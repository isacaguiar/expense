<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\GroupMemberResponsible;
use App\Models\SettlementConfirmation;
use App\Models\User;
use App\Services\CycleSummaryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\TestCase;

/**
 * O estado do comprovante no ciclo (feature 20261110-quem-paga-se-declara-e-credor-recusa, plan §3,
 * specify R5): o selo conta só o comprovante que vale (o do devedor e o de responsável aceito, nunca o
 * recusado), e `withConfirmations` expõe `confirmationStatus` e `refusedAt`, com o recusado saindo como
 * "não confirmado" (`confirmedAt`, `confirmedProofUrl` e `confirmedNote` nulos).
 */
class SettlementProofStatusInCycleTest extends TestCase
{
    use BuildsSettlementScenarios;
    use DatabaseTransactions;

    private const WHEN = '2026-08-20 10:00:00';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    private function cycles(): CycleSummaryService
    {
        return app(CycleSummaryService::class);
    }

    // --- o selo (summaryIsFullySettled) ------------------------------------

    /**
     * Despesa do credor dividida com o devedor, com a parcela de agosto **paga** (o ciclo só fica
     * quitado por falta de comprovante) e um responsável no grupo.
     *
     * @return array{0: Group, 1: User, 2: User, 3: User, 4: Expense}
     */
    private function paidScenario(): array
    {
        [$creditor, $debtor, $responsible] = User::factory()->count(3)->create()->all();
        $group = Group::create(['name' => 'Casa']);
        $group->members()->attach([$creditor->id, $debtor->id, $responsible->id]);

        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 200,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);
        $expense->payers()->sync([$creditor->id, $debtor->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => true, 'value_quota' => 200]);

