<?php

namespace Tests\Feature;

use App\Models\Group;
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
 * Os **dois orçamentos de 10** do histórico do acerto (feature 20261114-historico-de-comprovantes-e-
 * justificativa, plan §2, ajuste 10, D13, TASK-639), a metade que fecha o achado A1 da revisão de segurança:
 * o orçamento de **quem envia** o comprovante (`replaced` e `withdrawn`, `isFull`) **não conta** os
 * complementos, e o de quem **complementa** (`complement`, `isComplementFull`) não conta os de quem envia. Quem
 * se declara pagador do devedor, então, não tranca o devedor que precisa corrigir o próprio comprovante.
 *
 * O lado do complemento (10 `replaced` ou `withdrawn` não o barram) é da TASK-638 e está em
 * `SettlementComplementTest`; aqui está o lado de quem envia e a prova de que os dois são independentes, de
 * ponta a ponta pelas rotas.
 */
class SettlementHistoryBudgetsTest extends TestCase
{
    use BuildsSettlementScenarios;
    use BuildsThirdPartyProofScenarios;
    use DatabaseTransactions;
    use FailsToWriteProofs;

    private const SENDER_LIMIT = 'O limite de comprovantes guardados deste acerto foi atingido.';

    private const COMPLEMENT_LIMIT = 'O limite de justificativas e comprovantes adicionados a este acerto foi atingido.';

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

    private function addEntries(Group $group, User $debtor, User $creditor, int $count, string $kind): void
    {
        for ($i = 0; $i < $count; $i++) {
            SettlementProofEntry::create([
                'group_id' => $group->id,
                'cycle_start' => '2026-08-01',
                'from_user_id' => $debtor->id,
                'to_user_id' => $creditor->id,
                'kind' => $kind,
                'sent_by' => $debtor->id,
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

    private function isFull(Group $group, User $debtor, User $creditor): bool
    {
        return app(SettlementProofHistoryService::class)->isFull($group->id, '2026-08-01', $debtor->id, $creditor->id);
    }

    // --- o orçamento de quem envia conta só `replaced` e `withdrawn` ---------------------------------------

    public function test_complements_do_not_fill_the_senders_budget(): void
    {
        [$group, $creditor, $debtor] = $this->ownProofScenario();

        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_COMPLEMENT);
        $this->assertFalse($this->isFull($group, $debtor, $creditor), '10 complementos não gastam nenhuma vaga de quem envia');

        $this->addEntries($group, $debtor, $creditor, 20, SettlementProofEntry::KIND_COMPLEMENT);
        $this->assertFalse($this->isFull($group, $debtor, $creditor), 'nem acima de 10');
    }

    public function test_replaced_and_withdrawn_entries_together_fill_the_senders_budget(): void
    {
        [$group, $creditor, $debtor] = $this->ownProofScenario();

        $this->addEntries($group, $debtor, $creditor, 5, SettlementProofEntry::KIND_REPLACED);
        $this->addEntries($group, $debtor, $creditor, 4, SettlementProofEntry::KIND_WITHDRAWN);
        $this->assertFalse($this->isFull($group, $debtor, $creditor), '5 substituídas e 4 retiradas, 9: cabe uma');

        $this->addEntries($group, $debtor, $creditor, 1, SettlementProofEntry::KIND_WITHDRAWN);
        $this->assertTrue($this->isFull($group, $debtor, $creditor), '5 e 5: encheu');
    }

    public function test_complements_never_help_fill_the_senders_budget(): void
    {
        [$group, $creditor, $debtor] = $this->ownProofScenario();

        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_COMPLEMENT);
        $this->addEntries($group, $debtor, $creditor, 9, SettlementProofEntry::KIND_REPLACED);
        $this->assertFalse($this->isFull($group, $debtor, $creditor), '9 de quem envia e 10 complementos: ainda cabe uma');

        $this->addEntries($group, $debtor, $creditor, 1, SettlementProofEntry::KIND_WITHDRAWN);
        $this->assertTrue($this->isFull($group, $debtor, $creditor), 'a 10ª de quem envia enche, com os complementos ou sem eles');
    }

    // --- com o orçamento dos complementos cheio, quem envia segue enviando ---------------------------------

    public function test_with_ten_complements_the_debtor_can_still_resend_their_own_proof(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->ownProofScenario();
        $oldPath = $proof->proof_path;
        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_COMPLEMENT);

        $this->confirmAs($debtor, $group, $creditor)->assertOk();

        $this->assertSame(11, $this->entries($group)->count());
        $replaced = $this->entries($group)->where('kind', SettlementProofEntry::KIND_REPLACED);
        $this->assertCount(1, $replaced, 'nasceu a entrada do comprovante que saiu');
        $this->assertSame($oldPath, $replaced->first()->proof_path);
        $this->assertNotSame($oldPath, $proof->fresh()->proof_path);
        Storage::disk('local')->assertExists($oldPath);
    }

    public function test_with_ten_complements_even_the_first_send_goes_through(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->ownProofScenario();
        $proof->delete();
        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_COMPLEMENT);

        $this->confirmAs($debtor, $group, $creditor)->assertOk();

        $this->assertSame(10, $this->entries($group)->count(), 'o primeiro envio não cria entrada');
        $this->assertSame(1, SettlementConfirmation::where('group_id', $group->id)->count());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function thirdPartyStates(): array
    {
        return [
            'aguardando o aceite' => [[]],
            'recusado, com o motivo' => [['refused_at' => '2026-08-21 09:00:00', 'refusal_reason' => 'foto cortada']],
        ];
    }

    /**
     * @dataProvider thirdPartyStates
     *
     * @param  array<string, mixed>  $state
     */
    public function test_with_ten_complements_the_responsible_can_still_resend_a_third_party_proof(array $state): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $proof->update($state);
        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_COMPLEMENT);

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();

