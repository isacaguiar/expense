<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Notification;
use App\Models\SettlementConfirmation;
use App\Models\User;
use App\Services\CycleSummaryService;
use App\Support\BillingCycle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CycleSummaryService: o lado de **leitura** do cálculo de ciclo (entradas,
 * totais, saldos, liquidação par-a-par e checagem de quitação), extraído do
 * ExpenseController sem mudar regra. Ver
 * docs/feature/concluidas/202610/20261010-painel-financeiro-pessoal/plan.md §0.2.
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function service(): CycleSummaryService
    {
        return app(CycleSummaryService::class);
    }

    /**
     * Despesa À Vista de $creditor, dividida entre $creditor e os $debtors,
     * com a Quota (de agosto, salvo `$date`) marcada como paga ou não.
     *
     * @param  array<int, User>  $debtors
     */
    private function expenseBetween(Group $group, User $creditor, array $debtors, float $total, bool $paid = true, string $date = '2026-08-10'): Expense
    {
        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => $date,
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
            'date_expected' => $date,
            'number' => 1,
            'paid' => $paid,
            'value_quota' => $total,
        ]);

        return $expense;
    }

    private function confirm(Group $group, User $debtor, User $creditor, float $amount): SettlementConfirmation
    {
        return $this->confirmFor($group, $debtor, $creditor, $amount, $this->start->toDateString(), $this->end->toDateString());
    }

    private function confirmFor(Group $group, User $debtor, User $creditor, float $amount, string $cycleStart, string $cycleEnd): SettlementConfirmation
    {
        return SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => $cycleStart,
            'cycle_end' => $cycleEnd,
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

    /**
     * O aviso de fechamento do frontend se apoia neste invariante
     * (docs/bugfix/20261024-alerta-ciclo-fechado-apos-pagamento): `balances` é o
     * rateio líquido da competência e não reage a pagamento de despesa nem a
     * confirmação de acerto. Quem diz se ainda falta acertar é o `confirmedAt`
     * de cada par de `settlements`.
     */
    public function test_balances_do_not_change_when_an_expense_is_paid_or_a_settlement_is_confirmed(): void
    {
        [$group, $creditor, $debtor] = $this->groupWithPair();
        $expense = $this->expenseBetween($group, $creditor, [$debtor], 200, false);

        $before = $this->service()->compute($group, $this->start, $this->end);

        $expense->quotas()->update(['paid' => true]);
        $this->confirm($group, $debtor, $creditor, 100);

        $after = $this->service()->compute($group, $this->start, $this->end);

        $this->assertEquals($before['balances'], $after['balances']);
        $this->assertEquals(-100.0, collect($after['balances'])->pluck('balance', 'user_id')[$debtor->id]);

        $settlements = $this->service()->withConfirmations($group->id, $this->start, $after['settlements']);
        $this->assertCount(1, $settlements);
        $this->assertNotNull($settlements[0]['confirmedAt']);
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

    /**
     * Despesa À Vista só do credor (sem devedor, logo sem acerto) e não paga:
     * deixa o ciclo pendente por `totals.pending`, que é o que o `focus()` olha.
     */
    private function unpaidExpense(Group $group, User $creditor, string $datePayment): Expense
    {
        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => $datePayment,
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);
        $expense->payers()->attach($creditor->id);
        $expense->quotas()->create(['date_expected' => $datePayment, 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        return $expense;
    }

    /** @return array{0: Group, 1: User} */
    private function groupWithCreditor(): array
    {
        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach($creditor->id);

        return [$group, $creditor];
    }

    public function test_focus_returns_the_most_recent_unsettled_closed_cycle(): void
    {
        Carbon::setTestNow('2026-10-15'); // outubro é a competência vigente

        [$group, $creditor] = $this->groupWithCreditor();
        $this->unpaidExpense($group, $creditor, '2026-08-10'); // 2 ciclos atrás
        $this->unpaidExpense($group, $creditor, '2026-09-10'); // 1 ciclo atrás

        $this->assertSame(1, $this->service()->focus($group));
    }

    public function test_focus_counts_a_cycle_that_is_still_in_its_grace_window(): void
    {
        // O ciclo de janeiro fecha em 05/02; em 02/02 ainda está `open`, mas já
        // passou da fronteira, e com conta em aberto a Home deve abrir nele.
        Carbon::setTestNow('2026-02-02');

        [$group, $creditor] = $this->groupWithCreditor();
        $this->unpaidExpense($group, $creditor, '2026-01-10');

        $this->assertSame(1, $this->service()->focus($group));
    }

    public function test_focus_skips_a_sealed_cycle_even_with_unpaid_entries(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $creditor] = $this->groupWithCreditor();
        $this->unpaidExpense($group, $creditor, '2026-08-10');
        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'totals' => ['total' => 100, 'paid' => 100, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'settlements' => [],
            'settled_at' => now(),
        ]);

        $this->assertSame(0, $this->service()->focus($group));
    }

    public function test_focus_is_zero_when_nothing_is_pending(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group] = $this->groupWithCreditor();

        $this->assertSame(0, $this->service()->focus($group));
    }

    public function test_focus_does_not_treat_the_open_current_cycle_as_pending(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $creditor] = $this->groupWithCreditor();
        $this->unpaidExpense($group, $creditor, '2026-10-10'); // vigente, aberto

        // O resultado é 0 pelo fallback, não por pendência: o vigente aberto não conta.
        $this->assertSame(0, $this->service()->focus($group));
    }

    public function test_focus_reaches_exactly_twelve_cycles_back(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $creditor] = $this->groupWithCreditor();
        $this->unpaidExpense($group, $creditor, '2025-10-10'); // outubro/2025: 12 ciclos atrás

        $this->assertSame(12, $this->service()->focus($group));
    }

    public function test_focus_does_not_look_past_twelve_cycles_back(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $creditor] = $this->groupWithCreditor();
        $this->unpaidExpense($group, $creditor, '2025-09-10'); // setembro/2025: 13 ciclos atrás

        $this->assertSame(0, $this->service()->focus($group));
    }

    // --- descrição do pagamento (feature 20261021 da `dev`, portada na TASK-471) ---
    // `pay()`/`confirmSettlement()` gravam `payment_note`/`note`; o `summary` as expõe como
    // `paymentNote`/`confirmedNote`. Esse cálculo mora aqui desde a TASK-409, então o serviço
    // precisa entregá-las em cada forma de entrada, não só o `ExpenseControllerSummaryTest`.

    public function test_compute_carries_the_payment_note_of_every_kind_of_entry(): void
    {
        [$group, $creditor] = $this->groupWithCreditor();

        $cash = $this->typedExpense($group, $creditor, 'IN_CASH', '2026-08-10', [['2026-08-10', true]]);
        $cash->quotas()->first()->update(['payment_note' => 'nota da à vista']);

        $installments = $this->typedExpense($group, $creditor, 'IN_INSTALLMENTS', '2026-07-10', [
            ['2026-07-10', true], ['2026-08-10', true],
        ]);
        $installments->quotas()->where('number', 2)->first()->update(['payment_note' => 'nota da parcela 2']);

        // Fixa já congelada (materializada) em agosto, e outra só projetada, sem quota.
        $frozen = $this->typedExpense($group, $creditor, 'FIXED', '2026-06-10', [['2026-08-10', true]]);
        $frozen->quotas()->first()->update(['payment_note' => 'nota da fixa']);
        $projected = $this->typedExpense($group, $creditor, 'FIXED', '2026-05-10', []);

        $notes = collect($this->service()->compute($group, $this->start, $this->end)['expenses'])->pluck('paymentNote', 'id');

        $this->assertSame('nota da à vista', $notes[$cash->id]);
        $this->assertSame('nota da parcela 2', $notes[$installments->id]);
        $this->assertSame('nota da fixa', $notes[$frozen->id]);
        $this->assertArrayHasKey($projected->id, $notes->all());
        $this->assertNull($notes[$projected->id]);
    }

    public function test_a_payment_without_a_note_has_a_null_payment_note(): void
    {
        [$group, $creditor] = $this->groupWithCreditor();
        $expense = $this->typedExpense($group, $creditor, 'IN_CASH', '2026-08-10', [['2026-08-10', true]]);

        $expenses = $this->service()->compute($group, $this->start, $this->end)['expenses'];

        $this->assertSame($expense->id, $expenses[0]['id']);
        $this->assertArrayHasKey('paymentNote', $expenses[0]);
        $this->assertNull($expenses[0]['paymentNote']);
    }

    public function test_with_confirmations_carries_the_note_of_the_confirmation(): void
    {
        [$group, $creditor, $debtor] = $this->groupWithPair();
        $this->expenseBetween($group, $creditor, [$debtor], 200);
        $settlements = $this->service()->compute($group, $this->start, $this->end)['settlements'];

        $before = $this->service()->withConfirmations($group->id, $this->start, $settlements)[0];
        $this->assertArrayHasKey('confirmedNote', $before);
        $this->assertNull($before['confirmedNote']);

        $confirmation = $this->confirm($group, $debtor, $creditor, 100);
        $confirmation->update(['note' => 'Pix de manhã']);

        $after = $this->service()->withConfirmations($group->id, $this->start, $settlements)[0];

        $this->assertSame('Pix de manhã', $after['confirmedNote']);
        $this->assertNotNull($after['confirmedProofUrl']);
    }

    // --- focus(): o curto-circuito dos ciclos anteriores à primeira entrada -----
    // (TASK-427: não calcular ciclo que não pode ter entrada; o resultado não muda)

    /**
     * O laço original do `focus()`, sem nenhum curto-circuito: uma consulta de
     * snapshot e um `isFullySettled` por ciclo, de 0 a `FOCUS_LOOKBACK`. É a
     * referência contra a qual o `focus()` otimizado tem de dar sempre a mesma
     * resposta.
     */
    private function focusByFullScan(Group $group): int
    {
        for ($ago = 0; $ago <= CycleSummaryService::FOCUS_LOOKBACK; $ago++) {
            $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $ago);

            $snapshot = GroupCycleSnapshot::where('group_id', $group->id)
                ->where('cycle_start', $cycle['start']->toDateString())
                ->first();

            if ($snapshot && $snapshot->isSealed()) {
                continue;
            }

            $cycleIsClosed = $cycle['status'] === 'closed'
                || ($cycle['status'] === 'open' && $snapshot && $snapshot->isManuallyClosedAndActive());
            $inGrace = $cycle['status'] === 'open' && Carbon::now()->startOfDay()->gt($cycle['end']);

            if (! $cycleIsClosed && ! $inGrace) {
                continue;
            }

            if (! $this->service()->isFullySettled($group, $cycle['start'], $cycle['end'])) {
                return $ago;
            }
        }

        return 0;
    }

    /**
     * Despesa de `$creditor` só dele (sem devedor), de qualquer tipo, com as
     * quotas dadas como lista de `[data, paga]` numeradas de 1.
     *
     * @param  array<int, array{0: string, 1: bool}>  $quotas
     */
    private function typedExpense(Group $group, User $creditor, string $type, string $datePayment, array $quotas, bool $deleted = false): Expense
    {
        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => $datePayment,
            'description' => 'Despesa de teste',
            'expense_type' => $type,
            'installments' => $type === 'IN_INSTALLMENTS' ? count($quotas) : 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => $deleted,
        ]);
        $expense->payers()->attach($creditor->id);

        foreach (array_values($quotas) as $index => [$date, $paid]) {
            $expense->quotas()->create(['date_expected' => $date, 'number' => $index + 1, 'paid' => $paid, 'value_quota' => 100]);
        }

        return $expense;
    }

    public function test_focus_gives_the_same_answer_as_the_full_scan_in_every_shape_of_group(): void
    {
        Carbon::setTestNow('2026-10-15'); // outubro (0), setembro (1), agosto (2) … abril (6)

        // nome => [resposta esperada, construtor do grupo]
        $scenarios = [
            'grupo vazio' => [0, fn () => $this->groupWithCreditor()[0]],

            'despesa só em setembro' => [1, function () {
                [$group, $creditor] = $this->groupWithCreditor();
                $this->unpaidExpense($group, $creditor, '2026-09-10');

                return $group;
            }],

            'parcelada que começa meses antes' => [6, function () {
                [$group, $creditor] = $this->groupWithCreditor();
                $this->typedExpense($group, $creditor, 'IN_INSTALLMENTS', '2026-04-10', [
                    ['2026-04-10', false], ['2026-05-10', true], ['2026-06-10', true],
                    ['2026-07-10', true], ['2026-08-10', true], ['2026-09-10', true],
                ]);

                return $group;
            }],

            'parcela com vencimento anterior à data da despesa (retroativa)' => [4, function () {
                [$group, $creditor] = $this->groupWithCreditor();
                $this->typedExpense($group, $creditor, 'IN_INSTALLMENTS', '2026-09-10', [
                    ['2026-06-10', false], ['2026-07-10', true], ['2026-08-10', true], ['2026-09-10', true],
                ]);

                return $group;
            }],

            'despesa Fixa com ocorrências congeladas' => [5, function () {
                [$group, $creditor] = $this->groupWithCreditor();
                $this->typedExpense($group, $creditor, 'FIXED', '2026-05-10', [
                    ['2026-05-10', false], ['2026-06-10', true], ['2026-07-10', true],
                    ['2026-08-10', true], ['2026-09-10', true],
                ]);

                return $group;
            }],

            'ciclo selado no meio do caminho' => [2, function () {
                [$group, $creditor] = $this->groupWithCreditor();
                $this->unpaidExpense($group, $creditor, '2026-08-10');
                $this->unpaidExpense($group, $creditor, '2026-09-10');
                GroupCycleSnapshot::create([
                    'group_id' => $group->id,
                    'cycle_start' => '2026-09-01',
                    'cycle_end' => '2026-09-30',
                    'totals' => ['total' => 100, 'paid' => 100, 'pending' => 0],
                    'expenses' => [],
                    'balances' => [],
                    'settlements' => [],
                    'settled_at' => now(),
                ]);

                return $group;
            }],

            'despesa excluída não conta' => [2, function () {
                [$group, $creditor] = $this->groupWithCreditor();
                $this->typedExpense($group, $creditor, 'IN_CASH', '2026-03-10', [['2026-03-10', false]], deleted: true);
                $this->unpaidExpense($group, $creditor, '2026-08-10');

                return $group;
            }],

            'acerto sem confirmação num ciclo antigo' => [4, function () {
                [$group, $creditor, $debtor] = $this->groupWithPair();
                $this->expenseBetween($group, $creditor, [$debtor], 200, true, '2026-06-10');

                return $group;
            }],

            'despesa no último dia do ciclo' => [2, function () {
                [$group, $creditor] = $this->groupWithCreditor();
                $this->unpaidExpense($group, $creditor, '2026-08-31');

                return $group;
            }],

            'despesa no primeiro dia do ciclo' => [1, function () {
                [$group, $creditor] = $this->groupWithCreditor();
                $this->unpaidExpense($group, $creditor, '2026-09-01');

                return $group;
            }],

            'fechamento no dia 15' => [1, function () {
                $creditor = User::factory()->create();
                $group = Group::create(['name' => 'Grupo', 'closing_day' => 15]);
                $group->members()->attach($creditor->id);
                $this->unpaidExpense($group, $creditor, '2026-08-20'); // ciclo 16/08–15/09

                return $group;
            }],
        ];

        foreach ($scenarios as $name => [$expected, $build]) {
            $group = $build();

            $this->assertSame($expected, $this->focusByFullScan($group), "a referência de força bruta mudou em: {$name}");
            $this->assertSame($expected, $this->service()->focus($group), $name);
        }
    }

    public function test_focus_does_not_scan_cycles_for_a_group_with_nothing_to_scan(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$empty] = $this->groupWithCreditor();

        [$onlyDeleted, $creditor] = $this->groupWithCreditor();
        $this->typedExpense($onlyDeleted, $creditor, 'IN_CASH', '2026-03-10', [['2026-03-10', false]], deleted: true);

        foreach (['grupo vazio' => $empty, 'só despesa excluída' => $onlyDeleted] as $name => $group) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->service()->focus($group);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            // Sem nenhuma entrada possível não há ciclo a calcular: no máximo as
            // consultas que descobrem a primeira data (antes: 49 para 12 ciclos).
            $this->assertLessThanOrEqual(2, count($queries), "{$name} varreu ciclos: ".count($queries).' consultas');
        }
    }

    public function test_focus_reads_the_snapshots_of_all_the_scanned_cycles_in_a_single_query(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $creditor] = $this->groupWithCreditor();
        // Paga e sem devedor: todo ciclo de janeiro a setembro é varrido e nenhum é pendente.
        $this->typedExpense($group, $creditor, 'IN_CASH', '2026-01-10', [['2026-01-10', true]]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $focus = $this->service()->focus($group);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $snapshotQueries = array_filter($queries, fn (array $query) => str_contains($query['query'], 'ex_group_cycle_snapshots'));

        $this->assertSame(0, $focus);
        $this->assertCount(1, $snapshotQueries, 'uma consulta de snapshot por ciclo varrido');
    }

    // --- view(): a escolha de fonte de summary(), sem a selagem preguiçosa -------

    public function test_view_serves_the_snapshot_of_a_sealed_cycle(): void
    {
        Carbon::setTestNow('2026-10-15'); // setembro é 1 ciclo atrás e já fechou por data

        [$group, $creditor, $debtor] = $this->groupWithPair();
        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-09-01',
            'cycle_end' => '2026-09-30',
            // Valores que o cálculo ao vivo nunca produziria (não há despesa nenhuma):
            // se vierem, é porque a fonte foi mesmo o snapshot.
            'totals' => ['total' => 777.0, 'paid' => 777.0, 'pending' => 0.0],
            'expenses' => [],
            'balances' => [],
            'settlements' => [['from_user_id' => $debtor->id, 'to_user_id' => $creditor->id, 'amount' => 50.0]],
            'settled_at' => now(),
        ]);
        $this->confirmFor($group, $debtor, $creditor, 50, '2026-09-01', '2026-09-30');

        $view = $this->service()->view($group, 1);

        $this->assertTrue($view['sealed']);
        $this->assertSame('closed', $view['cycle']['status']);
        $this->assertTrue($view['cycle']['settled']);
        $this->assertFalse($view['cycle']['in_grace']);
        $this->assertSame('2026-10-05', $view['cycle']['closes_at']);
        $this->assertEquals(777.0, $view['summary']['totals']['total']);
        $this->assertNotNull($view['summary']['settlements'][0]['confirmedAt']);
        $this->assertNotNull($view['summary']['settlements'][0]['confirmedProofUrl']);
    }

    public function test_view_computes_live_a_closed_cycle_that_is_not_sealed(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $creditor, $debtor] = $this->groupWithPair();
        $this->expenseBetween($group, $creditor, [$debtor], 200, false, '2026-09-10');

        $view = $this->service()->view($group, 1);

        $this->assertFalse($view['sealed']);
        $this->assertSame('closed', $view['cycle']['status']);
        $this->assertFalse($view['cycle']['settled']);
        $this->assertFalse($view['cycle']['in_grace']);
        $this->assertSame('2026-09-01', $view['cycle']['start']);
        $this->assertSame('2026-09-30', $view['cycle']['end']);
        $this->assertSame('2026-10-05', $view['cycle']['closes_at']);
        $this->assertEquals(200.0, $view['summary']['totals']['pending']);
        $this->assertCount(1, $view['summary']['settlements']);
        $this->assertNull($view['summary']['settlements'][0]['confirmedAt']);
    }

    public function test_view_marks_an_active_manual_closing_of_the_current_cycle(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $creditor, $debtor] = $this->groupWithPair();
        $this->expenseBetween($group, $creditor, [$debtor], 200, false, '2026-10-10');
        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-10-01',
            'cycle_end' => '2026-10-31',
            'totals' => ['total' => 0, 'paid' => 0, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => now(),
        ]);

        $view = $this->service()->view($group, 0);

        $this->assertSame('closed_manually', $view['cycle']['status']);
        $this->assertFalse($view['cycle']['settled']);
        $this->assertFalse($view['sealed']);
        // Ao vivo, não o snapshot manual vazio: a despesa de outubro aparece.
        $this->assertCount(1, $view['summary']['expenses']);
    }

    public function test_view_treats_a_reopened_manual_closing_as_open(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group] = $this->groupWithPair();
        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-10-01',
            'cycle_end' => '2026-10-31',
            'totals' => ['total' => 0, 'paid' => 0, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => now()->subMinute(),
            'reopened_at' => now(),
        ]);

        $this->assertSame('open', $this->service()->view($group, 0)['cycle']['status']);
    }

    public function test_view_computes_live_the_open_current_cycle(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $creditor, $debtor] = $this->groupWithPair();
        $this->expenseBetween($group, $creditor, [$debtor], 200, false, '2026-10-10');

        $view = $this->service()->view($group, 0);

        $this->assertFalse($view['sealed']);
        $this->assertSame('open', $view['cycle']['status']);
        $this->assertFalse($view['cycle']['settled']);
        $this->assertFalse($view['cycle']['in_grace']);
        $this->assertSame('2026-11-05', $view['cycle']['closes_at']);
        $this->assertEquals(200.0, $view['summary']['totals']['total']);
    }

    public function test_view_flags_a_cycle_whose_boundary_passed_but_is_still_open_as_in_grace(): void
    {
        // O ciclo de janeiro tem fronteira em 31/01 e só fecha em 05/02.
        Carbon::setTestNow('2026-02-02');

        [$group] = $this->groupWithPair();

        $view = $this->service()->view($group, 1);

        $this->assertSame('open', $view['cycle']['status']);
        $this->assertTrue($view['cycle']['in_grace']);
        $this->assertSame('2026-01-31', $view['cycle']['end']);
        $this->assertSame('2026-02-05', $view['cycle']['closes_at']);
    }

    public function test_view_does_not_seal_a_settled_cycle_but_the_summary_endpoint_does(): void
    {
        Carbon::setTestNow('2026-10-15');

        // Setembro fechado por data, todo pago e com o único acerto confirmado,
        // mas sem snapshot: o caso "quitado e não selado" do plan.md §0.1.
        [$group, $creditor, $debtor] = $this->groupWithPair();
        $this->expenseBetween($group, $creditor, [$debtor], 200, true, '2026-09-10');
        $this->confirmFor($group, $debtor, $creditor, 100, '2026-09-01', '2026-09-30');

        $snapshotsBefore = GroupCycleSnapshot::count();
        $notificationsBefore = Notification::count();

        $view = $this->service()->view($group, 1);

        // view() lê e não grava: nem snapshot, nem notificação "ciclo quitado".
        $this->assertFalse($view['cycle']['settled']);
        $this->assertFalse($view['sealed']);
        $this->assertSame($snapshotsBefore, GroupCycleSnapshot::count());
        $this->assertSame($notificationsBefore, Notification::count());

        // Controle: a mesma situação lida por GET /summary sela o ciclo. É a
        // diferença deliberada entre as duas leituras.
        $this->withToken(auth('api')->login($creditor))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1")
            ->assertOk()
            ->assertJsonPath('cycle.settled', true);

        $this->assertSame($snapshotsBefore + 1, GroupCycleSnapshot::count());
    }

    public function test_view_matches_the_summary_endpoint_for_a_closed_pending_cycle(): void
    {
        Carbon::setTestNow('2026-10-15');

        $creditor = User::factory()->create();
        $debtorA = User::factory()->create();
        $debtorB = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach([$creditor->id, $debtorA->id, $debtorB->id]);

        $this->expenseBetween($group, $creditor, [$debtorA, $debtorB], 300, false, '2026-09-10');
        $this->confirmFor($group, $debtorA, $creditor, 100, '2026-09-01', '2026-09-30');

        $http = $this->withToken(auth('api')->login($creditor))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1")
            ->assertOk()
            ->json();

        $view = $this->service()->view($group, 1);

        foreach (['start', 'end', 'closes_at', 'status', 'settled'] as $key) {
            $this->assertSame($http['cycle'][$key], $view['cycle'][$key], "cycle.{$key}");
        }
        $this->assertEquals($http['totals'], $view['summary']['totals']);

        $pick = fn (array $settlements) => array_map(
            fn (array $s) => [$s['from_user_id'], $s['to_user_id'], $s['amount'], $s['confirmedAt']],
            $settlements
        );
        $this->assertEquals($pick($http['settlements']), $pick($view['summary']['settlements']));
        $this->assertCount(2, $view['summary']['settlements']);
    }
}
