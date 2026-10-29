<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\Quota;
use App\Models\SettlementConfirmation;
use App\Models\User;
use App\Services\CycleSummaryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsPurchaseScenarios;
use Tests\TestCase;

/**
 * Rede de segurança da feature 20261027-pagamento-no-cadastro-despesa
 * (specify.md §2.13, §3.7 e NR-01 a NR-10): ela muda um DADO (`paid` e seus
 * campos), não uma fórmula. Por isso o teste é de equivalência de estado: a
 * compra marcada como paga tem que dar as mesmas saídas que a compra pendente
 * onde a conta ignora `paid`, e mudar só o que depende de `paid`.
 *
 * Estes testes descrevem o código ATUAL e nascem verdes; depois da feature, os
 * mesmos cenários rodam também pelo `POST /api/expenses` (TASK-530).
 */
class PurchasePaymentNonRegressionTest extends TestCase
{
    use BuildsPurchaseScenarios;
    use DatabaseTransactions;

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
     * Formas de grupo do specify §3.7. Colunas: agora, membros, o credor
     * participa da divisão, tipo, total, início, parcelas, ciclos a conferir.
     *
     * @return array<string, array{0: string, 1: int, 2: bool, 3: string, 4: float, 5: string, 6: int, 7: int}>
     */
    public static function shapes(): array
    {
        return [
            'à vista, credor participa, 3 membros' => ['2026-08-19', 3, true, 'IN_CASH', 100.0, '2026-08-10', 1, 1],
            'à vista, credor não participa, 3 membros' => ['2026-08-19', 3, false, 'IN_CASH', 90.0, '2026-08-10', 1, 1],
            'à vista, 2 membros' => ['2026-08-19', 2, true, 'IN_CASH', 150.0, '2026-08-10', 1, 1],
            'parcelada 2x' => ['2026-08-19', 3, true, 'IN_INSTALLMENTS', 200.0, '2026-08-10', 2, 2],
            'parcelada 3x com resto de centavo' => ['2026-08-19', 3, true, 'IN_INSTALLMENTS', 100.0, '2026-08-10', 3, 3],
            'parcelada 12x com resto de centavo, 2 membros' => ['2026-08-19', 2, true, 'IN_INSTALLMENTS', 1000.0, '2026-08-10', 12, 12],
            'parcelada, credor não participa' => ['2026-08-19', 3, false, 'IN_INSTALLMENTS', 300.0, '2026-08-10', 3, 3],
            'fixa, mês inicial e seguinte' => ['2026-08-19', 3, true, 'FIXED', 90.0, '2026-08-10', 1, 2],
            'parcelada retroativa com parcelas born_paid' => ['2026-10-19', 3, true, 'IN_INSTALLMENTS', 400.0, '2026-08-10', 4, 4],
        ];
    }

    /**
     * NR-01. `balances`, `settlements`, `valuePerPerson`, `value` e `totals.total`
     * não leem `paid` (`CycleSummaryService.php:164-314`): ficam idênticos.
     *
     * @dataProvider shapes
     */
    public function test_compute_balances_settlements_and_value_per_person_are_identical(
        string $now,
        int $members,
        bool $creditorParticipates,
        string $type,
        float $total,
        string $start,
        int $n,
        int $cycleCount
    ): void {
        Carbon::setTestNow($now);

        ['group' => $group, 'users' => $users] = $this->makeGroup($members);
        $creditor = $users[0];
        $participants = $creditorParticipates ? $users : array_slice($users, 1);
        $expense = $this->makePurchase($group, $creditor, $participants, $type, $total, $start, $n);
        $cycles = $this->cyclesFrom($group, $start, $cycleCount);

        $before = array_map(fn (array $cycle) => $this->service()->compute($group, $cycle['start'], $cycle['end']), $cycles);

        $this->markPaid($expense);

        $after = array_map(fn (array $cycle) => $this->service()->compute($group, $cycle['start'], $cycle['end']), $cycles);

        foreach ($cycles as $i => $cycle) {
            $label = "ciclo {$cycle['start']->toDateString()}";

            $this->assertEquals($before[$i]['balances'], $after[$i]['balances'], "balances mudaram no {$label}");
            $this->assertEquals($before[$i]['settlements'], $after[$i]['settlements'], "settlements mudaram no {$label}");
            $this->assertEquals($before[$i]['totals']['total'], $after[$i]['totals']['total'], "totals.total mudou no {$label}");
            $this->assertSame(array_column($before[$i]['expenses'], 'value'), array_column($after[$i]['expenses'], 'value'), "value mudou no {$label}");
            $this->assertSame(array_column($before[$i]['expenses'], 'valuePerPerson'), array_column($after[$i]['expenses'], 'valuePerPerson'), "valuePerPerson mudou no {$label}");
        }
    }