        return [$group, $creditor, $debtor, $responsible, $expense];
    }

    /** Uma confirmação direto pelo model, sem passar pela rota. */
    private function proof(Group $group, User $debtor, User $creditor, array $overrides = []): SettlementConfirmation
    {
        return SettlementConfirmation::create(array_merge([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'amount' => 100,
            'proof_path' => 'comprovantes/'.$group->id.'/teste.jpg',
            'confirmed_at' => self::WHEN,
        ], $overrides));
    }

    private function isFullySettled(Group $group): bool
    {
        return $this->cycles()->isFullySettled($group, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));
    }

    public function test_a_debtor_proof_still_settles_the_pair(): void
    {
        [$group, $creditor, $debtor] = $this->paidScenario();
        $this->proof($group, $debtor, $creditor);

        $this->assertTrue($this->isFullySettled($group));
    }

    public function test_a_third_party_proof_awaiting_acceptance_does_not_settle_the_cycle(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->paidScenario();
        $this->proof($group, $debtor, $creditor, ['confirmed_by' => $responsible->id]);

        $this->assertFalse($this->isFullySettled($group));
    }

    public function test_an_accepted_third_party_proof_settles_the_cycle(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->paidScenario();
        $this->proof($group, $debtor, $creditor, ['confirmed_by' => $responsible->id, 'accepted_at' => self::WHEN]);

        $this->assertTrue($this->isFullySettled($group));
    }

    public function test_a_refused_proof_does_not_settle_the_cycle(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->paidScenario();
        $this->proof($group, $debtor, $creditor, ['confirmed_by' => $responsible->id, 'refused_at' => self::WHEN]);

        $this->assertFalse($this->isFullySettled($group));
    }

    public function test_focus_keeps_a_cycle_whose_last_proof_awaits_acceptance(): void
    {
        Carbon::setTestNow('2026-09-20');
        [$group, $creditor, $debtor, $responsible] = $this->paidScenario();
        $proof = $this->proof($group, $debtor, $creditor, ['confirmed_by' => $responsible->id]);

        $this->assertSame(1, $this->cycles()->focus($group), 'agosto continua pendente: é o foco (1 ciclo atrás)');

        $proof->update(['accepted_at' => self::WHEN]);

        $this->assertSame(0, $this->cycles()->focus($group), 'aceito, agosto está quitado e o foco volta ao ciclo vigente');
    }

    // --- ponta a ponta pela rota `confirm` ---------------------------------

    /**
     * O acerto do devedor ao credor (R$ 100,00) com agosto/2026 fechado e a parcela **paga**, e um
     * responsável vinculado ao devedor.
     *
     * @return array{0: Group, 1: User, 2: User, 3: User}
     */
    private function httpScenario(bool $paid = false, bool $closeCycle = true): array
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-08-19');

        [$creditor, $debtor, $responsible] = User::factory()->count(3)->create()->all();
        $group = Group::create(['name' => 'Casa']);
        $group->members()->attach([$creditor->id, $debtor->id, $responsible->id]);
        $expense = $this->makeSettlement($group, $creditor, $debtor, 200, $closeCycle);
        if ($paid) {
            $expense->quotas()->update(['paid' => true]);
        }
        GroupMemberResponsible::create([
            'group_id' => $group->id,
            'user_id' => $debtor->id,
            'responsible_user_id' => $responsible->id,
        ]);

        return [$group, $creditor, $debtor, $responsible];
    }

    private function confirm(User $caller, Group $group, User $creditor, ?int $fromUserId = null): TestResponse
    {
        $body = ['to_user_id' => $creditor->id, 'comprovante' => UploadedFile::fake()->image('comprovante.jpg')];

        if ($fromUserId !== null) {
            $body['from_user_id'] = $fromUserId;
        }

        return $this->withToken($this->tokenFor($caller))->post("/api/groups/{$group->id}/settlements/confirm", $body);
    }

    private function sealedSnapshot(Group $group): ?GroupCycleSnapshot
    {
        return GroupCycleSnapshot::where('group_id', $group->id)->where('cycle_start', '2026-08-01')->whereNotNull('settled_at')->first();
    }

    public function test_a_responsible_proof_as_the_last_one_does_not_seal_the_cycle(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->httpScenario(paid: true);

        $this->confirm($responsible, $group, $creditor, $debtor->id)->assertOk();

        $this->assertNull($this->sealedSnapshot($group), 'comprovante de responsável sem aceite não sela');
    }

    public function test_the_debtor_proof_as_the_last_one_still_seals_the_cycle(): void
    {
        [$group, $creditor, $debtor] = $this->httpScenario(paid: true);

        $this->confirm($debtor, $group, $creditor)->assertOk();

        $this->assertNotNull($this->sealedSnapshot($group), 'o do próprio devedor sela como sempre');
    }

    // --- o estado no resumo (withConfirmations) ----------------------------

    private function summaryOf(User $viewer, Group $group): TestResponse
    {
        return $this->withToken($this->tokenFor($viewer))->getJson("/api/groups/{$group->id}/expenses/summary");
    }

    private function settlementOf(TestResponse $response, User $debtor): array
    {
        return collect($response->json('settlements'))->firstWhere('from_user_id', $debtor->id);
    }

    /**
     * Cinco devedores do mesmo credor, um acerto cada, nos cinco estados.
     *
     * @return array{0: Group, 1: User, 2: array<string, User>, 3: User}
     */
    private function fiveStates(): array
    {
        [$group, $creditor, $first, $responsible] = $this->httpScenario();
        // O primeiro devedor (R$ 100,00) ficará "sent"; os demais entram com valores diferentes.
        $debtors = ['sent' => $first];
        foreach (['awaiting' => 400, 'accepted' => 300, 'refused' => 100, 'none' => 160] as $label => $total) {
            $debtor = User::factory()->create();
            $group->members()->attach($debtor->id);
            $this->makeSettlement($group, $creditor, $debtor, $total, false);
            $debtors[$label] = $debtor;
        }

        $this->proof($group, $debtors['sent'], $creditor, ['amount' => 100]);
        $this->proof($group, $debtors['awaiting'], $creditor, ['amount' => 200, 'confirmed_by' => $responsible->id]);
        $this->proof($group, $debtors['accepted'], $creditor, ['amount' => 150, 'confirmed_by' => $responsible->id, 'accepted_at' => self::WHEN]);
        $this->proof($group, $debtors['refused'], $creditor, ['amount' => 50, 'confirmed_by' => $responsible->id, 'refused_at' => self::WHEN, 'note' => 'foto errada']);

        return [$group, $creditor, $debtors, $responsible];
    }

    public function test_the_summary_exposes_the_confirmation_status_of_every_pair(): void
    {
        [$group, $creditor, $debtors] = $this->fiveStates();

        $response = $this->summaryOf($creditor, $group)->assertOk();

        $this->assertSame('sent', $this->settlementOf($response, $debtors['sent'])['confirmationStatus']);
        $this->assertSame('awaiting_acceptance', $this->settlementOf($response, $debtors['awaiting'])['confirmationStatus']);
        $this->assertSame('accepted', $this->settlementOf($response, $debtors['accepted'])['confirmationStatus']);
        $this->assertSame('refused', $this->settlementOf($response, $debtors['refused'])['confirmationStatus']);
        $this->assertNull($this->settlementOf($response, $debtors['none'])['confirmationStatus']);
    }

    public function test_a_refused_pair_has_no_proof_fields_but_keeps_who_sent_it(): void
    {
        [$group, $creditor, $debtors, $responsible] = $this->fiveStates();

        $refused = $this->settlementOf($this->summaryOf($creditor, $group)->assertOk(), $debtors['refused']);

        $this->assertNull($refused['confirmedAt'], 'recusado volta como não confirmado');
        $this->assertNull($refused['confirmedProofUrl']);
        $this->assertNull($refused['confirmedNote']);
        $this->assertNotNull($refused['refusedAt']);
        $this->assertSame(['id' => $responsible->id, 'name' => $responsible->name], $refused['confirmedBy']);
        // Os que valem seguem com o comprovante à mostra.
        $awaiting = $this->settlementOf($this->summaryOf($creditor, $group)->assertOk(), $debtors['awaiting']);
        $this->assertNotNull($awaiting['confirmedAt']);
        $this->assertNotNull($awaiting['confirmedProofUrl']);
        $this->assertNull($awaiting['refusedAt']);
    }

    public function test_a_refused_pair_is_listed_with_the_unconfirmed_ones(): void
    {
        [$group, $creditor, $debtors] = $this->fiveStates();

        $order = collect($this->summaryOf($creditor, $group)->assertOk()->json('settlements'))->pluck('from_user_id')->all();

        $this->assertSame([
            $debtors['none']->id, $debtors['refused']->id,       // sem comprovante e recusado: no topo, por valor
            $debtors['awaiting']->id, $debtors['accepted']->id, $debtors['sent']->id, // com comprovante, por valor
        ], $order);
    }

    public function test_a_pair_without_confirmation_has_the_new_keys_as_null(): void
    {
        [$group, $creditor, $debtor] = $this->httpScenario();

        $settlement = $this->settlementOf($this->summaryOf($creditor, $group)->assertOk(), $debtor);

        $this->assertArrayHasKey('confirmationStatus', $settlement);
        $this->assertArrayHasKey('refusedAt', $settlement);
        $this->assertNull($settlement['confirmationStatus']);
        $this->assertNull($settlement['refusedAt']);
    }

    public function test_close_and_reopen_carry_the_status_fields(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->httpScenario(closeCycle: false);
        $closed = $this->withToken($this->tokenFor($creditor))->postJson("/api/groups/{$group->id}/expenses/close")->assertOk();
        $this->assertNull($this->settlementOf($closed, $debtor)['confirmationStatus'], 'close: sem comprovante ainda');
        $this->proof($group, $debtor, $creditor, ['confirmed_by' => $responsible->id, 'refused_at' => self::WHEN]);

        $reopened = $this->withToken($this->tokenFor($creditor))->postJson("/api/groups/{$group->id}/expenses/reopen")->assertOk();
        $this->assertSame('refused', $this->settlementOf($reopened, $debtor)['confirmationStatus'], 'reopen');
        $this->assertNotNull($this->settlementOf($reopened, $debtor)['refusedAt']);

        $closedAgain = $this->withToken($this->tokenFor($creditor))->postJson("/api/groups/{$group->id}/expenses/close")->assertOk();
        $this->assertSame('refused', $this->settlementOf($closedAgain, $debtor)['confirmationStatus'], 'close');
    }

    public function test_a_snapshot_cycle_decorates_the_status_live(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->httpScenario(closeCycle: false);
        $this->withToken($this->tokenFor($creditor))->postJson("/api/groups/{$group->id}/expenses/close")->assertOk();
        $this->assertNull($this->settlementOf($this->summaryOf($creditor, $group)->assertOk(), $debtor)['confirmationStatus']);

        $this->proof($group, $debtor, $creditor, ['confirmed_by' => $responsible->id]);

        $this->assertSame(
            'awaiting_acceptance',
            $this->settlementOf($this->summaryOf($creditor, $group)->assertOk(), $debtor)['confirmationStatus'],
            'o snapshot não guarda a confirmação: o estado é lido ao vivo'
        );
    }
}
