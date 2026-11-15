<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMemberResponsible;
use App\Models\SettlementConfirmation;
use App\Models\SettlementProofEntry;
use App\Models\User;
use App\Services\SettlementConfirmationService;
use App\Services\SettlementProofHistoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\Concerns\BuildsThirdPartyProofScenarios;
use Tests\Concerns\FailsToWriteProofs;
use Tests\TestCase;

/**
 * Os **dois orçamentos de 10 por par e ciclo, divididos por quem age** (feature 20261114-historico-de-
 * comprovantes-e-justificativa, plan §2, ajuste 11, D14, TASK-639 e TASK-640), que fecham os achados A1 e A5 da
 * revisão de segurança: o orçamento do **devedor** (entradas em que ele agiu) e o de **quem paga por ele** (toda
 * entrada de quem não é o devedor: `replaced`, `withdrawn` e `complement`, de todos os pagadores somados).
 * Nenhum pagador gasta a vaga do devedor, e o devedor não gasta a dos pagadores; a retirada não é barrada, mas
 * conta no lado de quem a fez.
 */
class SettlementHistoryBudgetsTest extends TestCase
{
    use BuildsSettlementScenarios;
    use BuildsThirdPartyProofScenarios;
    use DatabaseTransactions;
    use FailsToWriteProofs;

    private const DEBTOR_LIMIT = 'O limite de comprovantes guardados deste acerto foi atingido.';

    private const PAYER_LIMIT = 'O limite de comprovantes e justificativas de quem paga por esta pessoa foi atingido.';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return list<string> */
    private function files(Group $group): array
    {
        return Storage::disk('local')->allFiles("comprovantes/{$group->id}");
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, SettlementProofEntry> */
    private function entries(Group $group)
    {
        return SettlementProofEntry::where('group_id', $group->id)->orderBy('id')->get();
    }

    /** `$actedBy` nulo grava `acted_by` nulo (conta apagada). */
    private function addEntries(Group $group, User $debtor, User $creditor, int $count, string $kind, ?int $actedBy): void
    {
        for ($i = 0; $i < $count; $i++) {
            SettlementProofEntry::create([
                'group_id' => $group->id,
                'cycle_start' => '2026-08-01',
                'from_user_id' => $debtor->id,
                'to_user_id' => $creditor->id,
                'kind' => $kind,
                'sent_by' => $actedBy ?? $debtor->id,
                'acted_by' => $actedBy,
                'status_at_exit' => $kind === SettlementProofEntry::KIND_COMPLEMENT ? null : 'sent',
                'amount' => 100,
                'proof_path' => "comprovantes/{$group->id}/{$kind}{$i}.jpg",
            ]);
        }
    }

    /**
     * Agosto/2026 com o comprovante **do próprio devedor** (`sent`) e o vínculo do responsável: o único estado em
     * que o complemento vale. A parcela não está paga, então o ciclo nunca sela no meio dos testes.
     *
     * @return array{0: Group, 1: User, 2: User, 3: User, 4: SettlementConfirmation}
     */
    private function ownProofScenario(): array
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $proof->update(['confirmed_by' => null, 'note' => 'pix de agosto']);

        return [$group, $creditor, $debtor, $responsible, $proof];
    }

    private function complementAs(User $caller, Group $group, User $debtor, User $creditor): TestResponse
    {
        return $this->withHeader('Accept', 'application/json')
            ->withToken($this->tokenFor($caller))
            ->post("/api/groups/{$group->id}/settlements/complement", [
                'from_user_id' => (string) $debtor->id,
                'to_user_id' => (string) $creditor->id,
                'comprovante' => UploadedFile::fake()->image('correcao.jpg'),
                'justificativa' => 'a foto anterior era de outro mês',
            ]);
    }

    private function withdrawAs(User $caller, Group $group, User $debtor, User $creditor): TestResponse
    {
        $query = http_build_query(['from_user_id' => $debtor->id, 'to_user_id' => $creditor->id]);

        return $this->withToken($this->tokenFor($caller))->deleteJson("/api/groups/{$group->id}/settlements/confirmation?{$query}");
    }

