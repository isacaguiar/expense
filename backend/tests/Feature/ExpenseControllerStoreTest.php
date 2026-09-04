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
        $this->assertDatabaseHas('ex_quotas', ['expense_id' => $expenseId, 'paid' => false, 'born_paid' => false]);
        $this->assertDatabaseMissing('ex_quotas', ['expense_id' => $expenseId, 'paid' => true]);
    }

    /**
     * A regra "o cliente não decide `paid`" continua valendo para parcelas em
     * ciclo aberto/futuro (aqui, ago e set/2026 com o relógio em 2026-08-15).
     * O servidor só marca parcela quitada na criação quando ela cai num ciclo
     * já FECHADO por data — coberto por
     * test_installments_expense_starting_in_a_closed_cycle_is_created_with_past_quotas_paid.
     */
    public function test_installments_expense_quotas_in_open_cycles_start_as_pending_even_if_client_sends_paid_true(): void
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

    public function test_installments_expense_starting_in_a_closed_cycle_is_created_with_past_quotas_paid(): void
    {
        // 2026-09-20: com closing_day nulo (mês calendário + 5 dias de carência),
        // os ciclos de jun, jul e ago/2026 já estão `closed`; set/2026 está
        // `open`; out e nov/2026 são `future`.
        Carbon::setTestNow('2026-09-20');

        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, [
                'date_payment' => '2026-06-05',
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 6,
                'total_value' => 600,
                'quotas' => [
                    ['date_expected' => '2026-06-05', 'number' => 1, 'value_quota' => 100],
                    ['date_expected' => '2026-07-05', 'number' => 2, 'value_quota' => 100],
                    ['date_expected' => '2026-08-05', 'number' => 3, 'value_quota' => 100],
                    ['date_expected' => '2026-09-05', 'number' => 4, 'value_quota' => 100],
                    ['date_expected' => '2026-10-05', 'number' => 5, 'value_quota' => 100],
                    ['date_expected' => '2026-11-05', 'number' => 6, 'value_quota' => 100],
                ],
            ]));

        $response->assertStatus(201);
        $expenseId = $response->json('expense_id');

        // jun/jul/ago (ciclos fechados) → quitadas pelo credor, marcadas born_paid
        // (TASK-001 de docs/feature/20260904-parcela-retroativa-contabilizacao/):
        // é esse flag, não `paid`, que tira a parcela do acerto em computeCycleSummary().
        $paid = \App\Models\Quota::where('expense_id', $expenseId)->where('paid', true)->get();
        $this->assertCount(3, $paid);
        $this->assertEqualsCanonicalizing(
            ['2026-06-05', '2026-07-05', '2026-08-05'],
            $paid->map(fn ($q) => $q->date_expected->toDateString())->all()
        );
        foreach ($paid as $quota) {
            $this->assertSame($member->id, $quota->paid_by);
            $this->assertNotNull($quota->paid_at);
            $this->assertTrue($quota->born_paid);
        }

        // set (menor ciclo aberto) + out/nov (futuros) → pendentes, born_paid=false.
        $pending = \App\Models\Quota::where('expense_id', $expenseId)->where('paid', false)->get();
        $this->assertCount(3, $pending);
        $this->assertEqualsCanonicalizing(
            ['2026-09-05', '2026-10-05', '2026-11-05'],
            $pending->map(fn ($q) => $q->date_expected->toDateString())->all()
        );
        foreach ($pending as $quota) {
            $this->assertNull($quota->paid_by);
            $this->assertNull($quota->paid_at);
            $this->assertFalse($quota->born_paid);
        }
    }

    public function test_installments_expense_shared_with_a_debtor_marks_past_quotas_born_paid(): void
    {
        // Mesmo cenário da despesa retroativa acima, mas com um devedor além do
        // credor — é essa combinação (participantsCount > 1) que expõe o bug de
        // computeCycleSummary() gerar settlement fantasma para parcela já paga
        // (docs/feature/20260904-parcela-retroativa-contabilizacao/specify.md §1).
        Carbon::setTestNow('2026-09-20');

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        $response = $this->withToken($this->tokenFor($creditor))
            ->postJson('/api/expenses', $this->payloadFor($group, $creditor, [
                'date_payment' => '2026-06-05',
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 6,
                'total_value' => 600,
                'payers' => [$creditor->id, $debtor->id],
                'quotas' => [
                    ['date_expected' => '2026-06-05', 'number' => 1, 'value_quota' => 100],
                    ['date_expected' => '2026-07-05', 'number' => 2, 'value_quota' => 100],
                    ['date_expected' => '2026-08-05', 'number' => 3, 'value_quota' => 100],
                    ['date_expected' => '2026-09-05', 'number' => 4, 'value_quota' => 100],
                    ['date_expected' => '2026-10-05', 'number' => 5, 'value_quota' => 100],
                    ['date_expected' => '2026-11-05', 'number' => 6, 'value_quota' => 100],
                ],
            ]));

        $response->assertStatus(201);
        $expenseId = $response->json('expense_id');

        $this->assertSame(
            3,
            \App\Models\Quota::where('expense_id', $expenseId)->where('born_paid', true)->count()
        );
        $this->assertSame(
            3,
            \App\Models\Quota::where('expense_id', $expenseId)->where('born_paid', false)->count()
        );
    }

    public function test_installments_expense_entirely_in_closed_cycles_is_rejected(): void
    {
        Carbon::setTestNow('2026-09-20');

        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, [
                'date_payment' => '2026-05-10',
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 3,
                'total_value' => 300,
                'quotas' => [
                    ['date_expected' => '2026-05-10', 'number' => 1, 'value_quota' => 100],
                    ['date_expected' => '2026-06-10', 'number' => 2, 'value_quota' => 100],
                    ['date_expected' => '2026-07-10', 'number' => 3, 'value_quota' => 100],
                ],
            ]));

        $response->assertStatus(422)->assertJson([
            'error' => 'Esta despesa parcelada está inteira em competências já fechadas. Para registrá-la, ao menos a última parcela precisa cair num ciclo ainda aberto.',
        ]);
        $this->assertDatabaseMissing('ex_expenses', ['group_id' => $group->id]);
    }

    public function test_retroactive_installments_leave_a_sealed_past_cycle_untouched_and_pending_starts_at_the_open_cycle(): void
    {
        Carbon::setTestNow('2026-09-20');

        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        // Junho/2026 já selado — foto congelada, nada pendente.
        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-06-01',
            'cycle_end' => '2026-06-30',
            'totals' => ['total' => 0, 'paid' => 0, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'settlements' => [],
            'settled_at' => now(),
        ]);

        $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, [
                'date_payment' => '2026-06-05',
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 6,
                'total_value' => 600,
                'quotas' => [
                    ['date_expected' => '2026-06-05', 'number' => 1, 'value_quota' => 100],
                    ['date_expected' => '2026-07-05', 'number' => 2, 'value_quota' => 100],
                    ['date_expected' => '2026-08-05', 'number' => 3, 'value_quota' => 100],
                    ['date_expected' => '2026-09-05', 'number' => 4, 'value_quota' => 100],
                    ['date_expected' => '2026-10-05', 'number' => 5, 'value_quota' => 100],
                    ['date_expected' => '2026-11-05', 'number' => 6, 'value_quota' => 100],
                ],
            ]))
            ->assertStatus(201);

        // Junho continua servindo a foto selada — a parcela retroativa não entra.
        $this->withToken($this->tokenFor($member))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=3")
            ->assertStatus(200)
            ->assertJsonPath('cycle.start', '2026-06-01')
            ->assertJsonPath('cycle.settled', true)
            ->assertJsonPath('totals.total', 0);

        // Ciclo corrente (set/2026): só a parcela de setembro conta como pendência.
        $this->withToken($this->tokenFor($member))
            ->getJson("/api/groups/{$group->id}/expenses/summary")
            ->assertStatus(200)
            ->assertJsonPath('cycle.start', '2026-09-01')
            ->assertJsonPath('totals.pending', 100);
    }
}