    /**
     * NR-02. Só `totals.paid` e `totals.pending` dependem de `paid`, e mudam pelo
     * valor das quotas que existem no ciclo: o oráculo vem da tabela de quotas,
     * não do próprio `compute()`. A Fixa projetada do mês seguinte não tem linha
     * e continua pendente.
     *
     * @dataProvider shapes
     */
    public function test_totals_paid_and_pending_differ_only_by_the_paid_quotas_of_each_cycle(
        string $now,
        int $members,
        bool $creditorParticipates,
        string $type,
        float $total,
        string $start,
        int $n,
        int $cycleCount
    ): void {
        Carbon::setTestNow($now);

        ['group' => $group, 'users' => $users] = $this->makeGroup($members);
        $creditor = $users[0];
        $participants = $creditorParticipates ? $users : array_slice($users, 1);
        $expense = $this->makePurchase($group, $creditor, $participants, $type, $total, $start, $n);
        $cycles = $this->cyclesFrom($group, $start, $cycleCount);

        $sumQuotas = function (array $cycle, bool $onlyBornPaid) use ($expense): float {
            $query = Quota::where('expense_id', $expense->id)
                ->whereBetween('date_expected', [$cycle['start']->toDateString(), $cycle['end']->toDateString()]);

            if ($onlyBornPaid) {
                $query->where('born_paid', true);
            }

            return round((float) $query->sum('value_quota'), 2);
        };

        $before = array_map(fn (array $cycle) => $this->service()->compute($group, $cycle['start'], $cycle['end']), $cycles);

        $this->markPaid($expense);

        $after = array_map(fn (array $cycle) => $this->service()->compute($group, $cycle['start'], $cycle['end']), $cycles);

        foreach ($cycles as $i => $cycle) {
            $label = "ciclo {$cycle['start']->toDateString()}";

            $this->assertEqualsWithDelta($sumQuotas($cycle, true), $before[$i]['totals']['paid'], 0.001, "paid antes, {$label}");
            $this->assertEqualsWithDelta($sumQuotas($cycle, false), $after[$i]['totals']['paid'], 0.001, "paid depois, {$label}");

            foreach ([$before[$i], $after[$i]] as $summary) {
                $this->assertEqualsWithDelta(
                    $summary['totals']['total'],
                    $summary['totals']['paid'] + $summary['totals']['pending'],
                    0.001,
                    "paid + pending deveria dar total no {$label}"
                );
            }
        }
    }

    /**
     * NR-02, a soma. Cada quota entra no ciclo da própria `date_expected`: a soma
     * de `totals.paid` ao longo de todos os ciclos fecha no `total_value` ao
     * centavo (sem contar a compra duas vezes), e no mês do cadastro `paid` é só a
     * quota daquele mês, não a compra inteira.
     *
     * @return array<string, array{0: float, 1: int, 2: int}>
     */
    public static function installmentPurchases(): array
    {
        return [
            '3x de 100,00 (33,33 / 33,33 / 33,34)' => [100.0, 3, 3],
            '12x de 1000,00 (83,33 x 11 e 83,37)' => [1000.0, 12, 2],
        ];
    }

    /**
     * @dataProvider installmentPurchases
     */
    public function test_installment_paid_totals_sum_to_total_value_across_all_cycles(float $total, int $n, int $members): void
    {
        Carbon::setTestNow('2026-08-19');

        ['group' => $group, 'users' => $users] = $this->makeGroup($members);
        $expense = $this->makePurchase($group, $users[0], $users, 'IN_INSTALLMENTS', $total, '2026-08-10', $n);
        $cycles = $this->cyclesFrom($group, '2026-08-10', $n);

        $this->markPaid($expense);

        $quotaValues = $expense->quotas()->orderBy('number')->pluck('value_quota')->map(fn ($value) => (float) $value)->all();
        $paidPerCycle = [];

        foreach ($cycles as $i => $cycle) {
            // Cada ciclo é lido num "agora" dentro dele: o resultado não depende da data.
            Carbon::setTestNow($cycle['start']->copy()->addDays(5));

            $summary = $this->service()->compute($group, $cycle['start'], $cycle['end']);
            $paidPerCycle[] = $summary['totals']['paid'];

            $this->assertEqualsWithDelta($quotaValues[$i], $summary['totals']['paid'], 0.001, "paid do ciclo {$cycle['start']->toDateString()} deveria ser só a parcela ".($i + 1));
            $this->assertEqualsWithDelta(0.0, $summary['totals']['pending'], 0.001, "pending do ciclo {$cycle['start']->toDateString()}");
        }

        $this->assertEqualsWithDelta($total, array_sum($paidPerCycle), 0.001, 'a soma de totals.paid nos ciclos deve fechar no total_value');
        $this->assertEqualsWithDelta($total, array_sum($quotaValues), 0.001, 'as parcelas somam o total_value');
    }

    /**
     * NR-01, dívidas em sentido contrário: o netting do par continua o mesmo
     * quando só uma das duas compras é marcada como paga.
     */
    public function test_opposite_debts_net_the_same_when_one_purchase_is_marked_paid(): void
    {
        Carbon::setTestNow('2026-08-19');

        ['group' => $group, 'users' => $users] = $this->makeGroup(2);
        [$ana, $bruno] = $users;

        $paidByAna = $this->makePurchase($group, $ana, [$ana, $bruno], 'IN_CASH', 100.0, '2026-08-10');
        $this->makePurchase($group, $bruno, [$ana, $bruno], 'IN_CASH', 60.0, '2026-08-12');
        [$cycle] = $this->cyclesFrom($group, '2026-08-10', 1);

        $before = $this->service()->compute($group, $cycle['start'], $cycle['end']);

        $this->markPaid($paidByAna);

        $after = $this->service()->compute($group, $cycle['start'], $cycle['end']);

        // Ana adiantou 100 e Bruno 60: cada um deve metade ao outro, e o par líquido é Bruno -> Ana, 20,00.
        $this->assertEquals([['from_user_id' => $bruno->id, 'to_user_id' => $ana->id, 'amount' => 20.0]], $before['settlements']);
        $this->assertEquals($before['settlements'], $after['settlements']);
        $this->assertEquals($before['balances'], $after['balances']);
        // Só `totals.pending` reage: 160,00 pendentes antes; pagas as 100,00 da compra da Ana, sobram 60,00.
        $this->assertEqualsWithDelta(160.0, $before['totals']['pending'], 0.001);
        $this->assertEqualsWithDelta(60.0, $after['totals']['pending'], 0.001);
    }

