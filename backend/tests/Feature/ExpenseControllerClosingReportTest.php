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
 * GET /api/groups/{groupId}/expenses/closing-report?cycle_start=YYYY-MM-DD: o
 * Relatório de Fechamento de um ciclo (selado, fechado pendente ou vigente).
 * Só lê: nunca sela, nunca grava snapshot, nunca notifica — a diferença
 * deliberada em relação a `summary()`.
 * Ver docs/feature/20261006-relatorios-acompanhamento-fechamento/plan.md §2.
 */
class ExpenseControllerClosingReportTest extends TestCase
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

    private function report(User $user, Group $group, string $cycleStart): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->tokenFor($user))
            ->getJson("/api/groups/{$group->id}/expenses/closing-report?cycle_start={$cycleStart}");
    }

    /** @return array{0: Group, 1: User, 2: User} */
    private function groupWithPair(?int $closingDay = null): array
    {
        $creditor = User::factory()->create(['name' => 'Ana']);
        $debtor = User::factory()->create(['name' => 'Bruno']);
        $group = Group::create(['name' => 'Casa', 'closing_day' => $closingDay]);
        $group->members()->attach([$creditor->id, $debtor->id]);

        return [$group, $creditor, $debtor];
    }

    /**
     * Despesa À Vista de $creditor dividida com $debtors; a Quota vence em $date.
     *
     * @param  array<int, User>  $debtors
     */
    private function expenseBetween(Group $group, User $creditor, array $debtors, float $total, bool $paid, string $date, ?string $note = null, bool $bornPaid = false): Expense
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
            'payment_note' => $note,
        ]);

        return $expense;
    }

    private function confirm(Group $group, User $debtor, User $creditor, float $amount, string $cycleStart, string $cycleEnd, ?string $note = null): SettlementConfirmation
    {
        return SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => $cycleStart,
            'cycle_end' => $cycleEnd,
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'amount' => $amount,
            'proof_path' => 'comprovantes/'.$group->id.'/x.jpg',
            'note' => $note,
            'confirmed_at' => now(),
        ]);
    }

    public function test_a_closed_pending_cycle_is_computed_live_with_the_creditors_breakdown(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $ana, $bruno] = $this->groupWithPair();
        $this->expenseBetween($group, $ana, [$bruno], 200, false, '2026-09-10');
        $this->expenseBetween($group, $bruno, [$ana], 40, true, '2026-09-12');

        $response = $this->report($ana, $group, '2026-09-01')->assertOk();

        $response->assertJsonPath('group.id', $group->id)
            ->assertJsonPath('group.name', 'Casa')
            ->assertJsonPath('cycle.start', '2026-09-01')
            ->assertJsonPath('cycle.end', '2026-09-30')
            ->assertJsonPath('cycle.closes_at', '2026-10-05')
            ->assertJsonPath('cycle.status', 'closed')
            ->assertJsonPath('cycle.settled', false);
        $this->assertEquals(240.0, $response->json('totals.total'));
        $this->assertEquals(200.0, $response->json('totals.pending'));
        $this->assertCount(2, $response->json('expenses'));
        $this->assertCount(2, $response->json('balances'));

        // Bruto: Bruno deve 100 a Ana; Ana deve 20 a Bruno. Líquido: Bruno paga 80 a Ana.
        $creditors = $response->json('creditors');
        $this->assertSame($ana->id, $creditors[0]['creditor']['id']);
        $this->assertEquals(200.0, $creditors[0]['advanced_total']);
        $this->assertEquals(100.0, $creditors[0]['debtors'][0]['amount']);
        $this->assertSame($bruno->id, $creditors[0]['debtors'][0]['id']);
        $this->assertSame($bruno->id, $creditors[1]['creditor']['id']);
        $this->assertEquals(20.0, $creditors[1]['debtors'][0]['amount']);

        $this->assertCount(1, $response->json('settlements'));
        $this->assertEquals(80.0, $response->json('settlements.0.amount'));
        $this->assertNull($response->json('settlements.0.confirmedAt'));
    }

    public function test_the_response_has_exactly_the_documented_top_level_keys(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $ana] = $this->groupWithPair();

        $keys = array_keys($this->report($ana, $group, '2026-09-01')->assertOk()->json());
        sort($keys);

        $this->assertSame(['balances', 'creditors', 'cycle', 'expenses', 'group', 'settlements', 'totals'], $keys);
    }

    public function test_a_sealed_cycle_is_read_from_its_snapshot(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $ana, $bruno] = $this->groupWithPair();
        // No banco, setembro está pendente; a foto selada diz o contrário e é ela que vale.
        $this->expenseBetween($group, $ana, [$bruno], 200, false, '2026-09-10');
        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-09-01',
            'cycle_end' => '2026-09-30',
            'totals' => ['total' => 777.0, 'paid' => 777.0, 'pending' => 0.0],
            'expenses' => [[
                'id' => 99, 'description' => 'Da foto', 'date' => '2026-09-05', 'value' => 777.0,
                'paid' => true, 'bornPaid' => false, 'payerName' => 'Ana', 'userPayerId' => $ana->id,
                'participants' => ['Ana', 'Bruno'],
                'participantDetails' => [
                    ['id' => $ana->id, 'name' => 'Ana', 'avatarUrl' => null],
                    ['id' => $bruno->id, 'name' => 'Bruno', 'avatarUrl' => null],
                ],
            ]],
            'balances' => [],
            'settlements' => [['from_user_id' => $bruno->id, 'to_user_id' => $ana->id, 'amount' => 388.5]],
            'settled_at' => now(),
        ]);
        $this->confirm($group, $bruno, $ana, 388.5, '2026-09-01', '2026-09-30');

        $response = $this->report($bruno, $group, '2026-09-01')->assertOk();

        $response->assertJsonPath('cycle.status', 'closed')->assertJsonPath('cycle.settled', true);
        $this->assertEquals(777.0, $response->json('totals.total'));
        $this->assertSame('Da foto', $response->json('expenses.0.description'));
        $this->assertNotNull($response->json('settlements.0.confirmedAt'));
        $this->assertEquals(388.5, $response->json('creditors.0.debtors.0.amount'));
    }

    public function test_the_open_current_cycle_comes_as_a_preview(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $ana, $bruno] = $this->groupWithPair();
        $this->expenseBetween($group, $ana, [$bruno], 200, false, '2026-10-10');

        $response = $this->report($ana, $group, '2026-10-01')->assertOk();

        $response->assertJsonPath('cycle.status', 'open')->assertJsonPath('cycle.settled', false);
        $this->assertEquals(200.0, $response->json('totals.total'));
        $this->assertEquals(100.0, $response->json('creditors.0.debtors.0.amount'));
    }

    public function test_an_actively_closed_manually_current_cycle_is_reported_as_closed_manually(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $ana, $bruno] = $this->groupWithPair();
        $this->expenseBetween($group, $ana, [$bruno], 200, false, '2026-10-10');
        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-10-01',
            'cycle_end' => '2026-10-31',
            'totals' => ['total' => 0, 'paid' => 0, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => now(),
        ]);

        $this->report($ana, $group, '2026-10-01')
            ->assertOk()
            ->assertJsonPath('cycle.status', 'closed_manually')
            ->assertJsonPath('totals.total', 200);
    }

    public function test_a_future_cycle_is_not_found(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $ana] = $this->groupWithPair();

        $this->report($ana, $group, '2026-11-01')->assertStatus(404);
    }

    public function test_a_date_that_does_not_start_a_cycle_is_not_found(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $ana] = $this->groupWithPair();

        $this->report($ana, $group, '2026-09-15')->assertStatus(404);
        $this->report($ana, $group, '2026-09-30')->assertStatus(404);
    }

    public function test_a_missing_or_malformed_cycle_start_is_rejected(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $ana] = $this->groupWithPair();
        $token = $this->tokenFor($ana);

        $this->withToken($token)->getJson("/api/groups/{$group->id}/expenses/closing-report")
            ->assertStatus(422)->assertJsonValidationErrors('cycle_start');
        foreach (['2026-9-1', '01/09/2026', 'abc', '2026-02-30'] as $bad) {
            $this->withToken($token)->getJson("/api/groups/{$group->id}/expenses/closing-report?cycle_start={$bad}")
                ->assertStatus(422);
        }
    }

    public function test_a_sealed_snapshot_keeps_opening_after_the_closing_day_of_the_group_changed(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $ana, $bruno] = $this->groupWithPair();
        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-09-01',
            'cycle_end' => '2026-09-30',
            'totals' => ['total' => 50.0, 'paid' => 50.0, 'pending' => 0.0],
            'expenses' => [],
            'balances' => [],
            'settlements' => [],
            'settled_at' => now(),
        ]);
        // Com o dia 15, 01/09 deixa de abrir um ciclo: só o snapshot o encontra.
        $group->update(['closing_day' => 15]);

        $this->report($ana, $group, '2026-09-01')
            ->assertOk()
            ->assertJsonPath('cycle.start', '2026-09-01')
            ->assertJsonPath('cycle.end', '2026-09-30')
            ->assertJsonPath('cycle.settled', true);
    }

    public function test_an_old_snapshot_without_participant_details_user_payer_id_and_born_paid_still_opens(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $ana, $bruno] = $this->groupWithPair();
        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-09-01',
            'cycle_end' => '2026-09-30',
            'totals' => ['total' => 90.0, 'paid' => 90.0, 'pending' => 0.0],
            // Formato anterior aos campos aditivos: sem participantDetails, userPayerId, bornPaid, paymentNote.
            'expenses' => [[
                'id' => 7, 'description' => 'Antiga', 'date' => '2026-09-03', 'value' => 90.0,
                'valuePerPerson' => 45.0, 'paid' => true, 'payerName' => 'Ana',
                'participants' => ['Ana', 'Bruno'],
            ]],
            'balances' => [],
            'settlements' => [['from_user_id' => $bruno->id, 'to_user_id' => $ana->id, 'amount' => 45.0]],
            'settled_at' => now(),
        ]);

        $response = $this->report($bruno, $group, '2026-09-01')->assertOk();

        $this->assertSame($ana->id, $response->json('creditors.0.creditor.id'));
        $this->assertSame($bruno->id, $response->json('creditors.0.debtors.0.id'));
        $this->assertEquals(45.0, $response->json('creditors.0.debtors.0.amount'));
    }

    public function test_a_born_paid_entry_is_listed_but_does_not_enter_the_creditors_breakdown(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $ana, $bruno] = $this->groupWithPair();
        $this->expenseBetween($group, $ana, [$bruno], 200, false, '2026-09-10');
        $this->expenseBetween($group, $ana, [$bruno], 500, true, '2026-09-11', null, true);

        $response = $this->report($ana, $group, '2026-09-01')->assertOk();

        $this->assertCount(2, $response->json('expenses'));
        $this->assertEquals(200.0, $response->json('creditors.0.advanced_total'));
        $this->assertEquals(100.0, $response->json('creditors.0.debtors.0.amount'));
    }

    public function test_the_payment_notes_of_the_expense_and_of_the_settlement_come_through(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $ana, $bruno] = $this->groupWithPair();
        $this->expenseBetween($group, $ana, [$bruno], 200, true, '2026-09-10', 'Paguei por fora, no débito');
        $this->confirm($group, $bruno, $ana, 100, '2026-09-01', '2026-09-30', 'Pix do Bruno de 12/09');

        $response = $this->report($ana, $group, '2026-09-01')->assertOk();

        $response->assertJsonPath('expenses.0.paymentNote', 'Paguei por fora, no débito')
            ->assertJsonPath('settlements.0.confirmedNote', 'Pix do Bruno de 12/09');
        $this->assertNotNull($response->json('settlements.0.confirmedAt'));
    }

    public function test_gross_debts_reconcile_with_the_net_settlements_pair_by_pair(): void
    {
        Carbon::setTestNow('2026-10-15');

        $ana = User::factory()->create(['name' => 'Ana']);
        $bruno = User::factory()->create(['name' => 'Bruno']);
        $carla = User::factory()->create(['name' => 'Carla']);
        $group = Group::create(['name' => 'Casa']);
        $group->members()->attach([$ana->id, $bruno->id, $carla->id]);

        // Valores que não dividem exato por 3, em ida e volta, para exercitar o arredondamento.
        $this->expenseBetween($group, $ana, [$bruno, $carla], 100, false, '2026-09-02');
        $this->expenseBetween($group, $ana, [$bruno, $carla], 100, false, '2026-09-03');
        $this->expenseBetween($group, $bruno, [$ana, $carla], 70.10, false, '2026-09-04');
        $this->expenseBetween($group, $carla, [$ana], 33.33, false, '2026-09-05');
        $this->expenseBetween($group, $carla, [$bruno, $ana], 50, false, '2026-09-06');

        $response = $this->report($ana, $group, '2026-09-01')->assertOk();

        $owed = [];
        foreach ($response->json('creditors') as $row) {
            foreach ($row['debtors'] as $debtor) {
                $owed[$row['creditor']['id']][$debtor['id']] = $debtor['amount'];
            }
        }
        $net = [];
        foreach ($response->json('settlements') as $settlement) {
            $net[$settlement['to_user_id']][$settlement['from_user_id']] = $settlement['amount'];
        }

        $ids = [$ana->id, $bruno->id, $carla->id];
        $checked = 0;
        foreach ($ids as $a) {
            foreach ($ids as $b) {
                if ($a >= $b) {
                    continue;
                }
                // bruto(b→a) − bruto(a→b), com o sinal de quem recebe, contra o líquido do par.
                $gross = ($owed[$a][$b] ?? 0) - ($owed[$b][$a] ?? 0);
                $settled = ($net[$a][$b] ?? 0) - ($net[$b][$a] ?? 0);
                $this->assertEqualsWithDelta($gross, $settled, 0.011, "Par {$a}/{$b}");
                $checked++;
            }
        }
        $this->assertSame(3, $checked);
    }

    public function test_the_report_never_seals_creates_a_snapshot_or_notifies(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $ana, $bruno] = $this->groupWithPair();
        // Setembro todo pago e com o acerto confirmado, sem snapshot: o ciclo que
        // `summary()` selaria na leitura.
        $this->expenseBetween($group, $ana, [$bruno], 200, true, '2026-09-10');
        $this->confirm($group, $bruno, $ana, 100, '2026-09-01', '2026-09-30');

        $snapshotsBefore = GroupCycleSnapshot::count();
        $notificationsBefore = Notification::count();
        $confirmationsBefore = SettlementConfirmation::count();

        $this->report($ana, $group, '2026-09-01')
            ->assertOk()
            ->assertJsonPath('cycle.settled', false);

        $this->assertSame($snapshotsBefore, GroupCycleSnapshot::count());
        $this->assertSame($notificationsBefore, Notification::count());
        $this->assertSame($confirmationsBefore, SettlementConfirmation::count());

        // Controle: a mesma situação lida por GET /summary sela o ciclo.
        $this->withToken($this->tokenFor($ana))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1")
            ->assertOk()
            ->assertJsonPath('cycle.settled', true);
        $this->assertSame($snapshotsBefore + 1, GroupCycleSnapshot::count());
    }

    public function test_it_requires_authentication(): void
    {
        [$group] = $this->groupWithPair();

        $this->getJson("/api/groups/{$group->id}/expenses/closing-report?cycle_start=2026-09-01")->assertStatus(401);
    }

    public function test_a_user_who_is_not_a_member_gets_not_found_even_with_a_bad_cycle_start(): void
    {
        Carbon::setTestNow('2026-10-15');

        [$group, $ana, $bruno] = $this->groupWithPair();
        $this->expenseBetween($group, $ana, [$bruno], 200, false, '2026-09-10');
        $outsider = User::factory()->create();

        $valid = $this->report($outsider, $group, '2026-09-01');
        $valid->assertStatus(404);
        $this->assertStringNotContainsString('Despesa de teste', $valid->getContent());

        // A checagem de membro vem antes da validação: quem é de fora não descobre nada por um 422.
        $this->report($outsider, $group, 'abc')->assertStatus(404);
    }
}