        $this->assertSame(1, $this->entries($group)->where('kind', SettlementProofEntry::KIND_REPLACED)->count());
        $this->assertSame(11, $this->entries($group)->count());
    }

    // --- com o orçamento de quem envia cheio, enviar segue barrado e retirar segue valendo -----------------

    public function test_ten_withdrawn_entries_also_stop_a_resend_and_a_first_send_before_the_file_is_written(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_WITHDRAWN);
        // Se o controller gravasse o arquivo antes de checar o teto, o disco que falha daria 500 em vez de 422.
        $this->failProofWrites();
        Storage::disk('local')->put($proof->proof_path, 'comprovante anterior');

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)
            ->assertStatus(422)
            ->assertJson(['error' => self::SENDER_LIMIT]);

        $proof->delete();
        $this->confirmAs($responsible, $group, $creditor, $debtor->id)
            ->assertStatus(422)
            ->assertJson(['error' => self::SENDER_LIMIT]);

        $this->assertSame(10, $this->entries($group)->count());
        $this->assertSame([$proof->proof_path], $this->files($group), 'o disco não ganhou arquivo');
    }

    public function test_withdrawing_still_works_with_the_senders_budget_full_and_the_next_send_stays_closed(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_REPLACED);

        $this->withdrawAs($responsible, $group, $debtor, $creditor)->assertOk()->assertJson(['message' => 'Comprovante retirado.']);

        $this->assertNull($proof->fresh(), 'a linha atual saiu');
        $this->assertSame(11, $this->entries($group)->count(), 'retirar não tem teto: a entrada nasceu');
        $this->confirmAs($responsible, $group, $creditor, $debtor->id)
            ->assertStatus(422)
            ->assertJson(['error' => self::SENDER_LIMIT]);
        $this->confirmAs($debtor, $group, $creditor)
            ->assertStatus(422)
            ->assertJson(['error' => self::SENDER_LIMIT]);
        $this->assertSame(11, $this->entries($group)->count());
    }

    /**
     * O laço "enviar, retirar, enviar" fecha **na décima entrada**, e a retirada é uma delas: com 9 `replaced`,
     * a retirada é a 10ª e o envio seguinte já leva 422. Sem contar o `withdrawn`, o laço reabriria.
     */
    public function test_the_withdrawal_itself_is_the_tenth_entry_and_closes_the_send_withdraw_send_loop(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->linkedScenario();
        $this->addEntries($group, $debtor, $creditor, 9, SettlementProofEntry::KIND_REPLACED);

        $this->withdrawAs($responsible, $group, $debtor, $creditor)->assertOk();

        $this->assertTrue($this->isFull($group, $debtor, $creditor));
        $this->confirmAs($responsible, $group, $creditor, $debtor->id)
            ->assertStatus(422)
            ->assertJson(['error' => self::SENDER_LIMIT]);
    }

    public function test_the_service_counts_the_senders_budget_again_under_the_lock_ignoring_complements(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $this->addEntries($group, $debtor, $creditor, 10, SettlementProofEntry::KIND_COMPLEMENT);
        $key = [
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
        ];
        $values = [
            'cycle_end' => '2026-08-31',
            'amount' => 100,
            'proof_path' => "comprovantes/{$group->id}/novo.jpg",
            'note' => null,
            'confirmed_at' => Carbon::now(),
            'confirmed_by' => $responsible->id,
        ];

        $result = app(SettlementConfirmationService::class)->submitProof($key, $values, true);

        $this->assertNull($result['blockedBy'], '10 complementos não bloqueiam o reenvio no service');
        $this->assertNotNull($result['confirmation']);

        // Agora o orçamento de quem envia enche de verdade: o service barra o seguinte, sob a trava.
        $this->addEntries($group, $debtor, $creditor, 9, SettlementProofEntry::KIND_WITHDRAWN);
        $this->assertSame(10, $this->entries($group)->whereIn('kind', [SettlementProofEntry::KIND_REPLACED, SettlementProofEntry::KIND_WITHDRAWN])->count());

        $blocked = app(SettlementConfirmationService::class)->submitProof($key, $values, true);

        $this->assertNull($blocked['confirmation']);
        $this->assertSame(SettlementConfirmationService::BLOCKED_HISTORY_FULL, $blocked['blockedBy']);
    }

    // --- o achado A1 de ponta a ponta -----------------------------------------------------------------------

    /**
     * O cenário do A1 pelas rotas, do começo ao fim: quem paga pelo devedor gasta os **10 complementos**; o 11º
     * leva 422 com a mensagem **do complemento**; o devedor corrige o próprio comprovante **10 vezes** sem
     * esbarrar nesse limite e só na 11ª leva 422, com a mensagem **de quem envia**; e o 11º complemento segue
     * barrado, porque as 10 substituições não devolveram nenhuma vaga ao complemento. No fim, os dois
     * orçamentos estão cheios e o par guarda **21 arquivos** (as 20 entradas e o comprovante atual), o pior
     * caso que a D13 contou.
     *
     * Quem paga pelo devedor só complementa o comprovante que **o próprio devedor** enviou (R9); por isso o
     * comprovante é o dele (`sent`) e o credor não o recusa (a recusa é do comprovante de terceiro).
     */
    public function test_the_two_budgets_are_independent_from_end_to_end(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->ownProofScenario();

        for ($i = 1; $i <= 10; $i++) {
            $this->complementAs($responsible, $group, $debtor, $creditor)->assertCreated();
        }
        $this->complementAs($responsible, $group, $debtor, $creditor)
            ->assertStatus(422)
            ->assertJson(['error' => self::COMPLEMENT_LIMIT]);

        for ($i = 1; $i <= 10; $i++) {
            $this->confirmAs($debtor, $group, $creditor)->assertOk();
        }
        $this->confirmAs($debtor, $group, $creditor)
            ->assertStatus(422)
            ->assertJson(['error' => self::SENDER_LIMIT]);
        $this->complementAs($responsible, $group, $debtor, $creditor)
            ->assertStatus(422)
            ->assertJson(['error' => self::COMPLEMENT_LIMIT]);

        $entries = $this->entries($group);
        $this->assertSame(10, $entries->where('kind', SettlementProofEntry::KIND_COMPLEMENT)->count());
        $this->assertSame(10, $entries->where('kind', SettlementProofEntry::KIND_REPLACED)->count());
        $this->assertCount(21, $this->files($group), 'as 20 entradas e o comprovante atual: o pior caso da D13');
        $this->assertSame('sent', SettlementConfirmation::where('group_id', $group->id)->sole()->status());
    }

    /** O inverso: com o orçamento de quem envia cheio, o complemento ainda tem as 10 vagas dele. */
    public function test_a_full_senders_budget_leaves_all_ten_complements(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->ownProofScenario();

        for ($i = 1; $i <= 10; $i++) {
            $this->confirmAs($debtor, $group, $creditor)->assertOk();
        }
        $this->confirmAs($debtor, $group, $creditor)->assertStatus(422)->assertJson(['error' => self::SENDER_LIMIT]);

        for ($i = 1; $i <= 10; $i++) {
            $this->complementAs($responsible, $group, $debtor, $creditor)->assertCreated();
        }
        $this->complementAs($responsible, $group, $debtor, $creditor)
            ->assertStatus(422)
            ->assertJson(['error' => self::COMPLEMENT_LIMIT]);
    }
}
