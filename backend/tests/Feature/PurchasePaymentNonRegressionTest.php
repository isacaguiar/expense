<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Quota;
use App\Models\SettlementConfirmation;
use App\Models\User;
use App\Services\CycleSummaryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
