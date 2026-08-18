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
}
