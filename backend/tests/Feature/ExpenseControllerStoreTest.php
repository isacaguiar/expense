<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseControllerStoreTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixa o relógio na mesma competência das fixtures (date_payment
        // '2026-08-15'). Sem isso, os testes de caminho feliz passam a receber
        // 422 "competência já fechada" assim que o relógio real vira de mês —
        // ver docs/bugfix/20260901-expense-store-update-422.md. Testes que
        // definem o próprio Carbon::setTestNow() continuam mandando.
        Carbon::setTestNow('2026-08-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

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

    public function test_cannot_create_expense_in_deleted_group(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'deleted' => true]);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member));

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

    public function test_installments_expense_rejects_quotas_count_different_from_installments(): void
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
                    ['date_expected' => '2026-08-15', 'number' => 1, 'paid' => false, 'value_quota' => 150],
                    ['date_expected' => '2026-09-15', 'number' => 2, 'paid' => false, 'value_quota' => 150],
                ],
            ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('ex_expenses', ['group_id' => $group->id, 'expense_type' => 'IN_INSTALLMENTS']);
    }

    public function test_new_expense_quota_starts_as_pending_even_if_client_sends_paid_true(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        // payloadFor() já envia 'paid' => true por padrão — o teste confirma
        // que o servidor ignora esse valor e a despesa nasce PENDENTE.
        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member));

        $response->assertStatus(201);
        $expenseId = $response->json('expense_id');
        $this->assertDatabaseHas('ex_quotas', ['expense_id' => $expenseId, 'paid' => false]);
        $this->assertDatabaseMissing('ex_quotas', ['expense_id' => $expenseId, 'paid' => true]);
    }

    public function test_installments_expense_quotas_start_as_pending_even_if_client_sends_paid_true(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 2,
                'total_value' => 200,
                'quotas' => [
                    ['date_expected' => '2026-08-15', 'number' => 1, 'paid' => true, 'value_quota' => 100],
                    ['date_expected' => '2026-09-15', 'number' => 2, 'paid' => true, 'value_quota' => 100],
                ],
            ]));

        $response->assertStatus(201);
        $expenseId = $response->json('expense_id');
        $this->assertSame(2, \App\Models\Quota::where('expense_id', $expenseId)->where('paid', false)->count());
        $this->assertSame(0, \App\Models\Quota::where('expense_id', $expenseId)->where('paid', true)->count());
    }

    public function test_fixed_expense_quota_starts_as_pending_even_if_client_sends_paid_true(): void
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
                    'paid' => true,
                    'value_quota' => 100,
                ]],
            ]));

        $response->assertStatus(201);
        $expenseId = $response->json('expense_id');
        $this->assertDatabaseHas('ex_quotas', ['expense_id' => $expenseId, 'paid' => false]);
    }

    public function test_installments_expense_rejects_quotas_sum_different_from_total_value(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 2,
                'total_value' => 300,
                'quotas' => [
                    ['date_expected' => '2026-08-15', 'number' => 1, 'paid' => false, 'value_quota' => 100],
                    ['date_expected' => '2026-09-15', 'number' => 2, 'paid' => false, 'value_quota' => 100],
                ],
            ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('ex_expenses', ['group_id' => $group->id, 'expense_type' => 'IN_INSTALLMENTS']);
    }

    public function test_rejects_expense_with_date_payment_in_an_automatically_closed_cycle(): void
    {
        Carbon::setTestNow('2026-08-19');

        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, [
                'date_payment' => '2026-07-10',
                'quotas' => [['date_expected' => '2026-07-10', 'number' => 1, 'value_quota' => 100]],
            ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('ex_expenses', ['group_id' => $group->id]);
    }

    public function test_rejects_fixed_expense_with_date_payment_in_an_automatically_closed_cycle(): void
    {
        Carbon::setTestNow('2026-08-19');

        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, [
                'expense_type' => 'FIXED',
                'date_payment' => '2026-07-10',
                'quotas' => [['date_expected' => '2026-07-10', 'number' => 1, 'value_quota' => 100]],
            ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('ex_expenses', ['group_id' => $group->id, 'expense_type' => 'FIXED']);
    }

    public function test_rejects_expense_with_date_payment_in_a_manually_closed_cycle(): void
    {
        Carbon::setTestNow('2026-08-19');

        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'totals' => ['total' => 0, 'paid' => 0, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => now(),
        ]);

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, [
                'date_payment' => '2026-08-10',
                'quotas' => [['date_expected' => '2026-08-10', 'number' => 1, 'value_quota' => 100]],
            ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('ex_expenses', ['group_id' => $group->id]);
    }
}