    private function isFull(Group $group, User $debtor, User $creditor, User $actor): bool
    {
        return app(SettlementProofHistoryService::class)->isFull($group->id, '2026-08-01', $debtor->id, $creditor->id, $actor->id);
    }

    // --- a contagem por quem age -------------------------------------------------------------------------

    public function test_the_debtors_budget_counts_only_what_the_debtor_did(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->ownProofScenario();
        $other = User::factory()->create();

        foreach ([SettlementProofEntry::KIND_REPLACED, SettlementProofEntry::KIND_WITHDRAWN, SettlementProofEntry::KIND_COMPLEMENT] as $kind) {
            $this->addEntries($group, $debtor, $creditor, 10, $kind, $responsible->id);
        }
        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_REPLACED, $other->id);
        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_REPLACED, null);
        $this->assertFalse($this->isFull($group, $debtor, $creditor, $debtor), '50 entradas de pagadores não gastam nenhuma vaga do devedor');

        $this->addEntries($group, $debtor, $creditor, 9, SettlementProofEntry::KIND_REPLACED, $debtor->id);
        $this->assertFalse($this->isFull($group, $debtor, $creditor, $debtor), '9 do devedor: cabe uma');
        $this->addEntries($group, $debtor, $creditor, 1, SettlementProofEntry::KIND_WITHDRAWN, $debtor->id);
        $this->assertTrue($this->isFull($group, $debtor, $creditor, $debtor), '10 do devedor, entre replaced e withdrawn: encheu');
    }

    public function test_the_payers_budget_adds_every_payer_and_every_kind_and_ignores_the_debtor(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->ownProofScenario();
        $other = User::factory()->create();

        $this->addEntries($group, $debtor, $creditor, 20, SettlementProofEntry::KIND_REPLACED, $debtor->id);
        $this->assertFalse($this->isFull($group, $debtor, $creditor, $responsible), 'o que o devedor fez não gasta a vaga dos pagadores');

        $this->addEntries($group, $debtor, $creditor, 3, SettlementProofEntry::KIND_REPLACED, $responsible->id);
        $this->addEntries($group, $debtor, $creditor, 3, SettlementProofEntry::KIND_COMPLEMENT, $other->id);
        $this->addEntries($group, $debtor, $creditor, 3, SettlementProofEntry::KIND_WITHDRAWN, $responsible->id);
        $this->assertFalse($this->isFull($group, $debtor, $creditor, $responsible), '9 de pagadores: cabe uma');

        $this->addEntries($group, $debtor, $creditor, 1, SettlementProofEntry::KIND_COMPLEMENT, $other->id);
        $this->assertTrue($this->isFull($group, $debtor, $creditor, $responsible), '10 de pagadores, de dois pagadores e três tipos: encheu');
        $this->assertTrue($this->isFull($group, $debtor, $creditor, $other), 'o orçamento é dos pagadores, não de um deles');
        $this->assertTrue($this->isFull($group, $debtor, $creditor, $debtor), 'o do devedor é contado à parte: as 20 dele o enchem sem tocar nas dos pagadores');
    }

    public function test_an_entry_of_a_deleted_account_counts_for_the_payers_and_never_for_the_debtor(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->ownProofScenario();

        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_REPLACED, null);

        $this->assertTrue($this->isFull($group, $debtor, $creditor, $responsible));
        $this->assertFalse($this->isFull($group, $debtor, $creditor, $debtor));
    }

    public function test_entries_of_other_pairs_cycles_and_groups_never_count(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->ownProofScenario();
        $other = User::factory()->create();
        $otherGroup = Group::create(['name' => 'Outro grupo']);

        $this->addEntries($group, $debtor, $other, 10, SettlementProofEntry::KIND_REPLACED, $responsible->id);
        $this->addEntries($otherGroup, $debtor, $creditor, 10, SettlementProofEntry::KIND_REPLACED, $responsible->id);
        for ($i = 0; $i < 10; $i++) {
            SettlementProofEntry::create([
                'group_id' => $group->id, 'cycle_start' => '2026-07-01', 'from_user_id' => $debtor->id, 'to_user_id' => $creditor->id,
                'kind' => SettlementProofEntry::KIND_REPLACED, 'sent_by' => $responsible->id, 'acted_by' => $responsible->id,
                'status_at_exit' => 'sent', 'amount' => 100, 'proof_path' => "comprovantes/{$group->id}/jul{$i}.jpg",
            ]);
        }

        $this->assertFalse($this->isFull($group, $debtor, $creditor, $responsible));
    }

    public function test_the_entry_records_who_acted_apart_from_who_sent_the_proof_that_left(): void
    {
        // O devedor assume o comprovante de um terceiro: `sent_by` é o terceiro, `acted_by` é o devedor.
        [$group, $creditor, $debtor, $responsible] = $this->linkedScenario();
        $this->confirmAs($debtor, $group, $creditor)->assertOk();
        $takeover = $this->entries($group)->sole();
        $this->assertSame($responsible->id, (int) $takeover->sent_by);
        $this->assertSame($debtor->id, (int) $takeover->acted_by);

        // Quem paga por ele retira o próprio comprovante enviado por ele mesmo? Não: o do devedor não sai por aqui;
        // o terceiro reenvia e retira o dele.
        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertStatus(422);
    }

    public function test_the_complement_records_its_author_as_who_acted(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->ownProofScenario();

        $this->complementAs($responsible, $group, $debtor, $creditor)->assertCreated();

        $entry = $this->entries($group)->sole();
        $this->assertSame($responsible->id, (int) $entry->acted_by);
        $this->assertSame($responsible->id, (int) $entry->sent_by);
    }

    public function test_withdrawing_records_the_one_who_withdrew(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->linkedScenario();
        $this->withdrawAs($debtor, $group, $debtor, $creditor)->assertOk();
        $this->assertSame($debtor->id, (int) $this->entries($group)->sole()->acted_by, 'o devedor retirou o comprovante de terceiro');

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();
        $this->withdrawAs($responsible, $group, $debtor, $creditor)->assertOk();
        $this->assertSame($responsible->id, (int) $this->entries($group)->last()->acted_by);
    }

    // --- o que o devedor faz com o orçamento dos pagadores cheio ----------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function payerKinds(): array
    {
        return [
            'complementos' => [SettlementProofEntry::KIND_COMPLEMENT],
            'substituições' => [SettlementProofEntry::KIND_REPLACED],
            'retiradas' => [SettlementProofEntry::KIND_WITHDRAWN],
        ];
    }

    /** @dataProvider payerKinds */
    public function test_with_the_payers_budget_full_the_debtor_can_still_resend_and_send(string $kind): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->ownProofScenario();
        $oldPath = $proof->proof_path;
        $this->addEntries($group, $debtor, $creditor, 10, $kind, $responsible->id);

        $this->confirmAs($debtor, $group, $creditor)->assertOk();

        $replaced = $this->entries($group)->where('acted_by', $debtor->id);
        $this->assertCount(1, $replaced);
        $this->assertSame($oldPath, $replaced->first()->proof_path);

        $proof->fresh()->delete();
        $this->confirmAs($debtor, $group, $creditor)->assertOk();
        $this->assertSame(11, $this->entries($group)->count(), 'o primeiro envio não cria entrada');
    }

    public function test_with_the_payers_budget_full_the_debtor_can_still_take_over_a_third_party_proof(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->linkedScenario();
        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_WITHDRAWN, $responsible->id);

        $this->confirmAs($debtor, $group, $creditor)->assertOk();

        $mine = $this->entries($group)->where('acted_by', $debtor->id)->sole();
        $this->assertSame($responsible->id, (int) $mine->sent_by);
        $this->assertSame(11, $this->entries($group)->count());
    }

    /**
     * O achado A5, o laço do terceiro: "retirar, enviar" até o 10º. O orçamento dos pagadores enche, o terceiro
     * leva 422 **antes de gravar o arquivo** e o devedor segue enviando o próprio comprovante.
     */
    public function test_a_payer_looping_send_and_withdraw_never_locks_the_debtor_out(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();

        for ($i = 1; $i <= 10; $i++) {
            $this->withdrawAs($responsible, $group, $debtor, $creditor)->assertOk();
            if ($i < 10) {
                $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();
            }
        }
        $this->assertSame(10, $this->entries($group)->where('acted_by', $responsible->id)->count());

        $filesBefore = $this->files($group);
        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertStatus(422)->assertJson(['error' => self::PAYER_LIMIT]);
        $this->assertSame($filesBefore, $this->files($group), 'o 422 veio antes de gravar o arquivo');

        $this->confirmAs($debtor, $group, $creditor)->assertOk();
        $this->confirmAs($debtor, $group, $creditor)->assertOk();
        $this->assertSame('sent', SettlementConfirmation::where('group_id', $group->id)->sole()->status());
    }

    /**
     * O laço de **dois cúmplices** (achado do revisor da TASK-640): o pagador envia (o primeiro envio não cria
     * entrada) e o devedor retira o arquivo dele. O arquivo é do pagador, então a retirada gasta a vaga **do
     * pagador**: no 10º ciclo o pagador leva 422 antes de gravar o arquivo e o disco para em no máximo 21.
     */
    public function test_a_payer_sending_and_the_debtor_withdrawing_cannot_loop_forever(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->linkedScenario();

        for ($i = 1; $i <= 10; $i++) {
            $this->withdrawAs($debtor, $group, $debtor, $creditor)->assertOk();
            if ($i < 10) {
                $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();
            }
        }

        $mine = $this->entries($group);
        $this->assertSame(10, $mine->where('acted_by', $debtor->id)->where('sent_by', $responsible->id)->count());
        $this->assertTrue($this->isFull($group, $debtor, $creditor, $responsible), 'a retirada do arquivo do pagador gastou a vaga do pagador');
        $this->assertFalse($this->isFull($group, $debtor, $creditor, $debtor), 'e o devedor segue com a vaga dele');

        $filesBefore = $this->files($group);
        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertStatus(422)->assertJson(['error' => self::PAYER_LIMIT]);
        $this->assertSame($filesBefore, $this->files($group));
        $this->confirmAs($debtor, $group, $creditor)->assertOk();
    }

    public function test_an_entry_belongs_to_the_debtor_only_when_the_debtor_acted_and_the_file_was_the_debtors(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->ownProofScenario();
        $entry = fn (?int $sentBy, ?int $actedBy) => SettlementProofEntry::create([
            'group_id' => $group->id, 'cycle_start' => '2026-08-01', 'from_user_id' => $debtor->id, 'to_user_id' => $creditor->id,
            'kind' => SettlementProofEntry::KIND_WITHDRAWN, 'sent_by' => $sentBy, 'acted_by' => $actedBy,
            'status_at_exit' => 'sent', 'amount' => 100, 'proof_path' => "comprovantes/{$group->id}/x".uniqid().'.jpg',
        ]);

        for ($i = 0; $i < 10; $i++) {
            $entry($responsible->id, $debtor->id); // o devedor agiu sobre o arquivo de um pagador
        }
        $this->assertFalse($this->isFull($group, $debtor, $creditor, $debtor), 'não é do devedor');
        $this->assertTrue($this->isFull($group, $debtor, $creditor, $responsible), 'é do lado dos pagadores');
    }

    public function test_a_file_of_a_deleted_account_is_never_the_debtors(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->ownProofScenario();
        for ($i = 0; $i < 10; $i++) {
            SettlementProofEntry::create([
                'group_id' => $group->id, 'cycle_start' => '2026-08-01', 'from_user_id' => $debtor->id, 'to_user_id' => $creditor->id,
                'kind' => SettlementProofEntry::KIND_WITHDRAWN, 'sent_by' => null, 'acted_by' => $debtor->id,
                'status_at_exit' => 'sent', 'amount' => 100, 'proof_path' => "comprovantes/{$group->id}/apagada{$i}.jpg",
            ]);
        }

        $this->assertFalse($this->isFull($group, $debtor, $creditor, $debtor));
        $this->assertTrue($this->isFull($group, $debtor, $creditor, $responsible));
    }

    public function test_the_sent_by_and_acted_by_columns_never_serialize(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->ownProofScenario();
        $this->addEntries($group, $debtor, $creditor, 1, SettlementProofEntry::KIND_COMPLEMENT, $responsible->id);

        $array = $this->entries($group)->sole()->toArray();

        $this->assertArrayNotHasKey('acted_by', $array);
        $this->assertArrayNotHasKey('sent_by', $array);
        $this->assertArrayNotHasKey('proof_path', $array);
    }
    // --- com o orçamento do devedor cheio ---------------------------------------------------------------

    public function test_ten_withdrawn_entries_of_the_debtor_stop_the_debtors_resend_and_first_send_before_the_file_is_written(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->ownProofScenario();
        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_WITHDRAWN, $debtor->id);
        // Se o controller gravasse o arquivo antes de checar o teto, o disco que falha daria 500 em vez de 422.
        $this->failProofWrites();
        Storage::disk('local')->put($proof->proof_path, 'comprovante anterior');

        $this->confirmAs($debtor, $group, $creditor)->assertStatus(422)->assertJson(['error' => self::DEBTOR_LIMIT]);

        $proof->delete();
        $this->confirmAs($debtor, $group, $creditor)->assertStatus(422)->assertJson(['error' => self::DEBTOR_LIMIT]);

        $this->assertSame(10, $this->entries($group)->count());
        $this->assertSame([$proof->proof_path], $this->files($group), 'o disco não ganhou arquivo');
    }

    public function test_the_debtors_full_budget_does_not_stop_the_complement(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->ownProofScenario();
        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_REPLACED, $debtor->id);

        $this->complementAs($responsible, $group, $debtor, $creditor)->assertCreated();
    }

    // --- a retirada: sem barreira, mas conta -------------------------------------------------------------

    public function test_withdrawing_still_works_with_the_payers_budget_full_and_the_next_send_stays_closed(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_REPLACED, $responsible->id);

        $this->withdrawAs($responsible, $group, $debtor, $creditor)->assertOk()->assertJson(['message' => 'Comprovante retirado.']);

        $this->assertNull($proof->fresh(), 'a linha atual saiu');
        $this->assertSame(11, $this->entries($group)->count(), 'retirar não tem teto: a entrada nasceu');
        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertStatus(422)->assertJson(['error' => self::PAYER_LIMIT]);
        $this->assertSame(11, $this->entries($group)->count());
    }

    /** A retirada é a 10ª entrada e já fecha o laço "enviar, retirar, enviar". */
    public function test_the_withdrawal_itself_is_the_tenth_entry_of_its_side(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->linkedScenario();
        $this->addEntries($group, $debtor, $creditor, 9, SettlementProofEntry::KIND_REPLACED, $responsible->id);

        $this->withdrawAs($responsible, $group, $debtor, $creditor)->assertOk();

        $this->assertTrue($this->isFull($group, $debtor, $creditor, $responsible));
        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertStatus(422)->assertJson(['error' => self::PAYER_LIMIT]);
    }

    // --- o service, com a linha travada ------------------------------------------------------------------

    public function test_the_service_checks_the_actors_budget_again_under_the_lock(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->linkedScenario();
        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_REPLACED, $responsible->id);
        $key = [
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
        ];
        $values = fn (?int $by) => [
            'cycle_end' => '2026-08-31',
            'amount' => 100,
            'proof_path' => "comprovantes/{$group->id}/novo.jpg",
            'note' => null,
            'confirmed_at' => Carbon::now(),
            'confirmed_by' => $by,
        ];

        $blocked = app(SettlementConfirmationService::class)->submitProof($key, $values($responsible->id), true);
        $this->assertNull($blocked['confirmation']);
        $this->assertSame(SettlementConfirmationService::BLOCKED_PAYER_FULL, $blocked['blockedBy']);

        $debtors = app(SettlementConfirmationService::class)->submitProof($key, $values(null), true);
        $this->assertNull($debtors['blockedBy'], 'o orçamento cheio é o dos pagadores, não o do devedor');
        $this->assertNotNull($debtors['confirmation']);
    }

    public function test_the_blocked_reason_names_whose_budget_is_full(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->ownProofScenario();
        $key = [
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
        ];
        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_REPLACED, $debtor->id);
        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_COMPLEMENT, $responsible->id);

        $this->assertSame(SettlementConfirmationService::BLOCKED_HISTORY_FULL, SettlementConfirmationService::historyBlockedBy($key, $debtor->id));
        $this->assertSame(SettlementConfirmationService::BLOCKED_PAYER_FULL, SettlementConfirmationService::historyBlockedBy($key, $responsible->id));
        $this->assertSame(self::DEBTOR_LIMIT, SettlementConfirmationService::blockedMessage(SettlementConfirmationService::BLOCKED_HISTORY_FULL));
        $this->assertSame(self::PAYER_LIMIT, SettlementConfirmationService::blockedMessage(SettlementConfirmationService::BLOCKED_PAYER_FULL));
    }

    // --- os achados A1 e A5 de ponta a ponta --------------------------------------------------------------

    /**
     * Quem paga pelo devedor gasta os **10 do lado dos pagadores** com complementos; o 11º leva 422 com a
     * mensagem **dos pagadores**; o devedor corrige o próprio comprovante **10 vezes** sem esbarrar nisso e só
     * na 11ª leva 422, com a mensagem **do devedor**; e o 11º complemento segue barrado. No fim os dois lados
     * estão cheios e o par guarda **21 arquivos** (as 20 entradas e o comprovante atual), o pior caso da D14.
     */
    public function test_the_two_budgets_are_independent_from_end_to_end(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->ownProofScenario();

        for ($i = 1; $i <= 10; $i++) {
            $this->complementAs($responsible, $group, $debtor, $creditor)->assertCreated();
        }
        $this->complementAs($responsible, $group, $debtor, $creditor)->assertStatus(422)->assertJson(['error' => self::PAYER_LIMIT]);

        for ($i = 1; $i <= 10; $i++) {
            $this->confirmAs($debtor, $group, $creditor)->assertOk();
        }
        $this->confirmAs($debtor, $group, $creditor)->assertStatus(422)->assertJson(['error' => self::DEBTOR_LIMIT]);
        $this->complementAs($responsible, $group, $debtor, $creditor)->assertStatus(422)->assertJson(['error' => self::PAYER_LIMIT]);

        $entries = $this->entries($group);
        $this->assertSame(10, $entries->where('kind', SettlementProofEntry::KIND_COMPLEMENT)->count());
        $this->assertSame(10, $entries->where('kind', SettlementProofEntry::KIND_REPLACED)->count());
        $this->assertCount(21, $this->files($group), 'as 20 entradas e o comprovante atual: o pior caso da D14');
        $this->assertSame('sent', SettlementConfirmation::where('group_id', $group->id)->sole()->status());
    }

    /** O inverso: com o lado do devedor cheio, os pagadores ainda têm as 10 vagas deles. */
    public function test_a_full_debtors_budget_leaves_all_ten_payer_entries(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->ownProofScenario();

        for ($i = 1; $i <= 10; $i++) {
            $this->confirmAs($debtor, $group, $creditor)->assertOk();
        }
        $this->confirmAs($debtor, $group, $creditor)->assertStatus(422)->assertJson(['error' => self::DEBTOR_LIMIT]);

        for ($i = 1; $i <= 10; $i++) {
            $this->complementAs($responsible, $group, $debtor, $creditor)->assertCreated();
        }
        $this->complementAs($responsible, $group, $debtor, $creditor)->assertStatus(422)->assertJson(['error' => self::PAYER_LIMIT]);
    }

    /** RK10, aceito (D14): os pagadores dividem um orçamento só; um deles o esgota para o outro. */
    public function test_one_payer_can_use_up_the_budget_of_the_other_payers(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->ownProofScenario();
        $other = User::factory()->create();
        $group->members()->attach($other->id);
        GroupMemberResponsible::create(['group_id' => $group->id, 'user_id' => $debtor->id, 'responsible_user_id' => $other->id]);

        for ($i = 1; $i <= 10; $i++) {
            $this->complementAs($responsible, $group, $debtor, $creditor)->assertCreated();
        }

        $this->complementAs($other, $group, $debtor, $creditor)->assertStatus(422)->assertJson(['error' => self::PAYER_LIMIT]);
        $this->confirmAs($debtor, $group, $creditor)->assertOk();
    }
}
