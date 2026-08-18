<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseControllerGetMonthlyExpensesTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    private function createExpense(Group $group, User $creator, User $payer, string $datePayment): Expense
    {
        return Expense::create([
            'create_date' => now(),
            'date_payment' => $datePayment,
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $creator->id,
            'user_payer_id' => $payer->id,
            'deleted' => false,
        ]);
    }

    public function test_member_can_view_monthly_expenses(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $this->createExpense($group, $member, $member, '2026-03-10');

        $response = $this->withToken($this->tokenFor($member))
            ->getJson("/api/groups/{$group->id}/expenses/monthly");

        $response->assertStatus(200)->assertJsonFragment([
            'year' => 2026,
            'month' => 3,
            'total_expenses' => 1,
        ]);
    }

    public function test_non_member_cannot_view_monthly_expenses(): void
    {
        $outsider = User::factory()->create();
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $this->createExpense($group, $member, $member, '2026-03-10');

        $response = $this->withToken($this->tokenFor($outsider))
            ->getJson("/api/groups/{$group->id}/expenses/monthly");

        $response->assertStatus(404);
    }
}
