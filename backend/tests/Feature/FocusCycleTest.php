<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `GET /api/groups/{groupId}/expenses/focus-cycle` — em qual competência o app
 * abre o grupo: o ciclo fechado mais recente ainda com pendência, ou 0.
 * Ver docs/feature/concluidas/202609/20260902-pagamento-ciclo-fechado/plan.md §4.
 */
class FocusCycleTest extends TestCase
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

    public function test_returns_the_most_recent_unsettled_closed_cycle(): void
    {
        Carbon::setTestNow('2026-10-15'); // outubro é a competência vigente

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach($creditor->id);

        // Conta não paga em agosto — 2 ciclos atrás, fechado por data.
        $this->unpaidExpense($group, $creditor, '2026-08-10');

        $this->withToken($this->tokenFor($creditor))
            ->getJson("/api/groups/{$group->id}/expenses/focus-cycle")
            ->assertStatus(200)
            ->assertJsonPath('cycles_ago', 2);
    }

    public function test_returns_zero_when_no_closed_cycle_has_a_pending_item(): void
    {
        Carbon::setTestNow('2026-10-15');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach($creditor->id);

        $this->withToken($this->tokenFor($creditor))
            ->getJson("/api/groups/{$group->id}/expenses/focus-cycle")
            ->assertStatus(200)
            ->assertJsonPath('cycles_ago', 0);
    }

    public function test_a_sealed_cycle_is_skipped_even_if_it_has_unpaid_entries(): void
    {
        Carbon::setTestNow('2026-10-15');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach($creditor->id);

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

        $this->withToken($this->tokenFor($creditor))
            ->getJson("/api/groups/{$group->id}/expenses/focus-cycle")
            ->assertStatus(200)
            ->assertJsonPath('cycles_ago', 0);
    }

    public function test_a_manually_closed_current_cycle_with_a_pending_item_returns_zero(): void
    {
        Carbon::setTestNow('2026-10-15');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach($creditor->id);

        $this->unpaidExpense($group, $creditor, '2026-10-10');

        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-10-01',
            'cycle_end' => '2026-10-31',
            'totals' => ['total' => 100, 'paid' => 0, 'pending' => 100],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => now(),
        ]);

        $this->withToken($this->tokenFor($creditor))
            ->getJson("/api/groups/{$group->id}/expenses/focus-cycle")
            ->assertStatus(200)
            ->assertJsonPath('cycles_ago', 0);
    }

    public function test_returns_a_cycle_in_its_grace_window_that_still_has_a_pending_item(): void
    {
        // closing_day null: o ciclo de janeiro tem fronteira em 31/01 e só fecha
        // em 05/02. Em 02/02 ele está na carência (ainda `open`) — mas com conta
        // em aberto a Home deve abrir nele.
        Carbon::setTestNow('2026-02-02');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach($creditor->id);

        $this->unpaidExpense($group, $creditor, '2026-01-10');

        $this->withToken($this->tokenFor($creditor))
            ->getJson("/api/groups/{$group->id}/expenses/focus-cycle")
            ->assertStatus(200)
            ->assertJsonPath('cycles_ago', 1);
    }

    public function test_ignores_a_grace_window_cycle_once_it_is_settled(): void
    {
        Carbon::setTestNow('2026-02-02');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach($creditor->id);

        $expense = $this->unpaidExpense($group, $creditor, '2026-01-10');
        $expense->quotas()->update(['paid' => true]);

        $this->withToken($this->tokenFor($creditor))
            ->getJson("/api/groups/{$group->id}/expenses/focus-cycle")
            ->assertStatus(200)
            ->assertJsonPath('cycles_ago', 0);
    }

    /**
     * TASK-001 de docs/feature/20260904-parcela-retroativa-contabilizacao/: sem
     * o filtro por born_paid em computeCycleSummary(), uma parcela retroativa
     * compartilhada gerava um settlement fantasma em junho (closed, não selado)
     * e o focus-cycle ficava preso lá — mesmo a parcela sendo `paid = true`.
     */
    public function test_retroactive_shared_installment_does_not_drag_home_back(): void
    {
        Carbon::setTestNow('2026-09-20');

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-06-10',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 1,
            'total_value' => 200,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);
        $expense->payers()->sync([$creditor->id, $debtor->id]);
        $expense->quotas()->create([
            'date_expected' => '2026-06-10',
            'number' => 1,
            'paid' => true,
            'paid_at' => now(),
            'paid_by' => $creditor->id,
            'born_paid' => true,
            'value_quota' => 200,
        ]);

        $this->withToken($this->tokenFor($creditor))
            ->getJson("/api/groups/{$group->id}/expenses/focus-cycle")
            ->assertStatus(200)
            ->assertJsonPath('cycles_ago', 0);
    }

    public function test_non_member_cannot_query_focus_cycle(): void
    {
        $outsider = User::factory()->create();
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach($member->id);

        $this->withToken($this->tokenFor($outsider))
            ->getJson("/api/groups/{$group->id}/expenses/focus-cycle")
            ->assertStatus(404);
    }
}
