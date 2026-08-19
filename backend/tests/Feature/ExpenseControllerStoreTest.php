<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseControllerStoreTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    private function payloadFor(Group $group, User $payer, array $overrides = []): array
    {
        return array_merge([
            'date_payment' => '2026-08-15',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $payer->id,
            'user_payer_id' => $payer->id,
            'payers' => [$payer->id],
            'quotas' => [[
                'date_expected' => '2026-08-15',
                'number' => 1,
                'paid' => true,
                'value_quota' => 100,
            ]],
        ], $overrides);
    }

    public function test_member_can_create_expense_in_own_group(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member));

        $response->assertStatus(201);
        $this->assertDatabaseHas('ex_expenses', [
            'group_id' => $group->id,
            'description' => 'Despesa de teste',
        ]);
    }

    public function test_non_member_cannot_create_expense_in_group(): void
    {
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);

        $response = $this->withToken($this->tokenFor($outsider))
            ->postJson('/api/expenses', $this->payloadFor($group, $outsider));

        $response->assertStatus(404);
        $this->assertDatabaseMissing('ex_expenses', ['group_id' => $group->id]);
    }

    public function test_payer_must_be_member_of_group(): void
    {
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, [
                'user_payer_id' => $outsider->id,
                'payers' => [$outsider->id],
            ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('ex_expenses', ['group_id' => $group->id]);
    }

    public function test_all_payers_must_be_members_of_group(): void
    {
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, [
                'payers' => [$member->id, $outsider->id],
            ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('ex_expenses', ['group_id' => $group->id]);
    }

    public function test_user_creator_id_is_always_the_authenticated_user(): void
    {
        $member = User::factory()->create();
        $spoofedCreator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, [
                'user_creator_id' => $spoofedCreator->id,
            ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('ex_expenses', [
            'group_id' => $group->id,
            'user_creator_id' => $member->id,
        ]);
        $this->assertDatabaseMissing('ex_expenses', [
            'group_id' => $group->id,
            'user_creator_id' => $spoofedCreator->id,
        ]);
    }

    public function test_member_can_create_installments_expense_with_multiple_quotas(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 3,
                'total_value' => 300,
                'quotas' => [
                    ['date_expected' => '2026-08-15', 'number' => 1, 'paid' => false, 'value_quota' => 100],
                    ['date_expected' => '2026-09-15', 'number' => 2, 'paid' => false, 'value_quota' => 100],
                    ['date_expected' => '2026-10-15', 'number' => 3, 'paid' => false, 'value_quota' => 100],
                ],
            ]));

        $response->assertStatus(201);
        $expenseId = $response->json('expense_id');
        $this->assertDatabaseHas('ex_expenses', [
            'id' => $expenseId,
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 3,
        ]);
        $this->assertSame(3, \App\Models\Quota::where('expense_id', $expenseId)->count());
    }

    public function test_member_can_create_fixed_expense(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, [
                'expense_type' => 'FIXED',
                'installments' => 1,
                'quotas' => [[
                    'date_expected' => '2026-08-15',
                    'number' => 1,
                    'paid' => false,
                    'value_quota' => 100,
                ]],
            ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('ex_expenses', [
            'group_id' => $group->id,
            'expense_type' => 'FIXED',
            'fixed_recurrence_ends_at' => null,
        ]);
    }

    public function test_fixed_expense_rejects_installments_different_from_one(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, [
                'expense_type' => 'FIXED',
                'installments' => 2,
            ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('ex_expenses', ['group_id' => $group->id, 'expense_type' => 'FIXED']);
    }

    public function test_fixed_expense_rejects_more_than_one_quota(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, [
                'expense_type' => 'FIXED',
                'installments' => 1,
                'quotas' => [
                    ['date_expected' => '2026-08-15', 'number' => 1, 'paid' => false, 'value_quota' => 50],
                    ['date_expected' => '2026-09-15', 'number' => 2, 'paid' => false, 'value_quota' => 50],
                ],
            ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('ex_expenses', ['group_id' => $group->id, 'expense_type' => 'FIXED']);
    }
}