    /**
     * Corpo JSON de um GET autenticado como `$user`.
     *
     * @return array<string, mixed>
     */
    private function fetch(string $token, string $uri): array
    {
        return $this->withToken($token)->getJson($uri)->assertOk()->json();
    }

    /**
     * Grupo de 3 membros com um ciclo selado (agosto), um fechado e pendente de
     * acerto (setembro) e o vigente (outubro, com uma Parcelada), tudo pago, com
     * comprovante e descrição. "Agora" é 2026-10-19.
     *
     * @return array{group: Group, users: list<User>, expenses: list<\App\Models\Expense>}
     */
    private function reportingScenario(): array
    {
        Carbon::setTestNow('2026-10-19 12:00:00');

        ['group' => $group, 'users' => $users] = $this->makeGroup(3);
        [$creditor, $debtorA, $debtorB] = $users;

        $august = $this->makePurchase($group, $creditor, $users, 'IN_CASH', 120.0, '2026-08-10');
        $september = $this->makePurchase($group, $creditor, $users, 'IN_CASH', 90.0, '2026-09-10');
        $october = $this->makePurchase($group, $creditor, $users, 'IN_INSTALLMENTS', 300.0, '2026-10-05', 3);

        foreach ([$august, $september, $october] as $expense) {
            $this->markPaid($expense, "comprovantes/{$group->id}/compra-{$expense->id}.jpg", 'Pago no débito');
        }

        // Agosto fica totalmente quitado (compra paga e os dois acertos confirmados).
        foreach ([$debtorA, $debtorB] as $debtor) {
            SettlementConfirmation::create([
                'group_id' => $group->id,
                'cycle_start' => '2026-08-01',
                'cycle_end' => '2026-08-31',
                'from_user_id' => $debtor->id,
                'to_user_id' => $creditor->id,
                'amount' => 40,
                'proof_path' => "comprovantes/{$group->id}/acerto-{$debtor->id}.jpg",
                'confirmed_at' => now(),
            ]);
        }

        return ['group' => $group, 'users' => $users, 'expenses' => [$august, $september, $october]];
    }

    /**
     * NR-03. Nenhum cálculo lê `paid_at` nem `paid_by` (só são gravados e
     * zerados em `store`, `pay` e `unpay`): trocar os dois não muda nenhuma
     * resposta. Cobre `summary` (selado, fechado e vigente), `gross-debts`,
     * `pending-cycles`, `closing-report`, `cycles` e `me/overview`.
     */
    public function test_changing_paid_at_and_paid_by_changes_no_output(): void
    {
        ['group' => $group, 'users' => $users, 'expenses' => $expenses] = $this->reportingScenario();

        $this->assertOutputsIgnorePaidAtAndPaidBy($group, $users, $expenses);
    }

    /**
     * TASK-530: as mesmas compras e as mesmas respostas, mas cadastradas já pagas, com foto e
     * descrição, pelo `POST /api/expenses` (cada uma no mês em que o cadastro ainda é aceito).
     */
    public function test_changing_paid_at_and_paid_by_changes_no_output_for_purchases_registered_paid_through_the_api(): void
    {
        ['group' => $group, 'users' => $users, 'expenses' => $expenses] = $this->reportingScenarioViaApi();

        $this->assertOutputsIgnorePaidAtAndPaidBy($group, $users, $expenses);
    }

    /**
     * @param  list<User>  $users
     * @param  list<\App\Models\Expense>  $expenses
     */
    private function assertOutputsIgnorePaidAtAndPaidBy(Group $group, array $users, array $expenses): void
    {
        $token = auth('api')->login($users[0]);
        $g = $group->id;

        $outputs = fn () => [
            'summary vigente' => $this->fetch($token, "/api/groups/{$g}/expenses/summary?cycles_ago=0"),
            'summary de setembro' => $this->fetch($token, "/api/groups/{$g}/expenses/summary?cycles_ago=1"),
            'summary de agosto (selado)' => $this->fetch($token, "/api/groups/{$g}/expenses/summary?cycles_ago=2"),
            'gross-debts vigente' => $this->fetch($token, "/api/groups/{$g}/expenses/gross-debts?cycles_ago=0"),
            'gross-debts de setembro' => $this->fetch($token, "/api/groups/{$g}/expenses/gross-debts?cycles_ago=1"),
            'pending-cycles' => $this->fetch($token, "/api/groups/{$g}/expenses/pending-cycles"),
            'closing-report de setembro' => $this->fetch($token, "/api/groups/{$g}/expenses/closing-report?cycle_start=2026-09-01"),
            'closing-report de agosto (selado)' => $this->fetch($token, "/api/groups/{$g}/expenses/closing-report?cycle_start=2026-08-01"),
            'cycles (histórico)' => $this->fetch($token, "/api/groups/{$g}/expenses/cycles"),
            'me/overview' => $this->fetch($token, '/api/me/overview'),
        ];

        // A primeira leitura sela agosto (summary sela na leitura); as medidas valem depois dela.
        $outputs();

        $baseline = $outputs();

        Quota::whereIn('expense_id', array_map(fn ($expense) => $expense->id, $expenses))
            ->update(['paid_at' => '2020-01-01 00:00:00', 'paid_by' => $users[2]->id]);

        $changed = $outputs();

        $this->assertNotEmpty($baseline['cycles (histórico)']['data'], 'o cenário precisa ter um ciclo selado no histórico');

        foreach ($baseline as $name => $output) {
            $this->assertEquals($output, $changed[$name], "a resposta '{$name}' mudou quando só paid_at e paid_by mudaram");
        }
    }

