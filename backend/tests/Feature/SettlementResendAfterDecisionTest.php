<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMemberResponsible;
use App\Models\Notification;
use App\Models\SettlementConfirmation;
use App\Models\User;
use App\Services\SettlementConfirmationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\Concerns\BuildsThirdPartyProofScenarios;
use Tests\TestCase;

/**
 * `POST /groups/{id}/settlements/confirm` depois da decisão do credor (feature
 * 20261110-quem-paga-se-declara-e-credor-recusa, TASK-601, specify R5, R8, F5, F6 e ajuste 8): o
 * comprovante **aceito** é definitivo (422, sem gravar nada); o reenvio de um recusado volta a "aguardando
 * aceite" e reavisa o credor; o devedor que reenvia por cima do comprovante de um responsável o assume.
 */
class SettlementResendAfterDecisionTest extends TestCase
{
    use BuildsSettlementScenarios;
    use BuildsThirdPartyProofScenarios;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * O cenário do comprovante de responsável (agosto/2026 fechado, R$ 100,00, ainda sem decisão), com o
     * vínculo "quem paga por quem" que a rota exige e o arquivo do comprovante no disco falso.
     *
     * @return array{0: Group, 1: User, 2: User, 3: User, 4: SettlementConfirmation, 5: User}
     */
    private function scenario(bool $paid = false): array
    {
        Storage::fake('local');

        $scenario = $this->thirdPartyScenario($paid);
        [$group, , $debtor, $responsible, $proof] = $scenario;

        GroupMemberResponsible::create([
            'group_id' => $group->id,
            'user_id' => $debtor->id,
            'responsible_user_id' => $responsible->id,
        ]);
        Storage::disk('local')->put($proof->proof_path, 'comprovante anterior');

        return $scenario;
    }

    /** `$caller` envia o comprovante do acerto ao credor; `$fromUserId` só vai quando é por outra pessoa. */
    private function confirm(User $caller, Group $group, User $creditor, ?int $fromUserId = null): TestResponse
    {
        $body = ['to_user_id' => $creditor->id, 'comprovante' => UploadedFile::fake()->image('comprovante.jpg')];

        if ($fromUserId !== null) {
            $body['from_user_id'] = $fromUserId;
        }

        return $this->withToken($this->tokenFor($caller))->post("/api/groups/{$group->id}/settlements/confirm", $body);
    }

    /** @return list<string> */
    private function proofFiles(Group $group): array
    {
        return Storage::disk('local')->allFiles("comprovantes/{$group->id}");
    }

    private function settlementNotices(): int
    {
        return Notification::where('type', 'settlement_confirmed')->count();
    }

    // --- F5, R8: o aceito é definitivo ------------------------------------

    public function test_resending_an_accepted_proof_returns_422_and_writes_nothing(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->scenario();
        $proof->update(['accepted_at' => '2026-08-20 11:00:00']);
        $before = $proof->fresh()->toArray();

        DB::enableQueryLog();
        foreach ([[$responsible, $debtor->id], [$debtor, null]] as [$caller, $for]) {
            $this->confirm($caller, $group, $creditor, $for)
                ->assertStatus(422)
                ->assertJson(['error' => 'Este comprovante já foi aceito.']);
        }
        $queries = collect(DB::getQueryLog())->pluck('query');

        // A checagem vem antes de gravar o arquivo: a rota nem chega ao caminho de escrita, que lê o par com
        // lock.
        $this->assertFalse($queries->contains(fn ($q) => str_contains($q, 'for update')), 'decidiu sem abrir o caminho de escrita');
        $this->assertSame($before, $proof->fresh()->toArray(), 'a linha não muda');
        $this->assertSame([$proof->proof_path], $this->proofFiles($group), 'o arquivo anterior segue e nenhum novo aparece');
        $this->assertSame(0, $this->settlementNotices());
    }

