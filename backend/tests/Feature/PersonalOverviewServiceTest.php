<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\SettlementConfirmation;
use App\Models\User;
use App\Services\PersonalOverviewService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PersonalOverviewService::forUser(): a visão pessoal por grupo (o que o
 * usuário deve, o que tem a receber, os itens dele e as flags de ação), sobre o
 * ciclo em foco de cada grupo. Ver
 * docs/feature/20261010-painel-financeiro-pessoal/plan.md §1.3.
 *
 * "Agora" é 15/10/2026 com `closing_day` nulo (mês calendário): outubro é o
 * ciclo vigente e setembro (1 ciclo atrás) já fechou por data em 05/10.
 */
class PersonalOverviewServiceTest extends TestCase
{
    use DatabaseTransactions;

    private User $creditor;

    private User $debtor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15');

        $this->creditor = User::factory()->create([
            'name' => 'Ana Credora',
            'email' => 'ana@example.test',
            'pix' => 'ana-pix-key',
        ]);
        $this->debtor = User::factory()->create([
            'name' => 'Beto Devedor',
            'email' => 'beto@example.test',
            'pix' => 'beto-pix-key',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function service(): PersonalOverviewService
    {
        return app(PersonalOverviewService::class);
    }

    private function group(string $name, User ...$members): Group
    {
        $group = Group::create(['name' => $name]);
        $group->members()->attach(array_map(fn (User $user) => $user->id, $members));

        return $group;
    }

    private function pairGroup(string $name = 'Grupo'): Group
    {
        return $this->group($name, $this->creditor, $this->debtor);
    }

    /**
     * Despesa À Vista paga por `$creditor` e dividida entre ele e `$others`.
     *
     * @param  array<int, User>  $others
     */
    private function expense(
        Group $group,
        User $creditor,
        array $others,
        float $total,
        string $date,
        bool $paid = false,
        string $description = 'Despesa',
        bool $bornPaid = false,
    ): Expense {
        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => $date,
            'description' => $description,
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => $total,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);
        $expense->payers()->sync(array_merge([$creditor->id], array_map(fn (User $user) => $user->id, $others)));
        $expense->quotas()->create([
            'date_expected' => $date,
            'number' => 1,
            'paid' => $paid,
            'born_paid' => $bornPaid,
            'value_quota' => $total,
        ]);

        return $expense;
    }

    private function confirm(Group $group, User $from, User $to, float $amount, string $cycleStart = '2026-09-01', string $cycleEnd = '2026-09-30'): SettlementConfirmation
    {
        return SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => $cycleStart,
            'cycle_end' => $cycleEnd,
            'from_user_id' => $from->id,
            'to_user_id' => $to->id,
            'amount' => $amount,
            'proof_path' => 'comprovantes/'.$group->id.'/x.jpg',
            'confirmed_at' => now(),
        ]);
    }

    /** @return array<string, mixed> o item do único grupo devolvido */
    private function onlyGroup(User $user): array
    {
        $groups = $this->service()->forUser($user)['groups'];
        $this->assertCount(1, $groups);

        return $groups[0];
    }

    /** Quantas consultas SQL `$run` dispara. */
    private function queriesOf(callable $run): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    // --- raiz e estrutura -----------------------------------------------------

    public function test_the_root_identifies_the_user_and_each_group_has_the_documented_shape(): void
    {
        $group = $this->pairGroup('Residencial');
        $this->expense($group, $this->creditor, [$this->debtor], 100, '2026-09-10');

        $result = $this->service()->forUser($this->debtor);

        $this->assertSame(['user', 'groups', 'truncated'], array_keys($result));
        $this->assertFalse($result['truncated']);
        $this->assertSame(['id' => $this->debtor->id, 'name' => 'Beto Devedor'], $result['user']);

        $item = $result['groups'][0];
        $this->assertSame(
            ['group', 'cycle', 'owe', 'receive', 'to_pay', 'to_receive', 'my_items', 'pending_items_count', 'has_open'],
            array_keys($item)
        );
        $this->assertSame(['id' => $group->id, 'name' => 'Residencial'], $item['group']);
        $this->assertSame(
            ['start', 'end', 'closes_at', 'status', 'settled', 'in_grace', 'cycles_ago'],
            array_keys($item['cycle'])
        );
        $this->assertSame(['total', 'confirmed', 'open'], array_keys($item['owe']));
        $this->assertSame(['total', 'confirmed', 'open'], array_keys($item['receive']));
    }

    // --- devedor --------------------------------------------------------------

    public function test_a_debtor_sees_what_they_owe_in_the_closed_cycle_in_focus(): void
    {
        $this->expense($this->pairGroup(), $this->creditor, [$this->debtor], 100, '2026-09-10');

        $item = $this->onlyGroup($this->debtor);

        $this->assertSame('2026-09-01', $item['cycle']['start']);
        $this->assertSame('2026-09-30', $item['cycle']['end']);
        $this->assertSame('closed', $item['cycle']['status']);
        $this->assertSame(1, $item['cycle']['cycles_ago']);

        $this->assertCount(1, $item['to_pay']);
        $row = $item['to_pay'][0];
        $this->assertEquals(50.0, $row['amount']);
        $this->assertTrue($row['can_confirm']);
        $this->assertNull($row['confirmed_at']);
        $this->assertNull($row['confirmed_proof_url']);
        $this->assertSame(['id', 'name', 'email', 'pix', 'avatar_url'], array_keys($row['to_user']));
        $this->assertSame($this->creditor->id, $row['to_user']['id']);
        $this->assertSame('Ana Credora', $row['to_user']['name']);
        $this->assertSame('ana@example.test', $row['to_user']['email']);
        $this->assertSame('ana-pix-key', $row['to_user']['pix']);

        $this->assertEquals(['total' => 50.0, 'confirmed' => 0.0, 'open' => 50.0], $item['owe']);
        $this->assertEquals(['total' => 0.0, 'confirmed' => 0.0, 'open' => 0.0], $item['receive']);
        $this->assertSame([], $item['to_receive']);
        $this->assertTrue($item['has_open']);
    }

    public function test_a_confirmed_settlement_counts_as_confirmed_and_carries_the_proof(): void
    {
        $group = $this->pairGroup();
        $this->expense($group, $this->creditor, [$this->debtor], 100, '2026-09-10');
        $this->confirm($group, $this->debtor, $this->creditor, 50);

        $item = $this->onlyGroup($this->debtor);

        $this->assertEquals(['total' => 50.0, 'confirmed' => 50.0, 'open' => 0.0], $item['owe']);
        $this->assertNotNull($item['to_pay'][0]['confirmed_at']);
        $this->assertNotNull($item['to_pay'][0]['confirmed_proof_url']);
        // O acerto está confirmado; a despesa segue pendente até o credor marcá-la.
        $this->assertFalse($item['has_open']);
        $this->assertSame(1, $item['pending_items_count']);
    }

    // --- credor ---------------------------------------------------------------

    public function test_a_creditor_sees_what_they_receive_without_the_debtors_contact_data(): void
    {
        $group = $this->pairGroup();
        $this->expense($group, $this->creditor, [$this->debtor], 100, '2026-09-10');

        $item = $this->onlyGroup($this->creditor);

        $this->assertCount(1, $item['to_receive']);
        $row = $item['to_receive'][0];
        $this->assertEquals(50.0, $row['amount']);
        $this->assertNull($row['confirmed_at']);
        // Só o necessário: sem e-mail nem Pix do devedor.
        $this->assertSame(['id', 'name', 'avatar_url'], array_keys($row['from_user']));
        $this->assertSame($this->debtor->id, $row['from_user']['id']);
        $this->assertSame('Beto Devedor', $row['from_user']['name']);

        $this->assertEquals(['total' => 50.0, 'confirmed' => 0.0, 'open' => 50.0], $item['receive']);
        $this->assertEquals(['total' => 0.0, 'confirmed' => 0.0, 'open' => 0.0], $item['owe']);
        $this->assertSame([], $item['to_pay']);
        $this->assertTrue($item['has_open']);

        $this->confirm($group, $this->debtor, $this->creditor, 50);

        $this->assertEquals(['total' => 50.0, 'confirmed' => 50.0, 'open' => 0.0], $this->onlyGroup($this->creditor)['receive']);
        $this->assertNotNull($this->onlyGroup($this->creditor)['to_receive'][0]['confirmed_at']);
    }

    // --- dois papéis no mesmo grupo ---------------------------------------------

    public function test_owe_and_receive_use_the_net_settlement_when_both_paid_for_each_other(): void
    {
        $group = $this->pairGroup();
        // Ana pagou 100 e Beto pagou 40, cada uma dividida ao meio entre os dois:
        // Beto deve 50 a Ana e Ana deve 20 a Beto, líquido: Beto paga 30 a Ana.
        $this->expense($group, $this->creditor, [$this->debtor], 100, '2026-09-10', false, 'Ana pagou');
        $this->expense($group, $this->debtor, [$this->creditor], 40, '2026-09-11', false, 'Beto pagou');

        $asDebtor = $this->onlyGroup($this->debtor);
        $this->assertCount(1, $asDebtor['to_pay']);
        $this->assertEquals(30.0, $asDebtor['to_pay'][0]['amount']);
        $this->assertEquals(30.0, $asDebtor['owe']['total']);
        $this->assertEquals(0.0, $asDebtor['receive']['total']);

        $asCreditor = $this->onlyGroup($this->creditor);
        $this->assertCount(1, $asCreditor['to_receive']);
        $this->assertEquals(30.0, $asCreditor['receive']['total']);
        $this->assertEquals(0.0, $asCreditor['owe']['total']);
    }

    // --- itens do usuário ---------------------------------------------------------

    public function test_my_items_carry_role_share_and_the_action_flags_for_the_creditor_only(): void
    {
        $group = $this->pairGroup();
        $this->expense($group, $this->creditor, [$this->debtor], 100, '2026-09-10', false, 'Aberta');
        $this->expense($group, $this->creditor, [$this->debtor], 60, '2026-09-12', true, 'Paga');

        $byDescription = fn (array $items) => collect($items)->keyBy('description');

        $asDebtor = $byDescription($this->onlyGroup($this->debtor)['my_items']);
        $this->assertCount(2, $asDebtor);
        $this->assertSame('debtor', $asDebtor['Aberta']['role']);
        $this->assertEquals(50.0, $asDebtor['Aberta']['my_share']);
        $this->assertEquals(100.0, $asDebtor['Aberta']['value']);
        $this->assertFalse($asDebtor['Aberta']['paid']);
        $this->assertSame(['id' => $this->creditor->id, 'name' => 'Ana Credora'], $asDebtor['Aberta']['creditor']);
        $this->assertSame('2026-09-10', $asDebtor['Aberta']['date']);
        $this->assertSame('debtor', $asDebtor['Paga']['role']);
        // O devedor nunca recebe as flags de ação, nem na despesa aberta, nem na paga.
        foreach ($asDebtor as $item) {
            $this->assertFalse($item['can_pay']);
            $this->assertFalse($item['can_unpay']);
        }

        $asCreditor = $byDescription($this->onlyGroup($this->creditor)['my_items']);
        $this->assertSame('creditor', $asCreditor['Aberta']['role']);
        $this->assertTrue($asCreditor['Aberta']['can_pay']);
        $this->assertFalse($asCreditor['Aberta']['can_unpay']);
        $this->assertSame('creditor', $asCreditor['Paga']['role']);
        $this->assertFalse($asCreditor['Paga']['can_pay']);
        $this->assertTrue($asCreditor['Paga']['can_unpay']);
        $this->assertEquals(60.0, $asCreditor['Paga']['value']);
    }

    public function test_installment_is_set_only_for_installment_expenses(): void
    {
        $group = $this->pairGroup();
        $this->expense($group, $this->creditor, [$this->debtor], 100, '2026-09-10', false, 'À vista');

        $installments = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Parcelada',
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 3,
            'total_value' => 300,
            'group_id' => $group->id,
            'user_creator_id' => $this->creditor->id,
            'user_payer_id' => $this->creditor->id,
            'deleted' => false,
        ]);
        $installments->payers()->sync([$this->creditor->id, $this->debtor->id]);
        foreach ([1 => '2026-08-10', 2 => '2026-09-10', 3 => '2026-10-10'] as $number => $date) {
            $installments->quotas()->create(['date_expected' => $date, 'number' => $number, 'paid' => false, 'value_quota' => 100]);
        }

        $items = collect($this->onlyGroup($this->debtor)['my_items'])->keyBy('description');

        $this->assertNull($items['À vista']['installment']);
        // A parcela que vence em setembro é a 2 de 3.
        $this->assertSame(['number' => 2, 'total' => 3], $items['Parcelada']['installment']);
    }

    public function test_born_paid_entries_are_not_listed_as_items(): void
    {
        $group = $this->pairGroup();
        $this->expense($group, $this->creditor, [$this->debtor], 100, '2026-09-10', false, 'Pendente');
        // Parcela retroativa nascida quitada: histórico, não dívida.
        $this->expense($group, $this->creditor, [$this->debtor], 80, '2026-09-11', true, 'Nasceu paga', true);

        $descriptions = collect($this->onlyGroup($this->debtor)['my_items'])->pluck('description')->all();

        $this->assertSame(['Pendente'], $descriptions);
    }

    public function test_an_expense_the_user_does_not_take_part_in_is_not_listed(): void
    {
        $third = User::factory()->create(['name' => 'Caio Terceiro']);
        $group = $this->group('Trio', $this->creditor, $this->debtor, $third);
        // Ana e Caio dividem; Beto não participa.
        $this->expense($group, $this->creditor, [$third], 100, '2026-09-10', false, 'Sem o Beto');
        $this->expense($group, $this->creditor, [$this->debtor], 100, '2026-09-11', false, 'Com o Beto');

        $descriptions = collect($this->onlyGroup($this->debtor)['my_items'])->pluck('description')->all();

        $this->assertSame(['Com o Beto'], $descriptions);
    }

    public function test_items_of_an_old_snapshot_without_participant_details_resolve_the_user_by_name(): void
    {
        $group = $this->pairGroup();
        // Outubro, o ciclo vigente, selado num snapshot antigo: as despesas só
        // têm os nomes dos participantes, sem `participantDetails`.
        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-10-01',
            'cycle_end' => '2026-10-31',
            'totals' => ['total' => 100.0, 'paid' => 100.0, 'pending' => 0.0],
            'expenses' => [[
                'id' => 9001,
                'description' => 'Snapshot antigo',
                'date' => '2026-10-05',
                'value' => 100.0,
                'paid' => true,
                'payerName' => 'Ana Credora',
                'participants' => ['Ana Credora', 'Beto Devedor'],
                'isFixed' => false,
                'userPayerId' => $this->creditor->id,
                'userCreatorId' => $this->creditor->id,
            ]],
            'balances' => [],
            'settlements' => [],
            'settled_at' => now(),
        ]);

        $items = $this->onlyGroup($this->debtor)['my_items'];

        $this->assertCount(1, $items);
        $this->assertSame('Snapshot antigo', $items[0]['description']);
        $this->assertSame('debtor', $items[0]['role']);
        // Sem `valuePerPerson` no snapshot, a parte vem de valor ÷ participantes.
        $this->assertEquals(50.0, $items[0]['my_share']);
    }

    // --- can_confirm por status ---------------------------------------------------

    public function test_can_confirm_is_true_in_an_active_manual_closing_and_false_in_the_open_current_cycle(): void
    {
        $group = $this->pairGroup();
        $this->expense($group, $this->creditor, [$this->debtor], 100, '2026-10-10');

        // Vigente e aberto: o acerto existe (ao vivo), mas ainda não pode ser confirmado.
        $open = $this->onlyGroup($this->debtor);
        $this->assertSame('open', $open['cycle']['status']);
        $this->assertSame(0, $open['cycle']['cycles_ago']);
        $this->assertCount(1, $open['to_pay']);
        $this->assertFalse($open['to_pay'][0]['can_confirm']);

        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-10-01',
            'cycle_end' => '2026-10-31',
            'totals' => ['total' => 0, 'paid' => 0, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => now(),
        ]);

        $manual = $this->onlyGroup($this->debtor);
        $this->assertSame('closed_manually', $manual['cycle']['status']);
        $this->assertTrue($manual['to_pay'][0]['can_confirm']);
    }

    public function test_can_confirm_is_false_in_a_sealed_cycle(): void
    {
        $group = $this->pairGroup();
        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-10-01',
            'cycle_end' => '2026-10-31',
            'totals' => ['total' => 100.0, 'paid' => 100.0, 'pending' => 0.0],
            'expenses' => [],
            'balances' => [],
            'settlements' => [['from_user_id' => $this->debtor->id, 'to_user_id' => $this->creditor->id, 'amount' => 50.0]],
            'settled_at' => now(),
        ]);

        $item = $this->onlyGroup($this->debtor);

        $this->assertSame('closed', $item['cycle']['status']);
        $this->assertTrue($item['cycle']['settled']);
        $this->assertCount(1, $item['to_pay']);
        $this->assertFalse($item['to_pay'][0]['can_confirm']);
    }

    // --- has_open, contagem e ordenação ---------------------------------------------

    public function test_groups_with_something_open_come_first_then_by_name_and_up_to_date_groups_have_zero_totals(): void
    {
        $zeta = $this->pairGroup('Zeta');
        $beta = $this->pairGroup('Beta');
        $alfa = $this->group('Alfa', $this->debtor); // sem nenhuma despesa: em dia

        $this->expense($zeta, $this->creditor, [$this->debtor], 100, '2026-09-10');
        $this->expense($beta, $this->creditor, [$this->debtor], 100, '2026-09-10');

        $groups = $this->service()->forUser($this->debtor)['groups'];

        $this->assertSame(['Beta', 'Zeta', 'Alfa'], array_map(fn (array $item) => $item['group']['name'], $groups));
        $this->assertSame([true, true, false], array_map(fn (array $item) => $item['has_open'], $groups));

        $upToDate = $groups[2];
        $this->assertSame($alfa->id, $upToDate['group']['id']);
        $this->assertEquals(['total' => 0.0, 'confirmed' => 0.0, 'open' => 0.0], $upToDate['owe']);
        $this->assertEquals(['total' => 0.0, 'confirmed' => 0.0, 'open' => 0.0], $upToDate['receive']);
        $this->assertSame([], $upToDate['to_pay']);
        $this->assertSame([], $upToDate['to_receive']);
        $this->assertSame([], $upToDate['my_items']);
        $this->assertSame(0, $upToDate['pending_items_count']);
        $this->assertSame(0, $upToDate['cycle']['cycles_ago']);
    }

    public function test_pending_items_count_counts_only_the_unpaid_items(): void
    {
        $group = $this->pairGroup();
        $this->expense($group, $this->creditor, [$this->debtor], 100, '2026-09-10', false, 'A');
        $this->expense($group, $this->creditor, [$this->debtor], 100, '2026-09-11', false, 'B');
        $this->expense($group, $this->creditor, [$this->debtor], 100, '2026-09-12', true, 'C');

        $this->assertSame(2, $this->onlyGroup($this->debtor)['pending_items_count']);
    }

    // --- escopo -----------------------------------------------------------------------

    public function test_a_deleted_group_and_a_group_of_someone_else_never_appear(): void
    {
        $mine = $this->pairGroup('Meu grupo');
        $deleted = $this->pairGroup('Apagado');
        $deleted->update(['deleted' => true]);
        $stranger = User::factory()->create();
        $notMine = $this->group('De outra pessoa', $this->creditor, $stranger);

        $this->expense($mine, $this->creditor, [$this->debtor], 100, '2026-09-10');
        $this->expense($deleted, $this->creditor, [$this->debtor], 100, '2026-09-10');
        $this->expense($notMine, $this->creditor, [$stranger], 100, '2026-09-10');

        $groups = $this->service()->forUser($this->debtor)['groups'];

        $this->assertSame(['Meu grupo'], array_map(fn (array $item) => $item['group']['name'], $groups));
        $this->assertNotContains($notMine->id, array_map(fn (array $item) => $item['group']['id'], $groups));
    }

    // --- orçamento de consultas (TASK-427, achado S1 da revisão de segurança) ----
    // Antes da otimização: 1 grupo vazio = 56 consultas; 20 grupos vazios = 1.082;
    // 1 grupo com pendência recente = 18. Os tetos abaixo são o valor medido
    // depois da TASK-427 com 20% de folga: um teste que falha aqui é uma
    // regressão de custo, não um número a "ajustar".

    public function test_one_empty_group_stays_within_the_query_budget(): void
    {
        $this->group('Vazio', $this->debtor);

        $queries = $this->queriesOf(fn () => $this->service()->forUser($this->debtor));

        // Medido: 8.
        $this->assertLessThanOrEqual(10, $queries, "1 grupo vazio: {$queries} consultas");
    }

    public function test_twenty_empty_groups_stay_within_the_query_budget(): void
    {
        foreach (range(1, 20) as $number) {
            $this->group("Vazio {$number}", $this->debtor);
        }

        $queries = $this->queriesOf(fn () => $this->service()->forUser($this->debtor));

        // Medido: 122 (6 por grupo mais 2 da raiz).
        $this->assertLessThanOrEqual(147, $queries, "20 grupos vazios: {$queries} consultas");
    }

    public function test_a_group_with_a_recent_pending_stays_within_the_query_budget(): void
    {
        $group = $this->pairGroup();
        $this->expense($group, $this->creditor, [$this->debtor], 100, '2026-09-10');

        $queries = $this->queriesOf(fn () => $this->service()->forUser($this->debtor));

        // Medido: 19 (eram 18; a consulta da primeira data custa uma e poupa nada
        // aqui, onde o foco para cedo). Guarda o caso comum contra regressão.
        $this->assertLessThanOrEqual(23, $queries, "1 grupo com pendência: {$queries} consultas");
    }

    // --- teto de grupos (TASK-428, decisão D9) ---------------------------------
    // No máximo 50 grupos, os de menor id, cortados ANTES de calcular: é isso
    // que limita o custo. A ordem "em aberto primeiro" vale dentro dos 50.
    // Os números são literais de propósito: o teto é contrato (specify §2.1).

    /** @return array<int, Group> */
    private function emptyGroups(int $count, string $prefix = 'Vazio'): array
    {
        return array_map(fn (int $number) => $this->group("{$prefix} {$number}", $this->debtor), range(1, $count));
    }

    public function test_fifty_groups_all_come_back_and_are_not_truncated(): void
    {
        $this->emptyGroups(50);

        $result = $this->service()->forUser($this->debtor);

        $this->assertCount(50, $result['groups']);
        $this->assertFalse($result['truncated']);
    }

    public function test_the_fifty_first_group_is_cut_and_the_result_is_truncated(): void
    {
        $groups = $this->emptyGroups(51);
        $newest = end($groups);

        $result = $this->service()->forUser($this->debtor);
        $ids = array_map(fn (array $item) => $item['group']['id'], $result['groups']);

        $this->assertCount(50, $result['groups']);
        $this->assertTrue($result['truncated']);
        // Ficam os 50 mais antigos: o de maior id é o cortado.
        $this->assertNotContains($newest->id, $ids);
        $this->assertEqualsCanonicalizing(array_map(fn (Group $group) => $group->id, array_slice($groups, 0, 50)), $ids);
    }

    public function test_the_cut_happens_before_computing_so_extra_groups_cost_nothing(): void
    {
        $this->emptyGroups(50);
        $withFifty = $this->queriesOf(fn () => $this->service()->forUser($this->debtor));

        $this->emptyGroups(10, 'Extra');
        $withSixty = $this->queriesOf(fn () => $this->service()->forUser($this->debtor));

        $this->assertSame($withFifty, $withSixty, 'os 10 grupos além do teto não podem ser calculados');
    }

    public function test_groups_with_something_open_still_come_first_within_the_fifty(): void
    {
        $this->emptyGroups(49, 'A');
        $pending = $this->group('Z pendente', $this->creditor, $this->debtor); // 50º: entra
        $this->expense($pending, $this->creditor, [$this->debtor], 100, '2026-09-10');
        $this->group('Z cortado', $this->debtor); // 51º: fica de fora

        $result = $this->service()->forUser($this->debtor);

        $this->assertTrue($result['truncated']);
        $this->assertSame('Z pendente', $result['groups'][0]['group']['name']);
        $this->assertTrue($result['groups'][0]['has_open']);
        $this->assertNotContains('Z cortado', array_map(fn (array $item) => $item['group']['name'], $result['groups']));
    }

    public function test_a_user_without_groups_gets_an_empty_list(): void
    {
        $lonely = User::factory()->create(['name' => 'Sem grupo']);

        $result = $this->service()->forUser($lonely);

        $this->assertSame(['id' => $lonely->id, 'name' => 'Sem grupo'], $result['user']);
        $this->assertSame([], $result['groups']);
    }
}