    /**
     * NR-09. Os relatórios anuais, o mensal, o resumo por mês e o `indexByGroup`
     * não leem `paid` (o `GroupExpenseReportController` nem lê `Quota`): marcar as
     * compras como pagas, com comprovante e descrição, não muda nenhuma resposta.
     */
    public function test_annual_reports_monthly_expenses_and_index_are_identical(): void
    {
        Carbon::setTestNow('2026-08-19 12:00:00');

        ['group' => $group, 'users' => $users] = $this->makeGroup(3);
        $creditor = $users[0];

        $expenses = [
            $this->makePurchase($group, $creditor, $users, 'IN_CASH', 120.0, '2026-08-10'),
            $this->makePurchase($group, $creditor, $users, 'IN_INSTALLMENTS', 100.0, '2026-08-10', 3),
            $this->makePurchase($group, $creditor, $users, 'FIXED', 60.0, '2026-08-12'),
        ];

        $token = auth('api')->login($creditor);
        $g = $group->id;

        $outputs = fn () => [
            'report anual' => $this->fetch($token, "/api/groups/{$g}/expenses/report/2026"),
            'report mensal com acerto' => $this->fetch($token, "/api/group/{$g}/report-monthly/2026"),
            'despesas por mês' => $this->fetch($token, "/api/groups/{$g}/expenses/monthly"),
            'índice de agosto' => $this->fetch($token, "/api/groups/{$g}/expenses?year=2026&month=8"),
            'índice de setembro' => $this->fetch($token, "/api/groups/{$g}/expenses?year=2026&month=9"),
            'índice de outubro' => $this->fetch($token, "/api/groups/{$g}/expenses?year=2026&month=10"),
        ];

        $unpaid = $outputs();

        foreach ($expenses as $expense) {
            $this->markPaid($expense, "comprovantes/{$g}/compra-{$expense->id}.jpg", 'Pago no débito');
        }

        $paid = $outputs();

        foreach ($unpaid as $name => $output) {
            $this->assertEquals($output, $paid[$name], "a resposta '{$name}' mudou quando as compras foram marcadas como pagas");
        }
    }

    /**
     * Setembro de 2026, fechado por data em "2026-10-19": uma compra À Vista de
     * 90,00 dividida por 3 (cada devedor deve 30,00 ao credor), ainda pendente e
     * sem acerto confirmado.
     *
     * @return array{group: Group, users: list<User>, expense: \App\Models\Expense}
     */
    private function closedSeptemberScenario(): array
    {
        Carbon::setTestNow('2026-10-19 12:00:00');

        ['group' => $group, 'users' => $users] = $this->makeGroup(3);
        $expense = $this->makePurchase($group, $users[0], $users, 'IN_CASH', 90.0, '2026-09-10');

        return ['group' => $group, 'users' => $users, 'expense' => $expense];
    }

    private function confirmSeptemberSettlements(Group $group, array $users): void
    {
        foreach ([$users[1], $users[2]] as $debtor) {
            SettlementConfirmation::create([
                'group_id' => $group->id,
                'cycle_start' => '2026-09-01',
                'cycle_end' => '2026-09-30',
                'from_user_id' => $debtor->id,
                'to_user_id' => $users[0]->id,
                'amount' => 30,
                'proof_path' => "comprovantes/{$group->id}/acerto-{$debtor->id}.jpg",
                'confirmed_at' => now(),
            ]);
        }
    }

    /**
     * Item de `pendingCycles` do ciclo que começa em `$start`, ou null.
     *
     * @return array<string, mixed>|null
     */
    private function pendingCycleItem(Group $group, string $start): ?array
    {
        foreach ($this->service()->pendingCycles($group) as $item) {
            if ($item['cycle']['start'] === $start) {
                return $item;
            }
        }

        return null;
    }

    /**
     * NR-04. Pagar a compra só tira o pendente; o ciclo continua precisando dos
     * acertos confirmados para ficar quitado, e só a leitura de `summary()` o
     * sela. A compra paga leva ao mesmo estado final que `pay()` levaria.
     */
    public function test_settlement_and_sealing_follow_the_equivalent_state(): void
    {
        ['group' => $group, 'users' => $users, 'expense' => $expense] = $this->closedSeptemberScenario();
        $start = Carbon::parse('2026-09-01');
        $end = Carbon::parse('2026-09-30');
        $token = auth('api')->login($users[0]);
        $sealed = fn () => (bool) \App\Models\GroupCycleSnapshot::where('group_id', $group->id)
            ->where('cycle_start', '2026-09-01')->whereNotNull('settled_at')->exists();

        // 1. Pendente e sem acerto confirmado.
        $item = $this->pendingCycleItem($group, '2026-09-01');
        $this->assertSame(1, $item['pending_expenses_count']);
        $this->assertSame([2, 0, false], [$item['settlements_total'], $item['settlements_confirmed'], $item['awaiting_seal']]);
        $this->assertFalse($this->service()->isFullySettled($group, $start, $end));

        // 2. Acertos confirmados, compra ainda pendente: não está quitado.
        $this->confirmSeptemberSettlements($group, $users);
        $item = $this->pendingCycleItem($group, '2026-09-01');
        $this->assertSame([1, 2, false], [$item['pending_expenses_count'], $item['settlements_confirmed'], $item['awaiting_seal']]);
        $this->assertFalse($this->service()->isFullySettled($group, $start, $end));
        $this->withToken($token)->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1")->assertOk();
        $this->assertFalse($sealed(), 'a compra pendente impede a selagem');

        // 3. Compra paga: quitado, mas ainda não selado (pendingCycles e isFullySettled só leem).
        $this->markPaid($expense);
        $item = $this->pendingCycleItem($group, '2026-09-01');
        $this->assertSame([0, true], [$item['pending_expenses_count'], $item['awaiting_seal']]);
        $this->assertTrue($this->service()->isFullySettled($group, $start, $end));
        $this->assertFalse($sealed());

        // 4. A leitura de summary() sela o ciclo e ele sai da lista de pendentes.
        $this->withToken($token)->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1")
            ->assertOk()->assertJsonPath('cycle.settled', true);
        $this->assertTrue($sealed());
        $this->assertNull($this->pendingCycleItem($group, '2026-09-01'));
    }

