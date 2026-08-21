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

    public function test_non_member_cannot_update_expense(): void
    {
        $creator = User::factory()->create();
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator);

        $response = $this->withToken($this->tokenFor($outsider))
            ->putJson("/api/expenses/{$expense->id}", ['description' => 'Alterado']);

        $response->assertStatus(404);
    }

    public function test_member_who_is_not_creator_nor_payer_cannot_update_expense(): void
    {
        $creator = User::factory()->create();
        $otherMember = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creator->id, $otherMember->id]);
        $expense = $this->createExpense($group, $creator, $creator);

        $response = $this->withToken($this->tokenFor($otherMember))
            ->putJson("/api/expenses/{$expense->id}", ['description' => 'Alterado']);

        $response->assertStatus(403);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'description' => 'Despesa de teste']);
    }

    public function test_creator_can_update_expense(): void
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['description' => 'Alterado']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'description' => 'Alterado']);
    }

    public function test_payer_can_update_expense(): void
    {
        $creator = User::factory()->create();
        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creator->id, $payer->id]);
        $expense = $this->createExpense($group, $creator, $payer);

        $response = $this->withToken($this->tokenFor($payer))
            ->putJson("/api/expenses/{$expense->id}", ['description' => 'Alterado']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'description' => 'Alterado']);
    }

    public function test_update_ignores_expense_type_installments_and_quotas(): void
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", [
                'expense_type' => 'FIXED',
                'installments' => 5,
                'quotas' => [['date_expected' => '2026-09-01', 'number' => 1, 'paid' => false, 'value_quota' => 20]],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', [
            'id' => $expense->id,
            'expense_type' => 'IN_CASH',
            'installments' => 1,
        ]);
    }

    public function test_update_replaces_payers_list(): void
    {
        $creator = User::factory()->create();
        $newPayer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creator->id, $newPayer->id]);
        $expense = $this->createExpense($group, $creator, $creator);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['payers' => [$newPayer->id]]);

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing([$newPayer->id], $expense->payers()->pluck('ex_users.id')->all());
    }

    public function test_non_member_cannot_destroy_expense(): void
    {
        $creator = User::factory()->create();
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator);

        $response = $this->withToken($this->tokenFor($outsider))
            ->deleteJson("/api/expenses/{$expense->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'deleted' => false]);
    }

    public function test_member_who_is_not_creator_nor_payer_cannot_destroy_expense(): void
    {
        $creator = User::factory()->create();
        $otherMember = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creator->id, $otherMember->id]);
        $expense = $this->createExpense($group, $creator, $creator);

        $response = $this->withToken($this->tokenFor($otherMember))
            ->deleteJson("/api/expenses/{$expense->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'deleted' => false]);
    }

    public function test_creator_can_destroy_expense(): void
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator);

        $response = $this->withToken($this->tokenFor($creator))
            ->deleteJson("/api/expenses/{$expense->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'deleted' => true]);
    }

    public function test_payer_can_destroy_expense(): void
    {
        $creator = User::factory()->create();
        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creator->id, $payer->id]);
        $expense = $this->createExpense($group, $creator, $payer);

        $response = $this->withToken($this->tokenFor($payer))
            ->deleteJson("/api/expenses/{$expense->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'deleted' => true]);
    }
}
