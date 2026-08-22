<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Participation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GroupControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    private function createExpense(Group $group, User $creator, string $datePayment): Expense
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
            'user_payer_id' => $creator->id,
            'deleted' => false,
        ]);
    }

    public function test_member_can_view_group(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups/'.$group->id);

        $response->assertStatus(200)->assertJsonFragment(['id' => $group->id]);
    }

    public function test_show_includes_creator_email(): void
    {
        $creator = User::factory()->create(['email' => 'criador@example.com']);
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'created_by' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id]);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups/'.$group->id);

        $response->assertStatus(200)->assertJsonFragment(['creator' => ['id' => $creator->id, 'email' => 'criador@example.com']]);
    }

    public function test_index_includes_creator_email(): void
    {
        $creator = User::factory()->create(['email' => 'criador2@example.com']);
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'created_by' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id]);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups');

        $response->assertStatus(200)->assertJsonFragment(['creator' => ['id' => $creator->id, 'email' => 'criador2@example.com']]);
    }

    public function test_index_includes_members_list(): void
    {
        $member = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
        $other = User::factory()->create(['name' => 'Bruno', 'email' => 'bruno@example.com']);
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$member->id, $other->id]);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups');

        $response->assertStatus(200)->assertJsonFragment(['id' => $member->id, 'email' => 'ana@example.com']);
        $response->assertJsonFragment(['id' => $other->id, 'email' => 'bruno@example.com']);
    }

    public function test_index_includes_expenses_max_date_payment(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo com despesas']);
        $group->members()->attach($member->id);
        $this->createExpense($group, $member, '2026-01-10');
        $this->createExpense($group, $member, '2026-03-20');

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups');

        $response->assertStatus(200)->assertJsonFragment(['expenses_max_date_payment' => '2026-03-20']);
    }

    public function test_index_expenses_max_date_payment_is_null_without_expenses(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo sem despesas']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups');

        $response->assertStatus(200)->assertJsonFragment(['id' => $group->id, 'expenses_max_date_payment' => null]);
    }

    public function test_non_member_cannot_view_group(): void
    {
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);

        $response = $this->withToken($this->tokenFor($outsider))
            ->getJson('/api/groups/'.$group->id);

        $response->assertStatus(404);
    }

    public function test_member_can_update_group(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Nome antigo']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->putJson('/api/groups/'.$group->id, ['name' => 'Nome novo']);

        $response->assertStatus(200)->assertJsonFragment(['name' => 'Nome novo']);
        $this->assertDatabaseHas('ex_groups', ['id' => $group->id, 'name' => 'Nome novo']);
    }

    public function test_non_member_cannot_update_group(): void
    {
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Nome antigo']);

        $response = $this->withToken($this->tokenFor($outsider))
            ->putJson('/api/groups/'.$group->id, ['name' => 'Nome novo']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('ex_groups', ['id' => $group->id, 'name' => 'Nome antigo']);
    }

    public function test_member_can_delete_group_without_closed_cycle_physically(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $expense = $this->createExpense($group, $member, '2026-08-10');
        $expense->payers()->attach($member->id);
        $quota = $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);
        Participation::create(['state' => 'PENDING', 'group_id' => $group->id, 'quota_id' => $quota->id]);

        $response = $this->withToken($this->tokenFor($member))
            ->deleteJson('/api/groups/'.$group->id);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('ex_groups', ['id' => $group->id]);
        $this->assertDatabaseMissing('ex_groups_members', ['group_id' => $group->id]);
        $this->assertDatabaseMissing('ex_expenses', ['id' => $expense->id]);
        $this->assertDatabaseMissing('ex_quotas', ['id' => $quota->id]);
        $this->assertDatabaseMissing('ex_expenses_payers', ['expense_id' => $expense->id]);
        $this->assertDatabaseMissing('ex_participations', ['quota_id' => $quota->id]);
    }

    public function test_member_can_delete_group_with_closed_cycle_logically_preserving_history(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $expense = $this->createExpense($group, $member, '2026-08-10');
        $expense->payers()->attach($member->id);
        $quota = $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);
        Participation::create(['state' => 'PENDING', 'group_id' => $group->id, 'quota_id' => $quota->id]);

        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'totals' => [],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => now(),
        ]);

        $response = $this->withToken($this->tokenFor($member))
            ->deleteJson('/api/groups/'.$group->id);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_groups', ['id' => $group->id, 'deleted' => true]);
        $this->assertDatabaseHas('ex_groups_members', ['group_id' => $group->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id]);
        $this->assertDatabaseHas('ex_quotas', ['id' => $quota->id]);
        $this->assertDatabaseHas('ex_expenses_payers', ['expense_id' => $expense->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('ex_participations', ['quota_id' => $quota->id]);
        $this->assertDatabaseHas('ex_group_cycle_snapshots', ['group_id' => $group->id]);
    }

    public function test_non_member_cannot_delete_group(): void
    {
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);

        $response = $this->withToken($this->tokenFor($outsider))
            ->deleteJson('/api/groups/'.$group->id);

        $response->assertStatus(404);
        $this->assertDatabaseHas('ex_groups', ['id' => $group->id, 'deleted' => false]);
    }

    public function test_store_persists_closing_day(): void
    {
        $member = User::factory()->create();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups', ['name' => 'Grupo com fechamento', 'closing_day' => 10]);

        $response->assertStatus(201)->assertJsonFragment(['closing_day' => 10]);
        $this->assertDatabaseHas('ex_groups', ['name' => 'Grupo com fechamento', 'closing_day' => 10]);
    }

    public function test_store_without_closing_day_defaults_to_null(): void
    {
        $member = User::factory()->create();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups', ['name' => 'Grupo sem fechamento']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('ex_groups', ['name' => 'Grupo sem fechamento', 'closing_day' => null]);
    }

    public function test_store_rejects_invalid_closing_day(): void
    {
        $member = User::factory()->create();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups', ['name' => 'Grupo inválido', 'closing_day' => 32]);

        $response->assertStatus(422);
    }

    public function test_store_sets_created_by_to_authenticated_user(): void
    {
        $member = User::factory()->create();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups', ['name' => 'Grupo com criador']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('ex_groups', ['name' => 'Grupo com criador', 'created_by' => $member->id]);
    }

    public function test_store_blocks_fourth_group_created_by_same_user(): void
    {
        $member = User::factory()->create();
        $token = $this->tokenFor($member);

        foreach (range(1, 3) as $i) {
            $this->withToken($token)
                ->postJson('/api/groups', ['name' => "Grupo {$i}"])
                ->assertStatus(201);
        }

        $response = $this->withToken($token)
            ->postJson('/api/groups', ['name' => 'Grupo 4']);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('ex_groups', ['name' => 'Grupo 4']);
    }

    public function test_store_ignores_deleted_groups_when_counting_limit(): void
    {
        $member = User::factory()->create();
        $token = $this->tokenFor($member);

        foreach (range(1, 3) as $i) {
            $this->withToken($token)
                ->postJson('/api/groups', ['name' => "Grupo excluido {$i}"])
                ->assertStatus(201);
        }

        Group::where('created_by', $member->id)->update(['deleted' => true]);

        $response = $this->withToken($token)
            ->postJson('/api/groups', ['name' => 'Grupo novo apos exclusao']);

        $response->assertStatus(201);
    }

    public function test_member_can_update_closing_day(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'closing_day' => 5]);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->putJson('/api/groups/'.$group->id, ['name' => 'Grupo de teste', 'closing_day' => 15]);

        $response->assertStatus(200)->assertJsonFragment(['closing_day' => 15]);
        $this->assertDatabaseHas('ex_groups', ['id' => $group->id, 'closing_day' => 15]);
    }
}