    /**
     * NR-04. `focus()` dá a mesma resposta da varredura completa (a que olha ciclo
     * a ciclo, sem atalho) com a compra pendente, paga com acertos pendentes e
     * paga com acertos confirmados. O teste existente de
     * `CycleSummaryServiceTest.php:516` não é editado.
     */
    public function test_focus_gives_the_same_answer_for_a_fully_paid_purchase(): void
    {
        ['group' => $group, 'users' => $users, 'expense' => $expense] = $this->closedSeptemberScenario();

        $fullScan = function () use ($group): int {
            for ($ago = 0; $ago <= CycleSummaryService::FOCUS_LOOKBACK; $ago++) {
                $cycle = \App\Support\BillingCycle::cycleFor($group->closing_day, Carbon::now(), $ago);
                $snapshot = \App\Models\GroupCycleSnapshot::where('group_id', $group->id)
                    ->where('cycle_start', $cycle['start']->toDateString())->first();

                if ($snapshot && $snapshot->isSealed()) {
                    continue;
                }

                $closedOrInGrace = $cycle['status'] === 'closed'
                    || ($cycle['status'] === 'open' && Carbon::now()->startOfDay()->gt($cycle['end']));

                if ($closedOrInGrace && ! $this->service()->isFullySettled($group, $cycle['start'], $cycle['end'])) {
                    return $ago;
                }
            }

            return 0;
        };

        $this->assertSame($fullScan(), $this->service()->focus($group));
        $this->assertSame(1, $this->service()->focus($group), 'compra pendente em setembro: o foco volta um ciclo');

        $this->markPaid($expense);
        $this->assertSame($fullScan(), $this->service()->focus($group));
        $this->assertSame(1, $this->service()->focus($group), 'paga, mas com acertos por confirmar: continua em setembro');

        $this->confirmSeptemberSettlements($group, $users);
        $this->assertSame($fullScan(), $this->service()->focus($group));
        $this->assertSame(0, $this->service()->focus($group), 'paga e com acertos confirmados: nada pendente');
    }

    /**
     * NR-05. `ClosingBreakdown` e o relatório de fechamento leem só `bornPaid`:
     * credores, devedores e valores brutos não mudam com o pagamento.
     */
    public function test_closing_breakdown_and_closing_report_are_identical(): void
    {
        ['group' => $group, 'users' => $users, 'expense' => $expense] = $this->closedSeptemberScenario();
        $token = auth('api')->login($users[0]);
        $uri = "/api/groups/{$group->id}/expenses/closing-report?cycle_start=2026-09-01";

        $unpaid = $this->fetch($token, $uri);

        $this->markPaid($expense, "comprovantes/{$group->id}/compra.jpg", 'Pago no débito');

        $paid = $this->fetch($token, $uri);

        $this->assertNotEmpty($unpaid['creditors']);
        $this->assertEquals($unpaid['creditors'], $paid['creditors']);
        $this->assertEquals($unpaid['balances'], $paid['balances']);
        $this->assertEquals($unpaid['settlements'], $paid['settlements']);
        $this->assertEquals($unpaid['totals']['total'], $paid['totals']['total']);
    }

    /**
     * NR-06. O snapshot selado e o histórico de ciclos têm as mesmas chaves de
     * hoje: nada novo entra na cópia congelada (R14). Um snapshot antigo, sem
     * `paymentNote` nem os campos aditivos, continua sendo lido.
     */
    public function test_cycle_history_and_snapshot_keep_the_same_keys(): void
    {
        ['group' => $group, 'users' => $users, 'expense' => $expense] = $this->closedSeptemberScenario();
        $token = auth('api')->login($users[0]);

        $this->markPaid($expense, "comprovantes/{$group->id}/compra.jpg", 'Pago no débito');
        $this->confirmSeptemberSettlements($group, $users);

        $this->withToken($token)->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1")->assertOk();

        $history = $this->fetch($token, "/api/groups/{$group->id}/expenses/cycles");
        $this->assertCount(1, $history['data']);
        $item = $history['data'][0];

        $this->assertSame(['cycle', 'totals', 'expenses', 'balances', 'settlements'], array_keys($item));

        $expectedKeys = [
            'id', 'description', 'date', 'value', 'valuePerPerson', 'paid', 'bornPaid', 'paymentProofUrl',
            'paymentNote', 'payerName', 'payerAvatarUrl', 'participants', 'participantDetails', 'isFixed',
            'expenseType', 'installmentNumber', 'installmentsTotal', 'totalValue', 'userPayerId', 'userCreatorId',
        ];
        $snapshotExpense = $item['expenses'][0];
        $this->assertEqualsCanonicalizing($expectedKeys, array_keys($snapshotExpense));
        $this->assertTrue($snapshotExpense['paid']);
        $this->assertSame('Pago no débito', $snapshotExpense['paymentNote']);
        $this->assertNotNull($snapshotExpense['paymentProofUrl']);

        // Snapshot antigo: a despesa sem `paymentNote` e sem os campos aditivos.
        $old = $item['expenses'][0];
        unset($old['paymentNote'], $old['payerAvatarUrl'], $old['participantDetails'], $old['expenseType'], $old['installmentNumber'], $old['installmentsTotal'], $old['totalValue']);
        \App\Models\GroupCycleSnapshot::where('group_id', $group->id)->where('cycle_start', '2026-09-01')
            ->update(['expenses' => json_encode([$old])]);

        $this->withToken($token)->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1")
            ->assertOk()->assertJsonPath('cycle.settled', true);
        $this->withToken($token)->getJson("/api/groups/{$group->id}/expenses/cycles")->assertOk();
        $this->withToken($token)->getJson("/api/groups/{$group->id}/expenses/closing-report?cycle_start=2026-09-01")->assertOk();
        $this->withToken($token)->getJson('/api/me/overview')->assertOk();
    }