    public function test_an_accept_that_commits_between_the_check_and_the_write_wins(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->scenario();
        $accepted = false;

        // O credor aceita e commita depois da checagem do reenvio e antes de ele travar a linha: o
        // reenvio relê o par com lock, vê o aceite e não grava (F6).
        DB::connection()->beforeExecuting(function (string $query) use (&$accepted, $proof) {
            if ($accepted || ! str_contains($query, 'ex_settlement_confirmations') || ! str_contains($query, 'for update')) {
                return;
            }

            $accepted = true;
            DB::table('ex_settlement_confirmations')->where('id', $proof->id)->update(['accepted_at' => '2026-08-20 11:00:00']);
        });

        $this->confirm($responsible, $group, $creditor, $debtor->id)
            ->assertStatus(422)
            ->assertJson(['error' => 'Este comprovante já foi aceito.']);

        $this->assertTrue($accepted, 'o reenvio releu a linha com lock');
        $row = $proof->fresh();
        $this->assertSame(SettlementConfirmation::STATUS_ACCEPTED, $row->status());
        $this->assertSame([$proof->proof_path], $this->proofFiles($group), 'o arquivo novo não fica órfão');
        $this->assertSame(0, $this->settlementNotices());
    }

    public function test_an_acceptance_that_appears_after_the_first_read_never_survives_on_the_new_proof(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->scenario();
        $proof->delete();
        $reads = 0;
        $appeared = false;

        // Sem linha na leitura inicial, o primeiro envio não trava nada. Se outra linha já aceita aparece
        // antes da gravação (a janela de milissegundos), o comprovante novo não pode herdar o aceite: quem
        // aceitou viu outro arquivo.
        DB::connection()->beforeExecuting(function (string $query) use (&$reads, &$appeared, $group, $debtor, $creditor, $responsible) {
            if ($appeared || ! str_contains($query, 'from `ex_settlement_confirmations`') || ! str_contains($query, 'limit 1')) {
                return;
            }

            // A 1ª leitura é a checagem do controller; a 2ª, a do `updateOrCreate`, logo antes de gravar.
            if (++$reads < 2) {
                return;
            }

            $appeared = true;
            SettlementConfirmation::create([
                'group_id' => $group->id,
                'cycle_start' => '2026-08-01',
                'cycle_end' => '2026-08-31',
                'from_user_id' => $debtor->id,
                'to_user_id' => $creditor->id,
                'confirmed_by' => $responsible->id,
                'amount' => 100,
                'proof_path' => 'comprovantes/'.$group->id.'/aceito-por-outro-envio.jpg',
                'confirmed_at' => $this->proofSentAt(),
                'accepted_at' => '2026-08-20 11:00:00',
            ]);
        });

        $this->confirm($responsible, $group, $creditor, $debtor->id)->assertOk();

        $this->assertTrue($appeared, 'a linha aceita apareceu entre a leitura e a gravação');
        $row = SettlementConfirmation::where('group_id', $group->id)->sole();
        $this->assertNull($row->accepted_at, 'o aceite não passa para o comprovante novo');
        $this->assertSame(SettlementConfirmation::STATUS_AWAITING_ACCEPTANCE, $row->status());
        $this->assertStringNotContainsString('aceito-por-outro-envio', $row->proof_path);
    }

    public function test_a_failure_while_saving_leaves_no_orphan_file_and_keeps_the_previous_one(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->scenario();
        $this->mock(SettlementConfirmationService::class, function ($mock) {
            $mock->shouldReceive('submitProof')->once()->andThrow(new \RuntimeException('falha ao gravar'));
        });

        $this->confirm($responsible, $group, $creditor, $debtor->id)->assertStatus(500);

        $this->assertSame([$proof->proof_path], $this->proofFiles($group), 'o arquivo novo não fica órfão e o anterior segue');
        $this->assertSame(SettlementConfirmation::STATUS_AWAITING_ACCEPTANCE, $proof->fresh()->status());
        $this->assertSame(0, $this->settlementNotices());
    }

    // --- R5, ajuste 8: o reenvio depois da recusa --------------------------

    public function test_resending_after_a_refusal_clears_the_refusal_and_renotifies_the_creditor(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->scenario();
        $proof->update(['refused_at' => '2026-08-20 11:00:00']);
        $oldPath = $proof->proof_path;

        $this->confirm($responsible, $group, $creditor, $debtor->id)->assertOk();

        $row = $proof->fresh();
        $this->assertNull($row->refused_at);
        $this->assertNull($row->accepted_at);
        $this->assertSame(SettlementConfirmation::STATUS_AWAITING_ACCEPTANCE, $row->status());
        $this->assertSame($responsible->id, (int) $row->confirmed_by);
        $this->assertNotSame($oldPath, $row->proof_path);
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertSame([$row->proof_path], $this->proofFiles($group));
        $this->assertSame(1, SettlementConfirmation::where('group_id', $group->id)->count());

        $notices = Notification::where('type', 'settlement_confirmed')->get();
        $this->assertCount(1, $notices);
        $this->assertSame($creditor->id, $notices->first()->user_id);
        $this->assertSame($responsible->name, $notices->first()->data['paidByName']);
    }

