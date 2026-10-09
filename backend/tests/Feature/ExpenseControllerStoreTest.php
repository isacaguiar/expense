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
        // (TASK-001 de docs/feature/concluidas/202609/20260904-parcela-retroativa-contabilizacao/):
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
        // (docs/feature/concluidas/202609/20260904-parcela-retroativa-contabilizacao/specify.md §1).
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

    /**
     * TASK-399 (docs/feature/20261008-rateio-parcelas-no-backend/): `quotas` passa a ser
     * opcional em POST /api/expenses — sem ele, o servidor gera as quotas com
     * App\Support\InstallmentSchedule. Quem envia `quotas` segue o caminho antigo.
     */
    private function payloadWithoutQuotas(Group $group, User $payer, array $overrides = []): array
    {
        $payload = $this->payloadFor($group, $payer, $overrides);
        unset($payload['quotas']);

        return $payload;
    }

    /**
     * @return list<array{number: int, date: string, value: string, paid: bool, born_paid: bool}>
     */
    private function quotaRows(int $expenseId): array
    {
        return \App\Models\Quota::where('expense_id', $expenseId)
            ->orderBy('number')
            ->get()
            ->map(fn ($q) => [
                'number' => $q->number,
                'date' => $q->date_expected->toDateString(),
                'value' => (string) $q->value_quota,
                'paid' => $q->paid,
                'born_paid' => $q->born_paid,
            ])
            ->all();
    }

    private function memberWithGroup(): array
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        return [$member, $group];
    }

    public function test_in_cash_expense_without_quotas_gets_a_single_quota_generated_by_the_server(): void
    {
        [$member, $group] = $this->memberWithGroup();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadWithoutQuotas($group, $member));

        $response->assertStatus(201);
        $this->assertSame(
            [['number' => 1, 'date' => '2026-08-15', 'value' => '100.00', 'paid' => false, 'born_paid' => false]],
            $this->quotaRows($response->json('expense_id'))
        );
    }

    public function test_fixed_expense_without_quotas_gets_a_single_quota_generated_by_the_server(): void
    {
        [$member, $group] = $this->memberWithGroup();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadWithoutQuotas($group, $member, ['expense_type' => 'FIXED']));

        $response->assertStatus(201);
        $this->assertSame(
            [['number' => 1, 'date' => '2026-08-15', 'value' => '100.00', 'paid' => false, 'born_paid' => false]],
            $this->quotaRows($response->json('expense_id'))
        );
    }

    public function test_fixed_expense_without_quotas_still_rejects_installments_different_from_one(): void
    {
        [$member, $group] = $this->memberWithGroup();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadWithoutQuotas($group, $member, [
                'expense_type' => 'FIXED',
                'installments' => 3,
            ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('ex_expenses', ['group_id' => $group->id]);
    }

    public function test_installments_expense_without_quotas_is_split_by_the_server_with_the_remainder_on_the_last(): void
    {
        [$member, $group] = $this->memberWithGroup();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadWithoutQuotas($group, $member, [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 3,
                'total_value' => 100,
            ]));

        $response->assertStatus(201);
        $this->assertSame([
            ['number' => 1, 'date' => '2026-08-15', 'value' => '33.33', 'paid' => false, 'born_paid' => false],
            ['number' => 2, 'date' => '2026-09-15', 'value' => '33.33', 'paid' => false, 'born_paid' => false],
            ['number' => 3, 'date' => '2026-10-15', 'value' => '33.34', 'paid' => false, 'born_paid' => false],
        ], $this->quotaRows($response->json('expense_id')));
    }

    public function test_generated_quotas_are_identical_to_the_ones_the_client_would_send(): void
    {
        [$member, $group] = $this->memberWithGroup();
        $base = [
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 7,
            'total_value' => 1000.01,
            'date_payment' => '2026-08-31',
        ];

        // O que o web monta hoje (utils/installments.ts) para 1000,01 em 7 a partir
        // de 31/08: seis de 142,85, a última de 142,91, e as datas com clamp de mês curto.
        $clientQuotas = [];
        foreach (['2026-08-31', '2026-09-30', '2026-10-31', '2026-11-30', '2026-12-31', '2027-01-31', '2027-02-28'] as $i => $date) {
            $clientQuotas[] = ['date_expected' => $date, 'number' => $i + 1, 'paid' => false, 'value_quota' => $i === 6 ? 142.91 : 142.85];
        }

        $withQuotas = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, $base + ['quotas' => $clientQuotas]));
        $withoutQuotas = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadWithoutQuotas($group, $member, $base));

        $withQuotas->assertStatus(201);
        $withoutQuotas->assertStatus(201);
        $this->assertCount(7, $this->quotaRows($withoutQuotas->json('expense_id')));
        $this->assertSame(
            $this->quotaRows($withQuotas->json('expense_id')),
            $this->quotaRows($withoutQuotas->json('expense_id'))
        );
    }

    public function test_installments_expense_without_quotas_rejects_a_single_installment(): void
    {
        [$member, $group] = $this->memberWithGroup();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadWithoutQuotas($group, $member, [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 1,
            ]));

        $response->assertStatus(422)->assertJsonValidationErrors('installments');
        $this->assertDatabaseMissing('ex_expenses', ['group_id' => $group->id]);
    }

    public function test_installments_expense_without_quotas_rejects_more_than_one_hundred_and_twenty_installments(): void
    {
        [$member, $group] = $this->memberWithGroup();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadWithoutQuotas($group, $member, [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 121,
                'total_value' => 1210,
            ]));

        $response->assertStatus(422)->assertJsonValidationErrors('installments');
        $this->assertDatabaseMissing('ex_expenses', ['group_id' => $group->id]);
    }

    public function test_installments_expense_without_quotas_accepts_exactly_one_hundred_and_twenty_installments(): void
    {
        [$member, $group] = $this->memberWithGroup();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadWithoutQuotas($group, $member, [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 120,
                'total_value' => 1200,
            ]));

        $response->assertStatus(201);
        $this->assertCount(120, $this->quotaRows($response->json('expense_id')));
    }

    public function test_the_installments_limit_does_not_apply_when_the_client_sends_its_own_quotas(): void
    {
        [$member, $group] = $this->memberWithGroup();

        $quotas = [];
        for ($i = 1; $i <= 121; $i++) {
            $quotas[] = ['date_expected' => Carbon::parse('2026-08-15')->addMonthsNoOverflow($i - 1)->toDateString(), 'number' => $i, 'paid' => false, 'value_quota' => 1];
        }

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadFor($group, $member, [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 121,
                'total_value' => 121,
                'quotas' => $quotas,
            ]));

        $response->assertStatus(201);
        $this->assertCount(121, $this->quotaRows($response->json('expense_id')));
    }

    public function test_empty_or_null_quotas_are_still_rejected(): void
    {
        [$member, $group] = $this->memberWithGroup();

        foreach ([[], null] as $quotas) {
            $response = $this->withToken($this->tokenFor($member))
                ->postJson('/api/expenses', $this->payloadFor($group, $member, ['quotas' => $quotas]));

            $response->assertStatus(422);
        }

        $this->assertDatabaseMissing('ex_expenses', ['group_id' => $group->id]);
    }

    public function test_in_cash_expense_without_quotas_in_a_closed_cycle_is_rejected(): void
    {
        Carbon::setTestNow('2026-08-19');
        [$member, $group] = $this->memberWithGroup();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadWithoutQuotas($group, $member, ['date_payment' => '2026-07-10']));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('ex_expenses', ['group_id' => $group->id]);
    }

    public function test_retroactive_installments_expense_without_quotas_marks_past_quotas_born_paid(): void
    {
        // Mesmo cenário de test_installments_expense_starting_in_a_closed_cycle_is_created_with_past_quotas_paid,
        // mas com as quotas geradas pelo servidor: jun/jul/ago fechados, set aberto, out/nov futuros.
        Carbon::setTestNow('2026-09-20');
        [$member, $group] = $this->memberWithGroup();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/expenses', $this->payloadWithoutQuotas($group, $member, [
                'date_payment' => '2026-06-05',
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 6,
                'total_value' => 600,
            ]));

        $response->assertStatus(201);
        $this->assertSame([
            ['number' => 1, 'date' => '2026-06-05', 'value' => '100.00', 'paid' => true, 'born_paid' => true],
            ['number' => 2, 'date' => '2026-07-05', 'value' => '100.00', 'paid' => true, 'born_paid' => true],
            ['number' => 3, 'date' => '2026-08-05', 'value' => '100.00', 'paid' => true, 'born_paid' => true],
            ['number' => 4, 'date' => '2026-09-05', 'value' => '100.00', 'paid' => false, 'born_paid' => false],
            ['number' => 5, 'date' => '2026-10-05', 'value' => '100.00', 'paid' => false, 'born_paid' => false],
            ['number' => 6, 'date' => '2026-11-05', 'value' => '100.00', 'paid' => false, 'born_paid' => false],
        ], $this->quotaRows($response->json('expense_id')));
    }
}