    /**
     * NR-07. Fixa a decisão D13: `grossDebts` (a árvore credor→devedores do
     * Dashboard) PULA a quota paga (`ExpenseController.php:987`), enquanto
     * `settlements` continua cobrando a mesma dívida até o devedor confirmar o
     * acerto. A divergência já existe hoje com o ✓ por parcela; este teste a
     * documenta para a feature não mexer num lado sem querer. Backlog 111.
     */
    public function test_gross_debts_skips_a_paid_quota_and_lists_it_again_when_unpaid(): void
    {
        ['group' => $group, 'users' => $users, 'expense' => $expense] = $this->closedSeptemberScenario();
        [$creditor, $debtorA, $debtorB] = $users;
        $token = auth('api')->login($creditor);
        $g = $group->id;

        $debts = fn () => collect($this->fetch($token, "/api/groups/{$g}/expenses/gross-debts?cycles_ago=1")['creditors'])
            ->flatMap(fn (array $node) => collect($node['debtors'])
                ->map(fn (array $debtor) => [$node['creditor']['id'], $debtor['id'], (float) $debtor['amount']]))
            ->sortBy(1)->values()->all();

        $owedByEach = [[$creditor->id, $debtorA->id, 30.0], [$creditor->id, $debtorB->id, 30.0]];
        $this->assertSame($owedByEach, $debts(), 'pendente: a árvore lista os dois devedores');

        $this->markPaid($expense);

        $this->assertSame([], $debts(), 'paga: a árvore não lista a quota');

        // `settlements` segue cobrando a mesma dívida enquanto não houver confirmação.
        $settlements = $this->fetch($token, "/api/groups/{$g}/expenses/summary?cycles_ago=1")['settlements'];
        $this->assertCount(2, $settlements);
        $this->assertSame([30.0, 30.0], array_map(fn (array $settlement) => (float) $settlement['amount'], $settlements));
        $this->assertSame([null, null], array_column($settlements, 'confirmedAt'));

        $expense->quotas()->update(['paid' => false, 'paid_at' => null, 'paid_by' => null]);

        $this->assertSame($owedByEach, $debts(), 'desfeita: a árvore volta igual');
    }

    /**
     * NR-08. No Painel, `owe`, `receive`, `to_pay` e `to_receive` vêm de
     * `settlements` e não mudam com o pagamento; os itens seguem `paid`
     * (`paid`, `can_pay`, `can_unpay`, `pending_items_count`). E a reconciliação
     * `owe × settlements` do resumo continua fechando.
     */
    public function test_overview_owe_and_receive_are_identical_and_items_follow_paid(): void
    {
        ['group' => $group, 'users' => $users, 'expense' => $expense] = $this->closedSeptemberScenario();
        [$creditor, $debtor] = $users;
        $overview = app(\App\Services\PersonalOverviewService::class);
        $groupOf = fn (User $user) => $overview->forUser($user)['groups'][0];

        $debtorBefore = $groupOf($debtor);
        $creditorBefore = $groupOf($creditor);

        $this->markPaid($expense);

        $debtorAfter = $groupOf($debtor);
        $creditorAfter = $groupOf($creditor);

        foreach (['owe', 'receive', 'to_pay', 'to_receive', 'has_open', 'cycle'] as $key) {
            $this->assertEquals($debtorBefore[$key], $debtorAfter[$key], "'{$key}' do devedor mudou com o pagamento");
            $this->assertEquals($creditorBefore[$key], $creditorAfter[$key], "'{$key}' do credor mudou com o pagamento");
        }

        $this->assertEqualsWithDelta(30.0, $debtorAfter['owe']['total'], 0.001);
        $this->assertEqualsWithDelta(60.0, $creditorAfter['receive']['total'], 0.001);

        // Os itens acompanham `paid`; quem é só devedor nunca pode pagar nem desfazer.
        $this->assertSame([false, false, false, 1], [$debtorBefore['my_items'][0]['paid'], $debtorBefore['my_items'][0]['can_pay'], $debtorBefore['my_items'][0]['can_unpay'], $debtorBefore['pending_items_count']]);
        $this->assertSame([true, false, false, 0], [$debtorAfter['my_items'][0]['paid'], $debtorAfter['my_items'][0]['can_pay'], $debtorAfter['my_items'][0]['can_unpay'], $debtorAfter['pending_items_count']]);
        $this->assertSame([false, true, false, 1], [$creditorBefore['my_items'][0]['paid'], $creditorBefore['my_items'][0]['can_pay'], $creditorBefore['my_items'][0]['can_unpay'], $creditorBefore['pending_items_count']]);
        $this->assertSame([true, false, true, 0], [$creditorAfter['my_items'][0]['paid'], $creditorAfter['my_items'][0]['can_pay'], $creditorAfter['my_items'][0]['can_unpay'], $creditorAfter['pending_items_count']]);

        // Reconciliação: o `owe` do devedor é a soma dos acertos dele no resumo do mesmo ciclo.
        $token = auth('api')->login($creditor);
        $summary = $this->fetch($token, "/api/groups/{$group->id}/expenses/summary?cycles_ago={$debtorAfter['cycle']['cycles_ago']}");
        $fromDebtor = collect($summary['settlements'])->where('from_user_id', $debtor->id)->sum('amount');
        $this->assertEqualsWithDelta($fromDebtor, $debtorAfter['owe']['total'], 0.001);
    }

