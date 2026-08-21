<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseControllerIndexByGroupTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    private function createExpense(Group $group, User $creator, User $payer, string $datePayment, string $expenseType = 'IN_CASH', ?string $fixedRecurrenceEndsAt = null): Expense
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
            'user_payer_id' => $payer->id,
            'deleted' => false,
            'fixed_recurrence_ends_at' => $fixedRecurrenceEndsAt,
        ]);
    }

    public function test_member_receives_expenses_for_the_requested_month(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $expense = $this->createExpense($group, $member, $member, '2026-03-10');
        $expense->payers()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson("/api/groups/{$group->id}/expenses?year=2026&month=3");

        $response->assertStatus(200)->assertJsonFragment([
            'id' => $expense->id,
            'description' => 'Despesa de teste',
            'payerName' => $member->name,
        ]);
    }

    public function test_member_does_not_receive_expenses_outside_the_requested_month(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $this->createExpense($group, $member, $member, '2026-03-10');

        $response = $this->withToken($this->tokenFor($member))
            ->getJson("/api/groups/{$group->id}/expenses?year=2026&month=4");

        $response->assertStatus(200)->assertJsonCount(0);
    }

    public function test_non_member_cannot_view_group_expenses(): void
    {
        $outsider = User::factory()->create();
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $this->createExpense($group, $member, $member, '2026-03-10');

        $response = $this->withToken($this->tokenFor($outsider))
            ->getJson("/api/groups/{$group->id}/expenses?year=2026&month=3");

        $response->assertStatus(404);
    }

    public function test_year_and_month_are_required(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson("/api/groups/{$group->id}/expenses");

        $response->assertStatus(422);
    }

    public function test_fixed_expense_appears_in_months_after_creation(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $expense = $this->createExpense($group, $member, $member, '2026-03-10', 'FIXED');
        $expense->payers()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson("/api/groups/{$group->id}/expenses?year=2026&month=5");

        $response->assertStatus(200)->assertJsonFragment([
            'id' => $expense->id,
            'date' => '2026-05-10',
            'isFixed' => true,
        ]);
    }

    public function test_fixed_expense_disappears_from_cutoff_month_onward(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $expense = $this->createExpense($group, $member, $member, '2026-03-10', 'FIXED', '2026-06-01');
        $expense->payers()->attach($member->id);

        $stillActive = $this->withToken($this->tokenFor($member))
            ->getJson("/api/groups/{$group->id}/expenses?year=2026&month=5");
        $stillActive->assertStatus(200)->assertJsonFragment(['id' => $expense->id]);

        $cutoffMonth = $this->withToken($this->tokenFor($member))
            ->getJson("/api/groups/{$group->id}/expenses?year=2026&month=6");
        $cutoffMonth->assertStatus(200)->assertJsonMissing(['id' => $expense->id]);

        $afterCutoff = $this->withToken($this->tokenFor($member))
            ->getJson("/api/groups/{$group->id}/expenses?year=2026&month=7");
        $afterCutoff->assertStatus(200)->assertJsonMissing(['id' => $expense->id]);
    }

    public function test_fixed_expense_disappears_from_its_own_creation_month_when_cutoff_is_that_month(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $expense = $this->createExpense($group, $member, $member, '2026-03-10', 'FIXED', '2026-03-01');
        $expense->payers()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson("/api/groups/{$group->id}/expenses?year=2026&month=3");

        $response->assertStatus(200)->assertJsonMissing(['id' => $expense->id]);
    }
}
