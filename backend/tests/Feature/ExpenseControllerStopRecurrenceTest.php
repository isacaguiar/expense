<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseControllerStopRecurrenceTest extends TestCase
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

    private function createExpense(Group $group, User $creator, string $expenseType, string $datePayment): Expense
    {
        return Expense::create([
            'create_date' => now(),
            'date_payment' => $datePayment,
            'description' => 'Despesa de teste',
            'expense_type' => $expenseType,
            'installments' => 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $creator->id,
            'user_payer_id' => $creator->id,
            'deleted' => false,
        ]);
    }

    public function test_member_can_stop_recurrence_of_fixed_expense(): void
    {
        Carbon::setTestNow('2026-03-15');

        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $expense = $this->createExpense($group, $member, 'FIXED', '2026-03-10');

        $response = $this->withToken($this->tokenFor($member))
            ->postJson("/api/expenses/{$expense->id}/stop-recurrence", ['year' => 2026, 'month' => 6]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', [
            'id' => $expense->id,
            'fixed_recurrence_ends_at' => '2026-06-01',
        ]);
    }

    public function test_cannot_stop_recurrence_with_cutoff_in_a_closed_cycle(): void
    {
        Carbon::setTestNow('2026-08-19');

        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $expense = $this->createExpense($group, $member, 'FIXED', '2026-03-10');

        $response = $this->withToken($this->tokenFor($member))
            ->postJson("/api/expenses/{$expense->id}/stop-recurrence", ['year' => 2026, 'month' => 6]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'fixed_recurrence_ends_at' => null]);
    }

    public function test_non_member_cannot_stop_recurrence(): void
    {
        $outsider = User::factory()->create();
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $expense = $this->createExpense($group, $member, 'FIXED', '2026-03-10');

        $response = $this->withToken($this->tokenFor($outsider))
            ->postJson("/api/expenses/{$expense->id}/stop-recurrence", ['year' => 2026, 'month' => 6]);

        $response->assertStatus(404);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'fixed_recurrence_ends_at' => null]);
    }

    public function test_cannot_stop_recurrence_of_non_fixed_expense(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $expense = $this->createExpense($group, $member, 'IN_CASH', '2026-03-10');

        $response = $this->withToken($this->tokenFor($member))
            ->postJson("/api/expenses/{$expense->id}/stop-recurrence", ['year' => 2026, 'month' => 6]);

        $response->assertStatus(422);
    }

    public function test_cutoff_before_creation_month_is_rejected(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $expense = $this->createExpense($group, $member, 'FIXED', '2026-03-10');

        $response = $this->withToken($this->tokenFor($member))
            ->postJson("/api/expenses/{$expense->id}/stop-recurrence", ['year' => 2026, 'month' => 1]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'fixed_recurrence_ends_at' => null]);
    }
}