    // --- TASK-530: as mesmas verificações com as compras cadastradas pelo POST /api/expenses -----

    /**
     * Cadastra pelo `POST /api/expenses` (JSON, ou multipart com foto) uma compra do `$creditor`
     * dividida entre `$participants`, já paga (com descrição) ou pendente.
     *
     * @param  list<User>  $participants
     */
    private function registerPurchase(
        Group $group,
        User $creditor,
        array $participants,
        string $type,
        float $total,
        string $start,
        int $n = 1,
        bool $paid = true,
        bool $withPhoto = false
    ): Expense {
        $body = [
            'date_payment' => $start,
            'description' => 'Compra de teste',
            'expense_type' => $type,
            'installments' => $type === 'IN_INSTALLMENTS' ? $n : 1,
            'total_value' => $total,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'payers' => array_map(fn (User $user) => $user->id, $participants),
        ];

        if ($paid) {
            $body['descricao_pagamento'] = 'Pago no débito';
        }

        $request = $this->withToken(auth('api')->login($creditor));
        $response = $withPhoto
            ? $request->post('/api/expenses', $body + ['comprovante' => UploadedFile::fake()->image('compra.jpg')], ['Accept' => 'application/json'])
            : $request->postJson('/api/expenses', $body);

        return Expense::findOrFail($response->assertStatus(201)->json('expense_id'));
    }

    /** O resumo de um ciclo sem os ids das despesas, que mudam de um grupo para o outro. */
    private function withoutExpenseIds(array $summary): array
    {
        $summary['expenses'] = array_map(function (array $expense) {
            unset($expense['id']);

            return $expense;
        }, $summary['expenses']);

        return $summary;
    }

    /**
     * Equivalência de estado, forma por forma: uma compra cadastrada JÁ paga pelo `POST` fica
     * exatamente como outra cadastrada pendente e marcada paga na sequência (o estado que
     * NR-01 a NR-09 já provam). Compara quota a quota e o `compute()` de cada ciclo, em dois
     * grupos gêmeos com os mesmos membros.
     *
     * @dataProvider shapes
     */
    public function test_a_purchase_registered_paid_has_the_same_state_as_one_registered_then_marked_paid(
        string $now,
        int $members,
        bool $creditorParticipates,
        string $type,
        float $total,
        string $start,
        int $n,
        int $cycleCount
    ): void {
        Carbon::setTestNow($now);

        ['group' => $groupA, 'users' => $users] = $this->makeGroup($members);
        $groupB = Group::create(['name' => 'Grupo gêmeo']);
        $groupB->members()->attach(array_map(fn (User $user) => $user->id, $users));
        $creditor = $users[0];
        $participants = $creditorParticipates ? $users : array_slice($users, 1);

        $viaApi = $this->registerPurchase($groupA, $creditor, $participants, $type, $total, $start, $n);
        $twin = $this->registerPurchase($groupB, $creditor, $participants, $type, $total, $start, $n, paid: false);
        $this->markPaid($twin, null, 'Pago no débito');

        $stateOf = fn (Expense $expense) => $expense->quotas()->orderBy('number')->get()->map(fn (Quota $quota) => [
            'number' => $quota->number,
            'date' => $quota->date_expected->toDateString(),
            'value' => (float) $quota->value_quota,
            'paid' => $quota->paid,
            'paid_by' => $quota->paid_by,
            'born_paid' => $quota->born_paid,
            'note' => $quota->payment_note,
            'proof' => $quota->payment_proof_path,
        ])->all();

        $this->assertEquals($stateOf($twin), $stateOf($viaApi), 'as quotas ficam iguais');

        foreach ($this->cyclesFrom($groupA, $start, $cycleCount) as $cycle) {
            $this->assertEquals(
                $this->withoutExpenseIds($this->service()->compute($groupB, $cycle['start'], $cycle['end'])),
                $this->withoutExpenseIds($this->service()->compute($groupA, $cycle['start'], $cycle['end'])),
                "o compute() do ciclo {$cycle['start']->toDateString()} fica igual"
            );
        }
    }

    /**
     * O cenário de `reportingScenario()` (agosto selado, setembro fechado, outubro vigente),
     * com cada compra cadastrada pelo `POST` no mês dela e já paga, com foto e descrição.
     * "Agora" termina em 2026-10-19.
     *
     * @return array{group: Group, users: list<User>, expenses: list<Expense>}
     */
    private function reportingScenarioViaApi(): array
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-08-19 12:00:00');

        ['group' => $group, 'users' => $users] = $this->makeGroup(3);
        [$creditor, $debtorA, $debtorB] = $users;

        $august = $this->registerPurchase($group, $creditor, $users, 'IN_CASH', 120.0, '2026-08-10', withPhoto: true);

        Carbon::setTestNow('2026-09-19 12:00:00');
        $september = $this->registerPurchase($group, $creditor, $users, 'IN_CASH', 90.0, '2026-09-10', withPhoto: true);

