<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseControllerShowUpdateDestroyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixa o relógio na mesma competência das fixtures (date_payment
        // '2026-08-15'). Sem isso, update()/destroy() passam a receber 422
        // "competência já fechada" assim que o relógio real vira de mês —
        // ver docs/bugfix/20260901-expense-store-update-422.md.
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

    public function test_update_rejects_fixed_as_a_target_type(): void
    {
        // 'FIXED' nunca é um valor aceito pra expense_type em update() — a
        // regra de validação `in:IN_CASH,IN_INSTALLMENTS` já recusa sozinha.
        // Ver docs/feature/concluidas/202608/20260826-editar-tipo-despesa/specify.md §R2.
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['expense_type' => 'FIXED']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'expense_type' => 'IN_CASH']);
    }

    public function test_update_rejects_changing_type_of_a_fixed_expense(): void
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator, [
            'expense_type' => 'FIXED',
            'date_payment' => '2026-06-05',
            'total_value' => 300,
        ]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['expense_type' => 'IN_CASH']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'expense_type' => 'FIXED']);
    }

    public function test_update_applies_a_real_change_from_in_cash_to_in_installments(): void
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator, ['total_value' => 200]);
        $expense->quotas()->create(['date_expected' => '2026-08-15', 'number' => 1, 'paid' => false, 'value_quota' => 200]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 2,
                'quotas' => [
                    ['date_expected' => '2026-08-15', 'number' => 1, 'value_quota' => 100],
                    ['date_expected' => '2026-09-15', 'number' => 2, 'value_quota' => 100],
                ],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', [
            'id' => $expense->id,
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 2,
        ]);
        $this->assertSame(2, $expense->quotas()->count());
        $this->assertDatabaseHas('ex_quotas', ['expense_id' => $expense->id, 'number' => 2, 'value_quota' => 100, 'paid' => false]);
    }

    public function test_update_collapses_installments_to_in_cash(): void
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator, [
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 2,
            'total_value' => 200,
        ]);
        $expense->quotas()->create(['date_expected' => '2026-08-15', 'number' => 1, 'paid' => false, 'value_quota' => 100]);
        $expense->quotas()->create(['date_expected' => '2026-09-15', 'number' => 2, 'paid' => false, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['expense_type' => 'IN_CASH']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'expense_type' => 'IN_CASH', 'installments' => 1]);
        $this->assertSame(1, $expense->quotas()->count());
        $this->assertDatabaseHas('ex_quotas', ['expense_id' => $expense->id, 'number' => 1, 'value_quota' => 200, 'paid' => false]);
    }

    public function test_update_rejects_installments_count_not_matching_quotas_count(): void
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator, ['total_value' => 200]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 3,
                'quotas' => [
                    ['date_expected' => '2026-08-15', 'number' => 1, 'value_quota' => 100],
                    ['date_expected' => '2026-09-15', 'number' => 2, 'value_quota' => 100],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'expense_type' => 'IN_CASH']);
    }

    public function test_update_rejects_quotas_sum_not_matching_total_value(): void
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator, ['total_value' => 200]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 2,
                'quotas' => [
                    ['date_expected' => '2026-08-15', 'number' => 1, 'value_quota' => 100],
                    ['date_expected' => '2026-09-15', 'number' => 2, 'value_quota' => 150],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'expense_type' => 'IN_CASH']);
    }

    public function test_update_blocks_entire_edit_when_installments_expense_has_any_quota_paid(): void
    {
        // Regra pedida pelo usuário: parcelada com QUALQUER parcela paga trava
        // a edição inteira, não só tipo/valor — mesmo enviando só `description`.
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator, [
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 2,
            'total_value' => 200,
        ]);
        $expense->quotas()->create(['date_expected' => '2026-08-15', 'number' => 1, 'paid' => true, 'value_quota' => 100]);
        $expense->quotas()->create(['date_expected' => '2026-09-15', 'number' => 2, 'paid' => false, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['description' => 'Só a descrição']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'description' => 'Despesa de teste']);
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

    public function test_update_rejects_total_value_change_when_expense_is_paid(): void
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator);
        $expense->quotas()->create(['date_expected' => '2026-08-15', 'number' => 1, 'paid' => true, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['total_value' => 200]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'total_value' => 100]);
    }

    public function test_update_allows_non_value_changes_when_expense_is_paid(): void
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator);
        $expense->quotas()->create(['date_expected' => '2026-08-15', 'number' => 1, 'paid' => true, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['description' => 'Só ajustando a descrição']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'description' => 'Só ajustando a descrição']);
    }

    public function test_update_rejects_total_value_change_for_installments_when_any_quota_is_paid(): void
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator, [
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 2,
            'total_value' => 200,
        ]);
        $expense->quotas()->create(['date_expected' => '2026-08-15', 'number' => 1, 'paid' => true, 'value_quota' => 100]);
        $expense->quotas()->create(['date_expected' => '2026-09-15', 'number' => 2, 'paid' => false, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['total_value' => 300]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'total_value' => 200]);
    }

    public function test_update_allows_fixed_total_value_change_even_when_a_past_occurrence_is_paid(): void
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator, [
            'expense_type' => 'FIXED',
            'date_payment' => '2026-06-05',
            'total_value' => 300,
        ]);
        $expense->quotas()->create(['date_expected' => '2026-06-05', 'number' => 1, 'paid' => true, 'value_quota' => 300]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['total_value' => 350]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'total_value' => 350]);
    }

    public function test_destroy_rejects_when_expense_is_paid(): void
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator);
        $expense->quotas()->create(['date_expected' => '2026-08-15', 'number' => 1, 'paid' => true, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($creator))
            ->deleteJson("/api/expenses/{$expense->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'deleted' => false]);
    }

    public function test_destroy_rejects_fixed_expense_when_any_occurrence_is_paid(): void
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator, [
            'expense_type' => 'FIXED',
            'date_payment' => '2026-06-05',
            'total_value' => 300,
        ]);
        $expense->quotas()->create(['date_expected' => '2026-06-05', 'number' => 1, 'paid' => true, 'value_quota' => 300]);

        $response = $this->withToken($this->tokenFor($creator))
            ->deleteJson("/api/expenses/{$expense->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'deleted' => false]);
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

    /**
     * TASK-400 (docs/feature/20261008-rateio-parcelas-no-backend/): ao trocar para
     * parcelada sem `quotas`, o update() gera as quotas com
     * App\Support\InstallmentSchedule. Quem envia `quotas` segue o caminho antigo.
     *
     * @return list<array{number: int, date: string, value: string, paid: bool}>
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
            ])
            ->all();
    }

    private function inCashExpenseWithOneQuota(array $overrides = []): array
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator, $overrides);
        $expense->quotas()->create([
            'date_expected' => $expense->date_payment->toDateString(),
            'number' => 1,
            'paid' => false,
            'value_quota' => $expense->total_value,
        ]);

        return [$creator, $expense];
    }

    public function test_update_to_installments_without_quotas_generates_them_on_the_server(): void
    {
        [$creator, $expense] = $this->inCashExpenseWithOneQuota(['total_value' => 100]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['expense_type' => 'IN_INSTALLMENTS', 'installments' => 3]);

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'quotas');
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'expense_type' => 'IN_INSTALLMENTS', 'installments' => 3]);
        $this->assertSame([
            ['number' => 1, 'date' => '2026-08-15', 'value' => '33.33', 'paid' => false],
            ['number' => 2, 'date' => '2026-09-15', 'value' => '33.33', 'paid' => false],
            ['number' => 3, 'date' => '2026-10-15', 'value' => '33.34', 'paid' => false],
        ], $this->quotaRows($expense->id));
    }

    public function test_update_to_installments_without_quotas_splits_the_final_total_from_the_final_date(): void
    {
        [$creator, $expense] = $this->inCashExpenseWithOneQuota(['total_value' => 100]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 3,
                'total_value' => 250,
                'date_payment' => '2026-08-20',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'total_value' => 250, 'date_payment' => '2026-08-20']);
        $this->assertSame([
            ['number' => 1, 'date' => '2026-08-20', 'value' => '83.33', 'paid' => false],
            ['number' => 2, 'date' => '2026-09-20', 'value' => '83.33', 'paid' => false],
            ['number' => 3, 'date' => '2026-10-20', 'value' => '83.34', 'paid' => false],
        ], $this->quotaRows($expense->id));
    }

    public function test_quotas_generated_by_update_are_identical_to_the_ones_the_client_would_send(): void
    {
        $base = [
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 7,
            'total_value' => 1000.01,
            'date_payment' => '2026-08-31',
        ];

        // O que o web monta hoje para 1000,01 em 7 a partir de 31/08: seis de
        // 142,85, a última de 142,91, e as datas com clamp de mês curto.
        $clientQuotas = [];
        foreach (['2026-08-31', '2026-09-30', '2026-10-31', '2026-11-30', '2026-12-31', '2027-01-31', '2027-02-28'] as $i => $date) {
            $clientQuotas[] = ['date_expected' => $date, 'number' => $i + 1, 'value_quota' => $i === 6 ? 142.91 : 142.85];
        }

        [$creatorWith, $expenseWith] = $this->inCashExpenseWithOneQuota();
        [$creatorWithout, $expenseWithout] = $this->inCashExpenseWithOneQuota();

        $withQuotas = $this->withToken($this->tokenFor($creatorWith))
            ->putJson("/api/expenses/{$expenseWith->id}", $base + ['quotas' => $clientQuotas]);
        $withoutQuotas = $this->withToken($this->tokenFor($creatorWithout))
            ->putJson("/api/expenses/{$expenseWithout->id}", $base);

        $withQuotas->assertStatus(200);
        $withoutQuotas->assertStatus(200);
        $this->assertCount(7, $this->quotaRows($expenseWithout->id));
        $this->assertSame($this->quotaRows($expenseWith->id), $this->quotaRows($expenseWithout->id));
    }

    public function test_update_resplits_an_unpaid_installments_expense_when_the_type_is_sent_without_quotas(): void
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator, [
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 2,
            'total_value' => 200,
        ]);
        $expense->quotas()->create(['date_expected' => '2026-08-15', 'number' => 1, 'paid' => false, 'value_quota' => 100]);
        $expense->quotas()->create(['date_expected' => '2026-09-15', 'number' => 2, 'paid' => false, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 4,
                'total_value' => 400,
            ]);

        $response->assertStatus(200);
        $this->assertSame([
            ['number' => 1, 'date' => '2026-08-15', 'value' => '100.00', 'paid' => false],
            ['number' => 2, 'date' => '2026-09-15', 'value' => '100.00', 'paid' => false],
            ['number' => 3, 'date' => '2026-10-15', 'value' => '100.00', 'paid' => false],
            ['number' => 4, 'date' => '2026-11-15', 'value' => '100.00', 'paid' => false],
        ], $this->quotaRows($expense->id));
    }

    public function test_update_to_installments_without_quotas_and_without_installments_is_rejected(): void
    {
        [$creator, $expense] = $this->inCashExpenseWithOneQuota();

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['expense_type' => 'IN_INSTALLMENTS']);

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Informe installments para parcelar a despesa.');
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'expense_type' => 'IN_CASH']);
        $this->assertCount(1, $this->quotaRows($expense->id));
    }

    public function test_update_to_installments_without_quotas_rejects_more_than_one_hundred_and_twenty_installments(): void
    {
        [$creator, $expense] = $this->inCashExpenseWithOneQuota(['total_value' => 121]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['expense_type' => 'IN_INSTALLMENTS', 'installments' => 121]);

        $response->assertStatus(422)->assertJsonValidationErrors('installments');
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'expense_type' => 'IN_CASH']);
        $this->assertCount(1, $this->quotaRows($expense->id));
    }

    public function test_update_to_installments_without_quotas_accepts_exactly_one_hundred_and_twenty_installments(): void
    {
        [$creator, $expense] = $this->inCashExpenseWithOneQuota(['total_value' => 120]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['expense_type' => 'IN_INSTALLMENTS', 'installments' => 120]);

        $response->assertStatus(200);
        $this->assertCount(120, $this->quotaRows($expense->id));
    }

    public function test_the_installments_limit_does_not_apply_to_update_when_the_client_sends_its_own_quotas(): void
    {
        [$creator, $expense] = $this->inCashExpenseWithOneQuota(['total_value' => 121]);

        $quotas = [];
        for ($i = 1; $i <= 121; $i++) {
            $quotas[] = ['date_expected' => Carbon::parse('2026-08-15')->addMonthsNoOverflow($i - 1)->toDateString(), 'number' => $i, 'value_quota' => 1];
        }

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 121,
                'quotas' => $quotas,
            ]);

        $response->assertStatus(200);
        $this->assertCount(121, $this->quotaRows($expense->id));
    }

    public function test_update_to_installments_without_quotas_is_still_blocked_when_the_expense_is_paid(): void
    {
        [$creator, $expense] = $this->inCashExpenseWithOneQuota();
        $expense->quotas()->update(['paid' => true]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['expense_type' => 'IN_INSTALLMENTS', 'installments' => 3]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'expense_type' => 'IN_CASH']);
        $this->assertSame(
            [['number' => 1, 'date' => '2026-08-15', 'value' => '100.00', 'paid' => true]],
            $this->quotaRows($expense->id)
        );
    }
}
