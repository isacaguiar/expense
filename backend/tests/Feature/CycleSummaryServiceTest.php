<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\SettlementConfirmation;
use App\Models\User;
use App\Services\CycleSummaryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CycleSummaryService: o lado de **leitura** do cálculo de ciclo (entradas,
 * totais, saldos, liquidação par-a-par e checagem de quitação), extraído do
 * ExpenseController sem mudar regra. Ver
 * docs/feature/20261010-painel-financeiro-pessoal/plan.md §0.2.
 *
 * O comportamento em si já é coberto, via HTTP e via reflexão no controller,
 * por ExpenseControllerSummaryTest e ExpenseControllerCycleIsFullySettledTest;
 * aqui fica o contrato do serviço, que o Painel passa a usar diretamente.
 */
class CycleSummaryServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $start;

    private Carbon $end;

    protected function setUp(): void
    {
        parent::setUp();

        // closing_day nulo → mês calendário: competência de agosto/2026.
        $this->start = Carbon::parse('2026-08-01');
        $this->end = Carbon::parse('2026-08-31');
    }

    private function service(): CycleSummaryService
    {
        return app(CycleSummaryService::class);
    }

    /**
     * Despesa À Vista de $creditor, dividida entre $creditor e os $debtors,
     * com a Quota de agosto marcada como paga ou não.
     *
     * @param  array<int, User>  $debtors
     */
    private function expenseBetween(Group $group, User $creditor, array $debtors, float $total, bool $paid = true): Expense
    {
        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => $total,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);
        $expense->payers()->sync(array_merge([$creditor->id], array_map(fn (User $user) => $user->id, $debtors)));
        $expense->quotas()->create([
            'date_expected' => '2026-08-10',
            'number' => 1,
            'paid' => $paid,
            'value_quota' => $total,
        ]);

        return $expense;
    }

    private function confirm(Group $group, User $debtor, User $creditor, float $amount): SettlementConfirmation
    {
        return SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => $this->start->toDateString(),
            'cycle_end' => $this->end->toDateString(),
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'amount' => $amount,
            'proof_path' => 'comprovantes/'.$group->id.'/x.jpg',
            'confirmed_at' => now(),
        ]);
    }

    /** @return array{0: Group, 1: User, 2: User} */
    private function groupWithPair(): array
    {
        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        return [$group, $creditor, $debtor];
    }

    public function test_compute_returns_totals_balances_and_the_net_settlement(): void
    {
        [$group, $creditor, $debtor] = $this->groupWithPair();
        $this->expenseBetween($group, $creditor, [$debtor], 200);

        $summary = $this->service()->compute($group, $this->start, $this->end);

        $this->assertEquals(['total' => 200.0, 'paid' => 200.0, 'pending' => 0.0], $summary['totals']);
        $this->assertCount(1, $summary['expenses']);
        $this->assertEquals(
            [['from_user_id' => $debtor->id, 'to_user_id' => $creditor->id, 'amount' => 100.0]],
            $summary['settlements']
        );

        $balances = collect($summary['balances'])->pluck('balance', 'user_id');
        $this->assertEquals(100.0, $balances[$creditor->id]);
        $this->assertEquals(-100.0, $balances[$debtor->id]);
    }

    public function test_entries_lists_the_expense_that_counts_for_the_cycle(): void
    {
        [$group, $creditor, $debtor] = $this->groupWithPair();
        $expense = $this->expenseBetween($group, $creditor, [$debtor], 200, false);

        $entries = $this->service()->entries($group->id, $this->start, $this->end);

        $this->assertCount(1, $entries);
        $entry = $entries->first();
        $this->assertSame($expense->id, $entry['expense']->id);
        $this->assertSame(200.0, $entry['value']);
        $this->assertFalse($entry['paid']);
        $this->assertFalse($entry['bornPaid']);
    }

    public function test_entries_ignores_an_expense_from_another_cycle(): void
    {
        [$group, $creditor, $debtor] = $this->groupWithPair();
        $this->expenseBetween($group, $creditor, [$debtor], 200);

        $entries = $this->service()->entries($group->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertCount(0, $entries);
    }

    public function test_with_confirmations_marks_confirmed_pairs_and_lists_unconfirmed_first(): void
    {
        $creditor = User::factory()->create();
        $debtorA = User::factory()->create();
        $debtorB = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach([$creditor->id, $debtorA->id, $debtorB->id]);

        // 300 dividido em três: cada devedor deve 100 ao credor.
        $this->expenseBetween($group, $creditor, [$debtorA, $debtorB], 300);
        $this->confirm($group, $debtorA, $creditor, 100);

        $summary = $this->service()->compute($group, $this->start, $this->end);
        $settlements = $this->service()->withConfirmations($group->id, $this->start, $summary['settlements']);

        $this->assertCount(2, $settlements);

        // Pagamento em aberto fica no topo (mesma ordem de summary()).
        $this->assertSame($debtorB->id, $settlements[0]['from_user_id']);
        $this->assertNull($settlements[0]['confirmedAt']);
        $this->assertNull($settlements[0]['confirmedProofUrl']);

        $this->assertSame($debtorA->id, $settlements[1]['from_user_id']);
        $this->assertNotNull($settlements[1]['confirmedAt']);
        $this->assertNotNull($settlements[1]['confirmedProofUrl']);
    }

    public function test_summary_is_not_settled_while_something_is_pending(): void
    {
        $summary = ['totals' => ['total' => 200.0, 'paid' => 0.0, 'pending' => 200.0], 'settlements' => []];

        $this->assertFalse($this->service()->summaryIsFullySettled($summary, 1, $this->start));
    }

    public function test_summary_is_not_settled_for_a_cent_still_pending(): void
    {
        $summary = ['totals' => ['total' => 200.0, 'paid' => 199.99, 'pending' => 0.01], 'settlements' => []];

        $this->assertFalse($this->service()->summaryIsFullySettled($summary, 1, $this->start));
    }

    public function test_summary_is_not_settled_when_a_settlement_pair_has_no_confirmation(): void
    {
        [$group, $creditor, $debtor] = $this->groupWithPair();
        $this->expenseBetween($group, $creditor, [$debtor], 200);

        $summary = $this->service()->compute($group, $this->start, $this->end);

        $this->assertFalse($this->service()->summaryIsFullySettled($summary, $group->id, $this->start));
    }

    public function test_summary_is_settled_when_nothing_is_pending_and_every_pair_is_confirmed(): void
    {
        [$group, $creditor, $debtor] = $this->groupWithPair();
        $this->expenseBetween($group, $creditor, [$debtor], 200);
        $this->confirm($group, $debtor, $creditor, 100);

        $summary = $this->service()->compute($group, $this->start, $this->end);

        $this->assertTrue($this->service()->summaryIsFullySettled($summary, $group->id, $this->start));
    }

    public function test_summary_is_settled_when_there_is_nothing_pending_and_no_settlement(): void
    {
        $summary = ['totals' => ['total' => 0.0, 'paid' => 0.0, 'pending' => 0.0], 'settlements' => []];

        $this->assertTrue($this->service()->summaryIsFullySettled($summary, 1, $this->start));
    }

    public function test_is_fully_settled_agrees_with_the_summary_check(): void
    {
        [$group, $creditor, $debtor] = $this->groupWithPair();
        $this->expenseBetween($group, $creditor, [$debtor], 200);

        $this->assertFalse($this->service()->isFullySettled($group, $this->start, $this->end));

        $this->confirm($group, $debtor, $creditor, 100);

        $this->assertTrue($this->service()->isFullySettled($group, $this->start, $this->end));
    }
}