    public function test_resending_an_unrefused_proof_does_not_renotify(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->scenario();

        // Aguardando aceite (o do responsável) e depois enviado (o do devedor): nenhum dos dois reenvios avisa.
        $this->confirm($responsible, $group, $creditor, $debtor->id)->assertOk();
        $this->confirm($debtor, $group, $creditor)->assertOk();

        $this->assertSame(0, $this->settlementNotices());
    }

    // --- Quem reenvia vence ------------------------------------------------

    public function test_the_debtor_resending_over_a_responsible_proof_takes_it_over(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->scenario(paid: true);
        $this->assertSame(SettlementConfirmation::STATUS_AWAITING_ACCEPTANCE, $proof->status());
        $this->assertNull($this->sealedSnapshot($group), 'o do responsável ainda não sela');

        $this->confirm($debtor, $group, $creditor)->assertOk();

        $row = $proof->fresh();
        $this->assertNull($row->confirmed_by, 'agora o comprovante é do devedor');
        $this->assertSame(SettlementConfirmation::STATUS_SENT, $row->status());
        $this->assertTrue(SettlementConfirmation::countsForSettlement()->whereKey($row->id)->exists());
        $this->assertNotNull($this->sealedSnapshot($group), 'o do devedor vale na hora e sela o ciclo');
    }

    public function test_the_debtor_resending_over_a_refused_proof_makes_it_count_and_renotifies(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->scenario(paid: true);
        $proof->update(['refused_at' => '2026-08-20 11:00:00']);

        $this->confirm($debtor, $group, $creditor)->assertOk();

        $row = $proof->fresh();
        $this->assertNull($row->refused_at);
        $this->assertNull($row->confirmed_by);
        $this->assertSame(SettlementConfirmation::STATUS_SENT, $row->status());
        $this->assertNotNull($this->sealedSnapshot($group));
        $this->assertSame(1, $this->settlementNotices(), 'depois de recusar, o credor é avisado do reenvio');
    }

    public function test_another_responsible_resending_becomes_the_new_sender(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->scenario();
        $other = User::factory()->create();
        $group->members()->attach($other->id);
        GroupMemberResponsible::create([
            'group_id' => $group->id,
            'user_id' => $debtor->id,
            'responsible_user_id' => $other->id,
        ]);

        $this->confirm($other, $group, $creditor, $debtor->id)->assertOk();

        $row = $proof->fresh();
        $this->assertSame($other->id, (int) $row->confirmed_by);
        $this->assertSame(SettlementConfirmation::STATUS_AWAITING_ACCEPTANCE, $row->status());
        $this->assertNotSame($responsible->id, (int) $row->confirmed_by);
    }

    // --- F6: o lock só onde há linha ---------------------------------------

    public function test_resending_reads_the_existing_proof_with_a_lock(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->scenario();

        DB::enableQueryLog();
        $this->confirm($responsible, $group, $creditor, $debtor->id)->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');

        $this->assertTrue(
            $queries->contains(fn ($q) => str_contains($q, 'ex_settlement_confirmations') && str_contains($q, 'for update')),
            'a linha do comprovante é lida com for update'
        );
    }

    public function test_the_first_send_takes_no_row_lock(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->scenario();
        $proof->delete();

        DB::enableQueryLog();
        $this->confirm($debtor, $group, $creditor)->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');

        $this->assertFalse(
            $queries->contains(fn ($q) => str_contains($q, 'ex_settlement_confirmations') && str_contains($q, 'for update')),
            'sem linha, não há o que travar (travar o vazio tomaria um gap lock e dois primeiros envios se travariam)'
        );
        $this->assertSame(1, SettlementConfirmation::where('group_id', $group->id)->count());
        $this->assertSame(1, $this->settlementNotices(), 'o primeiro envio avisa o credor como sempre');
    }
}
