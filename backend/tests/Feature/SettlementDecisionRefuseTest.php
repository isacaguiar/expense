<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Notification;
use App\Models\User;
use App\Services\CycleSealingService;
use App\Support\ProofStorage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\Concerns\BuildsThirdPartyProofScenarios;
use Tests\TestCase;

/**
 * `POST /groups/{id}/settlements/refuse` (feature 20261110-quem-paga-se-declara-e-credor-recusa, plan §4,
 * TASK-599): o credor do par recusa o comprovante que um responsável enviou. A recusa **não apaga nada**
 * (D4-B): marca `refused_at`, e o acerto volta a pendente até quem enviou reenviar.
 */
class SettlementDecisionRefuseTest extends TestCase
{
    use BuildsSettlementScenarios;
    use BuildsThirdPartyProofScenarios;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function refuse(User $caller, Group|int|string $group, array $body): TestResponse
    {
        $groupId = $group instanceof Group ? $group->id : $group;

        return $this->withToken($this->tokenFor($caller))->postJson("/api/groups/{$groupId}/settlements/refuse", $body);
    }

    private function accept(User $caller, Group $group, array $body): TestResponse
    {
        return $this->withToken($this->tokenFor($caller))->postJson("/api/groups/{$group->id}/settlements/accept", $body);
    }

    // --- o caminho feliz ---------------------------------------------------

    public function test_it_refuses_and_keeps_the_row_and_the_file(): void
    {
        Storage::fake(ProofStorage::DISK);
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario(paid: true);
        Storage::disk(ProofStorage::DISK)->put($proof->proof_path, 'conteudo do comprovante');

        $this->refuse($creditor, $group, $this->pairBody($debtor, $creditor))
            ->assertOk()
            ->assertJson(['message' => 'Comprovante recusado.', 'confirmationStatus' => 'refused']);

        $fresh = $proof->fresh();
        $this->assertNotNull($fresh, 'a linha continua (D4-B)');
        $this->assertNotNull($fresh->refused_at);
        $this->assertNull($fresh->accepted_at);
        $this->assertSame($proof->proof_path, $fresh->proof_path, 'o caminho do arquivo não muda');
        Storage::disk(ProofStorage::DISK)->assertExists($proof->proof_path);
    }

