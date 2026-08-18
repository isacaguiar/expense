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
}