        Carbon::setTestNow('2026-10-19 12:00:00');
        $october = $this->registerPurchase($group, $creditor, $users, 'IN_INSTALLMENTS', 300.0, '2026-10-05', 3, withPhoto: true);

        foreach ([$debtorA, $debtorB] as $debtor) {
            SettlementConfirmation::create([
                'group_id' => $group->id,
                'cycle_start' => '2026-08-01',
                'cycle_end' => '2026-08-31',
                'from_user_id' => $debtor->id,
                'to_user_id' => $creditor->id,
                'amount' => 40,
                'proof_path' => "comprovantes/{$group->id}/acerto-{$debtor->id}.jpg",
                'confirmed_at' => now(),
            ]);
        }

        return ['group' => $group, 'users' => $users, 'expenses' => [$august, $september, $october]];
    }

    /**
     * Setembro de 2026 como em `closedSeptemberScenario()`, mas a compra de 90,00 é cadastrada
     * pelo `POST` em 19/09, já paga. Depois o relógio vai para 2026-10-19 (setembro fechado).
     *
     * @return array{group: Group, users: list<User>, expense: Expense}
     */
    private function septemberRegisteredPaid(): array
    {
        Carbon::setTestNow('2026-09-19 12:00:00');

        ['group' => $group, 'users' => $users] = $this->makeGroup(3);
        $expense = $this->registerPurchase($group, $users[0], $users, 'IN_CASH', 90.0, '2026-09-10');

        Carbon::setTestNow('2026-10-19 12:00:00');

        return ['group' => $group, 'users' => $users, 'expense' => $expense];
    }

    /** NR-04 com a compra cadastrada já paga: quitada só com os acertos confirmados; selada pela leitura de `summary()`. */
    public function test_settlement_and_sealing_with_a_purchase_registered_paid_through_the_api(): void
    {
        ['group' => $group, 'users' => $users] = $this->septemberRegisteredPaid();
        $start = Carbon::parse('2026-09-01');
        $end = Carbon::parse('2026-09-30');
        $token = auth('api')->login($users[0]);
        $sealed = fn () => (bool) \App\Models\GroupCycleSnapshot::where('group_id', $group->id)
            ->where('cycle_start', '2026-09-01')->whereNotNull('settled_at')->exists();

        $item = $this->pendingCycleItem($group, '2026-09-01');
        $this->assertSame([0, 2, 0, false], [$item['pending_expenses_count'], $item['settlements_total'], $item['settlements_confirmed'], $item['awaiting_seal']]);
        $this->assertFalse($this->service()->isFullySettled($group, $start, $end), 'paga, mas os acertos seguem em aberto');
        $this->assertFalse($sealed());

        $this->confirmSeptemberSettlements($group, $users);

        $item = $this->pendingCycleItem($group, '2026-09-01');
        $this->assertSame([0, 2, true], [$item['pending_expenses_count'], $item['settlements_confirmed'], $item['awaiting_seal']]);
        $this->assertTrue($this->service()->isFullySettled($group, $start, $end));
        $this->assertFalse($sealed(), 'pendingCycles e isFullySettled só leem');

        $this->withToken($token)->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1")
            ->assertOk()->assertJsonPath('cycle.settled', true);
        $this->assertTrue($sealed());
        $this->assertNull($this->pendingCycleItem($group, '2026-09-01'));
    }

    /** NR-05, NR-07 e NR-08 com a compra cadastrada já paga. */
    public function test_gross_debts_closing_report_and_overview_with_a_purchase_registered_paid_through_the_api(): void
    {
        ['group' => $group, 'users' => $users] = $this->septemberRegisteredPaid();
        [$creditor, $debtorA, $debtorB] = $users;
        $token = auth('api')->login($creditor);
        $g = $group->id;

        // NR-07: a árvore bruta pula a quota paga, e `settlements` segue cobrando (D13).
        $this->assertSame([], $this->fetch($token, "/api/groups/{$g}/expenses/gross-debts?cycles_ago=1")['creditors']);
        $settlements = $this->fetch($token, "/api/groups/{$g}/expenses/summary?cycles_ago=1")['settlements'];
        $this->assertSame([30.0, 30.0], array_map(fn (array $settlement) => (float) $settlement['amount'], $settlements));

        // NR-05: o credor adiantou 90,00 e cada devedor deve 30,00, pago ou não.
        $creditors = $this->fetch($token, "/api/groups/{$g}/expenses/closing-report?cycle_start=2026-09-01")['creditors'];
        $this->assertCount(1, $creditors);
        $this->assertEqualsWithDelta(90.0, $creditors[0]['advanced_total'], 0.001);
        $this->assertEqualsWithDelta(60.0, array_sum(array_column($creditors[0]['debtors'], 'amount')), 0.001);

        // NR-08: o Painel mostra o que cada um deve e o item "Paga".
        $debtorView = app(\App\Services\PersonalOverviewService::class)->forUser($debtorA)['groups'][0];
        $this->assertEqualsWithDelta(30.0, $debtorView['owe']['total'], 0.001);
        $this->assertSame([true, false, 0], [$debtorView['my_items'][0]['paid'], $debtorView['my_items'][0]['can_pay'], $debtorView['pending_items_count']]);

        $creditorView = app(\App\Services\PersonalOverviewService::class)->forUser($creditor)['groups'][0];
        $this->assertEqualsWithDelta(60.0, $creditorView['receive']['total'], 0.001);
        $this->assertSame([true, false, true], [$creditorView['my_items'][0]['paid'], $creditorView['my_items'][0]['can_pay'], $creditorView['my_items'][0]['can_unpay']]);
        $this->assertNotNull($debtorB);
    }
}