    public function test_a_refused_pair_is_unconfirmed_in_the_summary_and_never_seals(): void
    {
        [$group, $creditor, $debtor] = $this->thirdPartyScenario(paid: true);

        $this->refuse($creditor, $group, $this->pairBody($debtor, $creditor))->assertOk();

        $settlement = collect($this->withToken($this->tokenFor($creditor))->getJson("/api/groups/{$group->id}/expenses/summary")->assertOk()->json('settlements'))
            ->firstWhere('from_user_id', $debtor->id);
        $this->assertNull($settlement['confirmedAt'], 'volta a "a pagar"');
        $this->assertSame('refused', $settlement['confirmationStatus']);
        $this->assertNotNull($settlement['refusedAt']);
        $this->assertNull($this->sealedSnapshot($group));
        $this->assertFalse(app(CycleSealingService::class)->sealIfSettled($group, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')), 'nem tentando selar');
    }

    // --- autorização -------------------------------------------------------

    public function test_it_returns_403_when_to_user_id_is_not_the_caller_before_any_lookup(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof, $creator] = $this->thirdPartyScenario(paid: true);

        foreach ([$debtor, $responsible, $creator] as $caller) {
            $this->refuse($caller, $group, $this->pairBody($debtor, $creditor))->assertForbidden();
        }

        // Antes de qualquer consulta: um par que nem tem comprovante também dá 403, não 404.
        $this->refuse($responsible, $group, $this->pairBody($creator, $creditor))->assertForbidden();
        $this->assertNull($proof->fresh()->refused_at, 'nada foi escrito');
    }

    public function test_it_returns_404_for_a_non_member_before_the_403(): void
    {
        [$group, $creditor, $debtor] = $this->thirdPartyScenario(paid: true);

        $this->refuse(User::factory()->create(), $group, $this->pairBody($debtor, $creditor))->assertNotFound();
    }

    public function test_it_returns_404_for_a_missing_deleted_or_malformed_group_id(): void
    {
        [$group, $creditor, $debtor] = $this->thirdPartyScenario(paid: true);

        $this->refuse($creditor, 99999999, $this->pairBody($debtor, $creditor))->assertNotFound();
        $this->refuse($creditor, $group->id.'abc', $this->pairBody($debtor, $creditor))->assertNotFound();

        $group->update(['deleted' => true]);
        $this->refuse($creditor, $group, $this->pairBody($debtor, $creditor))->assertNotFound();
    }

    // --- o comprovante -----------------------------------------------------

    public function test_it_returns_404_when_there_is_no_confirmation_for_the_pair(): void
    {
        [$group, $creditor, , , , $creator] = $this->thirdPartyScenario(paid: true);

        $this->refuse($creditor, $group, $this->pairBody($creator, $creditor))->assertNotFound();
    }

    public function test_it_returns_422_for_a_debtor_proof(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario(paid: true);
        $proof->update(['confirmed_by' => null]);

        $this->refuse($creditor, $group, $this->pairBody($debtor, $creditor))
            ->assertStatus(422)
            ->assertJson(['error' => 'Só o comprovante enviado por quem paga por outra pessoa passa pelo aceite.']);

        $this->assertNull($proof->fresh()->refused_at);
    }

    public function test_it_returns_422_when_refusing_twice(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario(paid: true);
        $this->refuse($creditor, $group, $this->pairBody($debtor, $creditor))->assertOk();
        $refusedAt = $proof->fresh()->refused_at;

        $this->refuse($creditor, $group, $this->pairBody($debtor, $creditor))
            ->assertStatus(422)
            ->assertJson(['error' => 'Este comprovante já foi recusado.']);

        $this->assertEquals($refusedAt, $proof->fresh()->refused_at);
        $this->assertSame(2, Notification::where('type', 'settlement_refused')->count(), 'a segunda recusa não avisa de novo (um para quem enviou, um para o devedor)');
    }

    public function test_a_refused_proof_cannot_be_accepted(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario(paid: true);
        $this->refuse($creditor, $group, $this->pairBody($debtor, $creditor))->assertOk();

        $this->accept($creditor, $group, $this->pairBody($debtor, $creditor))
            ->assertStatus(422)
            ->assertJson(['error' => 'Este comprovante foi recusado: quem enviou precisa reenviar.']);

        $this->assertNull($proof->fresh()->accepted_at);
        $this->assertNull($this->sealedSnapshot($group));
    }

    public function test_an_accepted_proof_cannot_be_refused(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario(paid: false);
        $this->accept($creditor, $group, $this->pairBody($debtor, $creditor))->assertOk();

        $this->refuse($creditor, $group, $this->pairBody($debtor, $creditor))
            ->assertStatus(422)
            ->assertJson(['error' => 'Este comprovante já foi aceito.']);

        $this->assertNull($proof->fresh()->refused_at, 'aceitar é definitivo');
    }

    // --- o ciclo -----------------------------------------------------------

    public function test_it_returns_422_for_a_sealed_cycle(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario(paid: true);
        GroupCycleSnapshot::where('group_id', $group->id)->where('cycle_start', '2026-08-01')->update(['settled_at' => $this->proofSentAt()]);

        $this->refuse($creditor, $group, $this->pairBody($debtor, $creditor))
            ->assertStatus(422)
            ->assertJson(['error' => 'Esta competência já foi encerrada.']);

        $this->assertNull($proof->fresh()->refused_at, 'ciclo encerrado é imutável');
    }

    public function test_it_returns_422_when_the_cycle_is_not_closed_yet(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario(paid: true, closeCycle: false);

        $this->refuse($creditor, $group, $this->pairBody($debtor, $creditor))
            ->assertStatus(422)
            ->assertJson(['error' => 'O acerto só pode ser confirmado depois que a competência é fechada.']);

        $this->assertNull($proof->fresh()->refused_at);
    }

    public function test_cycles_ago_picks_a_previous_closed_cycle(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario(paid: true, closeCycle: false);
        Carbon::setTestNow('2026-09-20');

        $this->refuse($creditor, $group, $this->pairBody($debtor, $creditor, ['cycles_ago' => 1]))->assertOk();

        $this->assertNotNull($proof->fresh()->refused_at);
    }

    // --- validação ---------------------------------------------------------

    public function test_the_body_is_validated(): void
    {
        [$group, $creditor, $debtor] = $this->thirdPartyScenario(paid: true);

        $this->refuse($creditor, $group, ['to_user_id' => $creditor->id])->assertStatus(422)->assertJsonValidationErrors('from_user_id');
        $this->refuse($creditor, $group, ['from_user_id' => $debtor->id, 'to_user_id' => 99999999])->assertStatus(422)->assertJsonValidationErrors('to_user_id');
        $this->refuse($creditor, $group, $this->pairBody($debtor, $creditor, ['cycles_ago' => -1]))->assertStatus(422)->assertJsonValidationErrors('cycles_ago');
    }

    // --- os avisos ---------------------------------------------------------

    public function test_it_notifies_the_sender_and_the_debtor(): void
    {
        [$group, $creditor, $debtor, $responsible, , $creator] = $this->thirdPartyScenario(paid: false);

        $this->refuse($creditor, $group, $this->pairBody($debtor, $creditor))->assertOk();

        foreach ([$responsible, $debtor] as $recipient) {
            $notice = Notification::where('type', 'settlement_refused')->where('user_id', $recipient->id)->first();
            $this->assertNotNull($notice, 'avisa '.$recipient->name);
            $this->assertSame($group->id, (int) $notice->group_id);
            $this->assertSame($creditor->name, $notice->data['actorName']);
            $this->assertSame($debtor->name, $notice->data['debtorName'], 'de quem é o acerto');
            $this->assertSame('100.00', $notice->data['amount']);
            $this->assertSame('agosto/2026', $notice->data['cycleLabel']);
            $this->assertSame($group->id, $notice->data['groupId']);
            $this->assertSame('Casa', $notice->data['groupName']);
        }
        $this->assertSame(2, Notification::where('type', 'settlement_refused')->count(), 'ninguém mais: nem o credor, nem o criador');
        $this->assertSame(0, Notification::where('type', 'settlement_refused')->whereIn('user_id', [$creditor->id, $creator->id])->count());
    }

    public function test_the_creditor_who_sent_the_proof_himself_refuses_without_a_notice_to_himself(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario(paid: false);
        $proof->update(['confirmed_by' => $creditor->id]);

        $this->refuse($creditor, $group, $this->pairBody($debtor, $creditor))->assertOk();

        $this->assertSame(1, Notification::where('type', 'settlement_refused')->count(), 'só o devedor');
        $this->assertSame(1, Notification::where('type', 'settlement_refused')->where('user_id', $debtor->id)->count());
    }

    // --- consistência ------------------------------------------------------

    public function test_it_locks_the_confirmation_row_while_deciding(): void
    {
        [$group, $creditor, $debtor] = $this->thirdPartyScenario(paid: false);

        DB::enableQueryLog();
        $this->refuse($creditor, $group, $this->pairBody($debtor, $creditor))->assertOk();
        $locked = collect(DB::getQueryLog())->pluck('query')->filter(
            fn (string $sql) => str_contains($sql, 'ex_settlement_confirmations') && str_contains(strtolower($sql), 'for update')
        );

        $this->assertGreaterThanOrEqual(1, $locked->count(), 'a linha do comprovante é lida com `for update`');
    }
}
