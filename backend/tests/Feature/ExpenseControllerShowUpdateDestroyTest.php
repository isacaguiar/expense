<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseControllerShowUpdateDestroyTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    private function createExpense(Group $group, User $creator, User $payer, array $overrides = []): Expense
    {
        $expense = Expense::create(array_merge([
            'create_date' => now(),
            'date_payment' => '2026-08-15',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $creator->id,
            'user_payer_id' => $payer->id,
            'deleted' => false,
        ], $overrides));

        $expense->payers()->attach($payer->id);

        return $expense;
    }

    public function test_member_can_view_expense(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);
        $expense = $this->createExpense($group, $member, $member);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson("/api/expenses/{$expense->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('id', $expense->id);
        $response->assertJsonStructure(['payers', 'quotas']);
    }

    public function test_non_member_cannot_view_expense(): void
    {
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);
        $expense = $this->createExpense($group, $member, $member);

        $response = $this->withToken($this->tokenFor($outsider))
            ->getJson("/api/expenses/{$expense->id}");

        $response->assertStatus(404);
    }

    public function test_deleted_expense_returns_404(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);
        $expense = $this->createExpense($group, $member, $member, ['deleted' => true]);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson("/api/expenses/{$expense->id}");

        $response->assertStatus(404);
    }
}
