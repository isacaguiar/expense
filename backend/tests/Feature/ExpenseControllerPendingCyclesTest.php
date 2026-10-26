<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Notification;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * GET /api/groups/{groupId}/expenses/pending-cycles: a aba Acompanhamento de
 * Relatórios — ciclos encerrados (ou em carência) que ainda aguardam quitação.
 * Só lê: nunca sela, nunca grava snapshot, nunca notifica.
 * Ver docs/feature/concluidas/202610/20261006-relatorios-acompanhamento-fechamento/plan.md §1.
 */
class ExpenseControllerPendingCyclesTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    /** @return array<int, mixed> */
    private function pendingCycles(User $user, Group $group): array
    {
        return $this->withToken($this->tokenFor($user))
            ->getJson("/api/groups/{$group->id}/expenses/pending-cycles")
            ->assertOk()
            ->json('data');
    }

    /** @return array{0: Group, 1: User, 2: User} */
    private function groupWithPair(?int $closingDay = null): array
    {
        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo', 'closing_day' => $closingDay]);
        $group->members()->attach([$creditor->id, $debtor->id]);

        return [$group, $creditor, $debtor];
    }

    /**
     * Despesa À Vista de $creditor dividida com $debtors; a Quota vence em $date.
     *
     * @param  array<int, User>  $debtors
     */
    private function expenseBetween(Group $group, User $creditor, array $debtors, float $total, bool $paid, string $date, bool $bornPaid = false): Expense
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
            'born_paid' => $bornPaid,
            'value_quota' => $total,
        ]);

        return $expense;
    }

    private function confirm(Group $group, User $debtor, User $creditor, float $amount, string $cycleStart, string $cycleEnd): SettlementConfirmation
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

    public function test_a_group_with_nothing_to_follow_returns_an_empty_list(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $creditor] = $this->groupWithPair();

        $this->assertSame([], $this->pendingCycles($creditor, $group));
    }

    public function test_a_closed_cycle_with_pending_expenses_is_listed_with_its_counters(): void
    {
        Carbon::setTestNow('2026-10-15');

        $creditor = User::factory()->create();
        $debtorA = User::factory()->create();
        $debtorB = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach([$creditor->id, $debtorA->id, $debtorB->id]);

        // 300 pendente (cada devedor deve 100) + 90 já paga (cada devedor deve 30).
        $this->expenseBetween($group, $creditor, [$debtorA, $debtorB], 300, false, '2026-09-10');
        $this->expenseBetween($group, $creditor, [$debtorA, $debtorB], 90, true, '2026-09-12');
        $this->confirm($group, $debtorA, $creditor, 130, '2026-09-01', '2026-09-30');

        $data = $this->pendingCycles($creditor, $group);

        $this->assertCount(1, $data);
        $this->assertSame([
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'closes_at' => '2026-10-05',
            'status' => 'closed',
            'settled' => false,
            'in_grace' => false,
        ], $data[0]['cycle']);
        $this->assertEquals(['total' => 390.0, 'paid' => 90.0, 'pending' => 300.0], $data[0]['totals']);
        $this->assertSame(2, $data[0]['settlements_total']);
        $this->assertSame(1, $data[0]['settlements_confirmed']);
        $this->assertSame(1, $data[0]['pending_expenses_count']);
        $this->assertFalse($data[0]['awaiting_seal']);
    }

    public function test_an_item_has_exactly_the_documented_keys(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $creditor, $debtor] = $this->groupWithPair();
        $this->expenseBetween($group, $creditor, [$debtor], 200, false, '2026-09-10');

        $item = $this->pendingCycles($creditor, $group)[0];

        $keys = array_keys($item);
        sort($keys);
        $this->assertSame(
            ['awaiting_seal', 'cycle', 'pending_expenses_count', 'settlements_confirmed', 'settlements_total', 'totals'],
            $keys
        );
    }

    public function test_a_cycle_in_its_grace_window_is_listed_as_open_with_the_closing_date(): void
    {
        // Setembro terminou em 30/09 e só fecha em 05/10: em 02/10 ainda está `open`, em carência.
        Carbon::setTestNow('2026-10-02');

        [$group, $creditor, $debtor] = $this->groupWithPair();
        $this->expenseBetween($group, $creditor, [$debtor], 200, false, '2026-09-10');
        $this->expenseBetween($group, $creditor, [$debtor], 80, false, '2026-10-01'); // vigente: fora da lista

        $data = $this->pendingCycles($creditor, $group);

        $this->assertCount(1, $data);
        $this->assertSame('2026-09-01', $data[0]['cycle']['start']);
        $this->assertSame('open', $data[0]['cycle']['status']);
        $this->assertTrue($data[0]['cycle']['in_grace']);
        $this->assertSame('2026-10-05', $data[0]['cycle']['closes_at']);
    }

    public function test_an_actively_closed_manually_current_cycle_is_listed(): void
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

        $data = $this->pendingCycles($creditor, $group);

        $this->assertCount(1, $data);
        $this->assertSame('2026-10-01', $data[0]['cycle']['start']);
        $this->assertSame('closed_manually', $data[0]['cycle']['status']);
        $this->assertFalse($data[0]['cycle']['in_grace']);
        $this->assertEquals(200.0, $data[0]['totals']['pending']);
    }

    public function test_the_open_current_cycle_is_never_listed(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $creditor, $debtor] = $this->groupWithPair();
        $this->expenseBetween($group, $creditor, [$debtor], 200, false, '2026-10-10');

        $this->assertSame([], $this->pendingCycles($creditor, $group));
    }

    public function test_a_reopened_manual_closing_of_the_current_cycle_is_not_listed(): void
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
            'closed_manually_at' => now()->subMinute(),
            'reopened_at' => now(),
        ]);

        $this->assertSame([], $this->pendingCycles($creditor, $group));
    }

    public function test_a_sealed_cycle_is_not_listed(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $creditor, $debtor] = $this->groupWithPair();
        // Setembro selado, com uma despesa real e um acerto confirmado na foto: não é
        // vazio, então só o selo o tira da lista (ele vai para o Histórico, não para cá).
        // No banco a despesa segue pendente, para provar que a foto vale, não o cálculo ao vivo.
        $this->expenseBetween($group, $creditor, [$debtor], 200, false, '2026-09-10');
        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-09-01',
            'cycle_end' => '2026-09-30',
            'totals' => ['total' => 200, 'paid' => 200, 'pending' => 0],
            'expenses' => [['id' => 1, 'value' => 200.0, 'paid' => true, 'bornPaid' => false]],
            'balances' => [],
            'settlements' => [['from_user_id' => $debtor->id, 'to_user_id' => $creditor->id, 'amount' => 100.0]],
            'settled_at' => now(),
        ]);
        $this->confirm($group, $debtor, $creditor, 100, '2026-09-01', '2026-09-30');

        $this->assertSame([], $this->pendingCycles($creditor, $group));
    }

    public function test_an_empty_cycle_and_a_cycle_with_only_a_born_paid_entry_are_not_listed(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $creditor, $debtor] = $this->groupWithPair();
        // Julho: só uma parcela retroativa nascida quitada (registro histórico, não dívida).
        $this->expenseBetween($group, $creditor, [$debtor], 200, true, '2026-07-10', true);
        // Agosto: nenhuma entrada. Junho: uma pendência real, para provar que a varredura passa pelos vazios.
        $this->expenseBetween($group, $creditor, [$debtor], 60, false, '2026-06-10');

        $data = $this->pendingCycles($creditor, $group);

        $this->assertCount(1, $data);
        $this->assertSame('2026-06-01', $data[0]['cycle']['start']);
    }

    public function test_a_settled_cycle_without_a_seal_is_listed_as_awaiting_seal_after_the_last_pending_expense_is_deleted_in_grace(): void
    {
        // O caminho (a) do plan.md §0.1.2: na carência o ciclo ainda é `open`, então
        // destroy() deixa excluir, e destroy() não sela.
        Carbon::setTestNow('2026-10-02');

        [$group, $creditor, $debtor] = $this->groupWithPair();
        $this->expenseBetween($group, $creditor, [$debtor], 200, true, '2026-09-10');
        $pending = $this->expenseBetween($group, $creditor, [$debtor], 100, false, '2026-09-12');
        $this->confirm($group, $debtor, $creditor, 100, '2026-09-01', '2026-09-30');

        $this->withToken($this->tokenFor($creditor))
            ->deleteJson("/api/expenses/{$pending->id}")
            ->assertOk();

        $this->assertSame(0, GroupCycleSnapshot::where('group_id', $group->id)->count(), 'destroy() não sela.');

        Carbon::setTestNow('2026-10-15');

        $data = $this->pendingCycles($creditor, $group);

        $this->assertCount(1, $data);
        $this->assertSame('2026-09-01', $data[0]['cycle']['start']);
        $this->assertSame('closed', $data[0]['cycle']['status']);
        $this->assertTrue($data[0]['awaiting_seal']);
        $this->assertSame(0, $data[0]['pending_expenses_count']);
        $this->assertSame(1, $data[0]['settlements_total']);
        $this->assertSame(1, $data[0]['settlements_confirmed']);
    }

    public function test_cycles_come_most_recent_first_and_the_scan_stops_after_thirteen_cycles(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $creditor, $debtor] = $this->groupWithPair();

        // Uma pendência por mês de outubro/2026 (vigente) até setembro/2025 (13 ciclos atrás).
        for ($ago = 0; $ago <= 13; $ago++) {
            $month = Carbon::parse('2026-10-10')->subMonths($ago);
            $this->expenseBetween($group, $creditor, [$debtor], 10 + $ago, false, $month->toDateString());
        }
        // O vigente só entra se estiver fechado manualmente.
        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-10-01',
            'cycle_end' => '2026-10-31',
            'totals' => ['total' => 0, 'paid' => 0, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => now(),
        ]);

        $data = $this->pendingCycles($creditor, $group);

        $this->assertCount(13, $data);
        $starts = array_map(fn (array $item) => $item['cycle']['start'], $data);
        $this->assertSame('2026-10-01', $starts[0]);
        $this->assertSame('2025-10-01', $starts[12], 'O 13º ciclo (ago=12) é o último; setembro/2025 (ago=13) fica de fora.');
        $sorted = $starts;
        rsort($sorted);
        $this->assertSame($sorted, $starts);
    }

    public function test_the_boundaries_follow_the_closing_day_of_the_group(): void
    {
        Carbon::setTestNow('2026-10-20');

        [$group, $creditor, $debtor] = $this->groupWithPair(15);
        // Com o dia 15, o ciclo que contém 01/09 vai de 16/08 a 15/09 e fecha em 20/09.
        $this->expenseBetween($group, $creditor, [$debtor], 200, false, '2026-09-01');

        $data = $this->pendingCycles($creditor, $group);

        $this->assertCount(1, $data);
        $this->assertSame('2026-08-16', $data[0]['cycle']['start']);
        $this->assertSame('2026-09-15', $data[0]['cycle']['end']);
        $this->assertSame('2026-09-20', $data[0]['cycle']['closes_at']);
        $this->assertSame('closed', $data[0]['cycle']['status']);
    }

    public function test_the_listing_never_seals_creates_a_snapshot_or_notifies(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $creditor, $debtor] = $this->groupWithPair();
        // Setembro todo pago e com o acerto confirmado, mas sem snapshot: o ciclo
        // que `summary()` selaria na leitura. Agosto segue pendente.
        $this->expenseBetween($group, $creditor, [$debtor], 200, true, '2026-09-10');
        $this->confirm($group, $debtor, $creditor, 100, '2026-09-01', '2026-09-30');
        $this->expenseBetween($group, $creditor, [$debtor], 60, false, '2026-08-10');

        $snapshotsBefore = GroupCycleSnapshot::count();
        $notificationsBefore = Notification::count();
        $confirmationsBefore = SettlementConfirmation::count();

        $data = $this->pendingCycles($creditor, $group);

        $this->assertSame(['2026-09-01', '2026-08-01'], array_map(fn (array $item) => $item['cycle']['start'], $data));
        $this->assertTrue($data[0]['awaiting_seal']);
        $this->assertSame($snapshotsBefore, GroupCycleSnapshot::count());
        $this->assertSame($notificationsBefore, Notification::count());
        $this->assertSame($confirmationsBefore, SettlementConfirmation::count());
    }

    public function test_it_requires_authentication(): void
    {
        [$group] = $this->groupWithPair();

        $this->getJson("/api/groups/{$group->id}/expenses/pending-cycles")->assertStatus(401);
    }

    public function test_a_user_who_is_not_a_member_gets_not_found(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $creditor, $debtor] = $this->groupWithPair();
        $this->expenseBetween($group, $creditor, [$debtor], 200, false, '2026-09-10');
        $outsider = User::factory()->create();

        $response = $this->withToken($this->tokenFor($outsider))
            ->getJson("/api/groups/{$group->id}/expenses/pending-cycles");

        $response->assertStatus(404);
        $this->assertStringNotContainsString('2026-09-01', $response->getContent());
    }

    public function test_a_confirmation_from_another_group_of_the_same_members_does_not_settle_this_cycle(): void
    {
        Carbon::setTestNow('2026-10-15');

        // Grupo A: tudo pago, mas o acerto Bruno -> Ana ainda NÃO foi confirmado.
        [$groupA, $ana, $bruno] = $this->groupWithPair();
        $this->expenseBetween($groupA, $ana, [$bruno], 200, true, '2026-09-10');

        // Grupo B: os mesmos dois membros, o mesmo ciclo, e lá Bruno já confirmou o acerto.
        $groupB = Group::create(['name' => 'Outro grupo']);
        $groupB->members()->attach([$ana->id, $bruno->id]);
        $this->expenseBetween($groupB, $ana, [$bruno], 200, true, '2026-09-10');
        $this->confirm($groupB, $bruno, $ana, 100, '2026-09-01', '2026-09-30');

        $dataA = $this->pendingCycles($ana, $groupA);

        $this->assertCount(1, $dataA);
        $this->assertFalse($dataA[0]['awaiting_seal'], 'A confirmação do outro grupo não quita este ciclo.');
        $this->assertSame(1, $dataA[0]['settlements_total']);
        $this->assertSame(0, $dataA[0]['settlements_confirmed']);

        // Controle: no grupo B a mesma confirmação conta.
        $dataB = $this->pendingCycles($ana, $groupB);

        $this->assertCount(1, $dataB);
        $this->assertTrue($dataB[0]['awaiting_seal']);
        $this->assertSame(1, $dataB[0]['settlements_confirmed']);
    }
}
