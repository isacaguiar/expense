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
     * TASK-400 (docs/feature/concluidas/202610/20261008-rateio-parcelas-no-backend/): ao trocar para
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

    /**
     * TASK-401 (docs/feature/concluidas/202610/20261008-rateio-parcelas-no-backend/): editar valor,
     * data ou nº de parcelas de uma despesa não-fixa sem parcela paga refaz as
     * quotas no servidor, sem precisar mandar `expense_type` nem `quotas`.
     *
     * @param  list<array{date_expected: string, value_quota: float|int}>  $rows
     * @return array{0: User, 1: Expense}
     */
    private function installmentsExpenseWithQuotas(array $rows, array $overrides = []): array
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator, array_merge([
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => count($rows),
            'total_value' => array_sum(array_column($rows, 'value_quota')),
        ], $overrides));

        foreach ($rows as $i => $row) {
            $expense->quotas()->create($row + ['number' => $i + 1, 'paid' => false]);
        }

        return [$creator, $expense];
    }

    private function twoInstallmentsOfOneHundred(): array
    {
        return $this->installmentsExpenseWithQuotas([
            ['date_expected' => '2026-08-15', 'value_quota' => 100],
            ['date_expected' => '2026-09-15', 'value_quota' => 100],
        ]);
    }

    /**
     * @return list<int>
     */
    private function quotaIds(Expense $expense): array
    {
        return $expense->quotas()->orderBy('number')->pluck('id')->all();
    }

    public function test_update_of_only_the_total_value_regenerates_the_single_quota_of_an_in_cash_expense(): void
    {
        [$creator, $expense] = $this->inCashExpenseWithOneQuota(['total_value' => 100]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['total_value' => 150]);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'quotas');
        $this->assertSame(
            [['number' => 1, 'date' => '2026-08-15', 'value' => '150.00', 'paid' => false]],
            $this->quotaRows($expense->id)
        );
    }

    public function test_update_of_only_the_date_moves_the_quota_of_an_in_cash_expense(): void
    {
        [$creator, $expense] = $this->inCashExpenseWithOneQuota(['total_value' => 100]);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['date_payment' => '2026-08-20']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'date_payment' => '2026-08-20']);
        $this->assertSame(
            [['number' => 1, 'date' => '2026-08-20', 'value' => '100.00', 'paid' => false]],
            $this->quotaRows($expense->id)
        );
    }

    public function test_update_of_only_the_total_value_resplits_an_unpaid_installments_expense(): void
    {
        [$creator, $expense] = $this->twoInstallmentsOfOneHundred();

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['total_value' => 300]);

        $response->assertStatus(200);
        $this->assertSame([
            ['number' => 1, 'date' => '2026-08-15', 'value' => '150.00', 'paid' => false],
            ['number' => 2, 'date' => '2026-09-15', 'value' => '150.00', 'paid' => false],
        ], $this->quotaRows($expense->id));
    }

    public function test_update_of_only_the_installments_count_resplits_an_unpaid_installments_expense(): void
    {
        [$creator, $expense] = $this->twoInstallmentsOfOneHundred();

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['installments' => 4]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'installments' => 4]);
        $this->assertSame([
            ['number' => 1, 'date' => '2026-08-15', 'value' => '50.00', 'paid' => false],
            ['number' => 2, 'date' => '2026-09-15', 'value' => '50.00', 'paid' => false],
            ['number' => 3, 'date' => '2026-10-15', 'value' => '50.00', 'paid' => false],
            ['number' => 4, 'date' => '2026-11-15', 'value' => '50.00', 'paid' => false],
        ], $this->quotaRows($expense->id));
    }

    public function test_update_of_only_the_date_recomputes_the_dates_of_an_unpaid_installments_expense(): void
    {
        [$creator, $expense] = $this->twoInstallmentsOfOneHundred();

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['date_payment' => '2026-08-20']);

        $response->assertStatus(200);
        $this->assertSame([
            ['number' => 1, 'date' => '2026-08-20', 'value' => '100.00', 'paid' => false],
            ['number' => 2, 'date' => '2026-09-20', 'value' => '100.00', 'paid' => false],
        ], $this->quotaRows($expense->id));
    }

    public function test_update_that_resends_the_stored_values_keeps_the_same_quotas(): void
    {
        [$creator, $expense] = $this->twoInstallmentsOfOneHundred();
        $idsBefore = $this->quotaIds($expense);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", [
                'total_value' => '200.00',
                'date_payment' => '2026-08-15',
                'installments' => 2,
            ]);

        $response->assertStatus(200);
        $this->assertSame($idsBefore, $this->quotaIds($expense));
    }

    public function test_update_of_only_the_description_keeps_the_same_quotas(): void
    {
        [$creator, $expense] = $this->twoInstallmentsOfOneHundred();
        $idsBefore = $this->quotaIds($expense);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['description' => 'Descrição nova']);

        $response->assertStatus(200);
        $this->assertSame($idsBefore, $this->quotaIds($expense));
    }

    public function test_update_of_the_total_value_of_a_fixed_expense_leaves_its_quotas_alone(): void
    {
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creator->id);
        $expense = $this->createExpense($group, $creator, $creator, [
            'expense_type' => 'FIXED',
            'date_payment' => '2026-06-05',
            'total_value' => 300,
        ]);
        $expense->quotas()->create(['date_expected' => '2026-06-05', 'number' => 1, 'paid' => false, 'value_quota' => 300]);
        $idsBefore = $this->quotaIds($expense);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['total_value' => 350, 'date_payment' => '2026-06-10']);

        $response->assertStatus(200);
        $this->assertSame($idsBefore, $this->quotaIds($expense));
        $this->assertSame(
            [['number' => 1, 'date' => '2026-06-05', 'value' => '300.00', 'paid' => false]],
            $this->quotaRows($expense->id)
        );
    }

    public function test_update_of_the_date_of_a_paid_expense_does_not_regenerate_its_quotas(): void
    {
        [$creator, $expense] = $this->inCashExpenseWithOneQuota(['total_value' => 100]);
        $expense->quotas()->update(['paid' => true]);
        $idsBefore = $this->quotaIds($expense);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['date_payment' => '2026-08-20']);

        $response->assertStatus(200);
        $this->assertSame($idsBefore, $this->quotaIds($expense));
        $this->assertSame(
            [['number' => 1, 'date' => '2026-08-15', 'value' => '100.00', 'paid' => true]],
            $this->quotaRows($expense->id)
        );
    }

    public function test_update_of_only_the_installments_count_rejects_more_than_one_hundred_and_twenty(): void
    {
        [$creator, $expense] = $this->twoInstallmentsOfOneHundred();
        $idsBefore = $this->quotaIds($expense);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['installments' => 121]);

        $response->assertStatus(422)->assertJsonValidationErrors('installments');
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'installments' => 2]);
        $this->assertSame($idsBefore, $this->quotaIds($expense));
    }

    public function test_update_that_would_regenerate_a_legacy_expense_with_more_than_one_hundred_and_twenty_installments_is_rejected(): void
    {
        $rows = [];
        for ($i = 1; $i <= 121; $i++) {
            $rows[] = ['date_expected' => Carbon::parse('2026-08-15')->addMonthsNoOverflow($i - 1)->toDateString(), 'value_quota' => 1];
        }
        [$creator, $expense] = $this->installmentsExpenseWithQuotas($rows);
        $idsBefore = $this->quotaIds($expense);

        $rejected = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['total_value' => 242]);

        $rejected->assertStatus(422)->assertJsonValidationErrors('installments');
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'total_value' => 121]);
        $this->assertSame($idsBefore, $this->quotaIds($expense));

        // Editar só a descrição não dispara a regeneração e continua permitido.
        $accepted = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['description' => 'Descrição nova']);

        $accepted->assertStatus(200);
        $this->assertSame($idsBefore, $this->quotaIds($expense));
    }

    /**
     * TASK-402 (docs/feature/concluidas/202610/20261008-rateio-parcelas-no-backend/): GET
     * /api/expenses/{id} devolve `value_per_person` em cada quota, com a mesma
     * fórmula do `valuePerPerson` de computeCycleSummary() — round(valor ÷
     * max(pagadores, 1), 2).
     *
     * @param  list<float|int|string>  $quotaValues
     * @return array{0: User, 1: Expense}
     */
    private function expenseWithPayers(int $payersCount, array $quotaValues, array $overrides = []): array
    {
        $viewer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($viewer->id);
        $expense = $this->createExpense($group, $viewer, $viewer, array_merge([
            'expense_type' => count($quotaValues) > 1 ? 'IN_INSTALLMENTS' : 'IN_CASH',
            'installments' => count($quotaValues),
            'total_value' => array_sum($quotaValues),
        ], $overrides));
        $expense->payers()->detach();

        for ($i = 0; $i < $payersCount; $i++) {
            $payer = $i === 0 ? $viewer : User::factory()->create();
            $group->members()->syncWithoutDetaching([$payer->id]);
            $expense->payers()->attach($payer->id);
        }

        foreach (array_values($quotaValues) as $i => $value) {
            $expense->quotas()->create([
                'date_expected' => Carbon::parse('2026-08-15')->addMonthsNoOverflow($i)->toDateString(),
                'number' => $i + 1,
                'paid' => false,
                'value_quota' => $value,
            ]);
        }

        return [$viewer, $expense];
    }

    /**
     * @return list<float|int>
     */
    private function valuePerPersonByQuota(User $viewer, Expense $expense): array
    {
        $response = $this->withToken($this->tokenFor($viewer))->getJson("/api/expenses/{$expense->id}");
        $response->assertStatus(200);

        return collect($response->json('quotas'))->sortBy('number')->pluck('value_per_person')->values()->all();
    }

    public function test_show_returns_the_value_per_person_of_each_quota_split_between_two_payers(): void
    {
        [$viewer, $expense] = $this->expenseWithPayers(2, [33.33, 33.33, 33.34]);

        $this->assertSame([16.67, 16.67, 16.67], $this->valuePerPersonByQuota($viewer, $expense));
    }

    public function test_show_returns_the_whole_quota_value_when_there_is_a_single_payer(): void
    {
        [$viewer, $expense] = $this->expenseWithPayers(1, [33.33, 33.33, 33.34]);

        $this->assertSame([33.33, 33.33, 33.34], $this->valuePerPersonByQuota($viewer, $expense));
    }

    public function test_show_splits_a_quota_between_three_payers(): void
    {
        [$viewer, $expense] = $this->expenseWithPayers(3, [100]);

        $this->assertSame([33.33], $this->valuePerPersonByQuota($viewer, $expense));
    }

    public function test_show_uses_a_divisor_of_one_when_the_expense_has_no_payers(): void
    {
        [$viewer, $expense] = $this->expenseWithPayers(0, [100]);

        // O JSON não preserva a fração zero: 100.0 chega como 100.
        $this->assertEquals([100], $this->valuePerPersonByQuota($viewer, $expense));
    }

    public function test_show_value_per_person_matches_the_one_in_the_cycle_summary(): void
    {
        // 0,29 ÷ 2 = 0,145: o round() do PHP dá 0,15 (o perPersonValue() do web
        // dava 0,14). A API segue o resumo, não o web.
        [$viewer, $expense] = $this->expenseWithPayers(2, [0.29]);

        $summary = $this->withToken($this->tokenFor($viewer))
            ->getJson("/api/groups/{$expense->group_id}/expenses/summary");
        $summary->assertStatus(200);

        $fromSummary = collect($summary->json('expenses'))->firstWhere('id', $expense->id)['valuePerPerson'];

        $this->assertSame(0.15, $fromSummary);
        $this->assertSame([$fromSummary], $this->valuePerPersonByQuota($viewer, $expense));
    }

    public function test_show_keeps_every_existing_field_of_the_quota(): void
    {
        [$viewer, $expense] = $this->expenseWithPayers(2, [33.33, 33.33, 33.34]);

        $response = $this->withToken($this->tokenFor($viewer))->getJson("/api/expenses/{$expense->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('total_value', '100.00');
        $response->assertJsonCount(2, 'payers');
        $response->assertJsonCount(3, 'quotas');
        $quota = collect($response->json('quotas'))->sortBy('number')->first();
        $this->assertSame('33.33', $quota['value_quota']);
        $this->assertSame(1, $quota['number']);
        $this->assertFalse($quota['paid']);
        $this->assertFalse($quota['born_paid']);
        $this->assertSame($expense->id, $quota['expense_id']);
        $this->assertArrayHasKey('date_expected', $quota);
        $this->assertArrayHasKey('payment_proof_url', $quota);
        $this->assertArrayNotHasKey('expense', $quota);
    }

    public function test_show_runs_the_same_number_of_queries_with_one_and_with_ten_quotas(): void
    {
        [$viewerOne, $expenseOne] = $this->expenseWithPayers(2, [100]);
        [$viewerTen, $expenseTen] = $this->expenseWithPayers(2, array_fill(0, 10, 10));
        // Comprovante em todas as quotas: força o accessor payment_proof_url, que
        // precisa de expense->group_id (o ponto onde um N+1 apareceria).
        \App\Models\Quota::whereIn('expense_id', [$expenseOne->id, $expenseTen->id])->update(['payment_proof_path' => 'comprovante.jpg']);

        // Aquece o que é cacheado na primeira requisição, fora da contagem.
        $this->withToken($this->tokenFor($viewerOne))->getJson("/api/expenses/{$expenseOne->id}")->assertStatus(200);

        $queries = function (User $viewer, Expense $expense): int {
            $token = $this->tokenFor($viewer);
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $this->withToken($token)->getJson("/api/expenses/{$expense->id}")->assertStatus(200);
            $count = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::disableQueryLog();

            return $count;
        };

        $this->assertSame($queries($viewerOne, $expenseOne), $queries($viewerTen, $expenseTen));
    }

    /**
     * TASK-404: o update() também recusa o rateio gerado que passaria do ano 9999
     * (achado A1 da revisão de segurança): antes, o PUT devolvia 200 e gravava as
     * quotas 2 a 120 com datas erradas (2000-01-31, 2000-02-29, ...).
     */
    public function test_update_to_installments_without_quotas_is_rejected_when_the_schedule_passes_the_year_9999(): void
    {
        [$creator, $expense] = $this->inCashExpenseWithOneQuota();
        $idsBefore = $this->quotaIds($expense);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 2,
                'date_payment' => '9999-12-31',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('date_payment');
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'expense_type' => 'IN_CASH', 'date_payment' => '2026-08-15']);
        $this->assertSame($idsBefore, $this->quotaIds($expense));
    }

    public function test_update_of_only_the_date_is_rejected_when_the_regenerated_schedule_passes_the_year_9999(): void
    {
        [$creator, $expense] = $this->twoInstallmentsOfOneHundred();
        $idsBefore = $this->quotaIds($expense);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['date_payment' => '9999-12-31']);

        $response->assertStatus(422)->assertJsonValidationErrors('date_payment');
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'date_payment' => '2026-08-15']);
        $this->assertSame($idsBefore, $this->quotaIds($expense));
        $this->assertSame([
            ['number' => 1, 'date' => '2026-08-15', 'value' => '100.00', 'paid' => false],
            ['number' => 2, 'date' => '2026-09-15', 'value' => '100.00', 'paid' => false],
        ], $this->quotaRows($expense->id));
    }

    public function test_update_accepts_a_schedule_whose_last_installment_is_exactly_the_last_valid_date(): void
    {
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = ['date_expected' => Carbon::parse('2026-08-15')->addMonthsNoOverflow($i)->toDateString(), 'value_quota' => 10];
        }
        [$creator, $expense] = $this->installmentsExpenseWithQuotas($rows);

        $response = $this->withToken($this->tokenFor($creator))
            ->putJson("/api/expenses/{$expense->id}", ['date_payment' => '9999-01-31']);

        $response->assertStatus(200);
        $quotas = $this->quotaRows($expense->id);
        $this->assertCount(12, $quotas);
        $this->assertSame('9999-01-31', $quotas[0]['date']);
        $this->assertSame('9999-12-31', $quotas[11]['date']);
    }

    /**
     * TASK-405 (achado A1 da revisão de segurança): o update() grava a despesa, os
     * pagadores e as quotas numa transação — uma falha no meio da recriação das
     * quotas não pode deixar a despesa com quotas parciais ou sem nenhuma.
     */
    private function failWhenQuotaNumberTwoIsCreated(): void
    {
        \App\Models\Quota::creating(function (\App\Models\Quota $quota) {
            if ($quota->number === 2) {
                throw new \RuntimeException('falha simulada na criação da quota 2');
            }
        });
    }

    public function test_update_is_atomic_when_the_regeneration_of_the_quotas_fails_midway(): void
    {
        [$creator, $expense] = $this->twoInstallmentsOfOneHundred();
        $other = User::factory()->create();
        Group::find($expense->group_id)->members()->attach($other->id);
        $idsBefore = $this->quotaIds($expense);
        $this->failWhenQuotaNumberTwoIsCreated();

        try {
            $response = $this->withToken($this->tokenFor($creator))
                ->putJson("/api/expenses/{$expense->id}", [
                    'total_value' => 300,
                    'description' => 'Descrição que não pode ficar',
                    'payers' => [$other->id],
                ]);
        } finally {
            \App\Models\Quota::flushEventListeners();
        }

        $response->assertStatus(500);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'total_value' => 200, 'description' => 'Despesa de teste']);
        $this->assertSame([$creator->id], $expense->payers()->pluck('ex_users.id')->all());
        $this->assertSame($idsBefore, $this->quotaIds($expense));
        $this->assertSame([
            ['number' => 1, 'date' => '2026-08-15', 'value' => '100.00', 'paid' => false],
            ['number' => 2, 'date' => '2026-09-15', 'value' => '100.00', 'paid' => false],
        ], $this->quotaRows($expense->id));
    }

    public function test_update_is_atomic_when_the_type_change_fails_midway(): void
    {
        [$creator, $expense] = $this->inCashExpenseWithOneQuota(['total_value' => 100]);
        $idsBefore = $this->quotaIds($expense);
        $this->failWhenQuotaNumberTwoIsCreated();

        try {
            $response = $this->withToken($this->tokenFor($creator))
                ->putJson("/api/expenses/{$expense->id}", ['expense_type' => 'IN_INSTALLMENTS', 'installments' => 2]);
        } finally {
            \App\Models\Quota::flushEventListeners();
        }

        $response->assertStatus(500);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'expense_type' => 'IN_CASH', 'installments' => 1]);
        $this->assertSame($idsBefore, $this->quotaIds($expense));
        $this->assertSame(
            [['number' => 1, 'date' => '2026-08-15', 'value' => '100.00', 'paid' => false]],
            $this->quotaRows($expense->id)
        );
    }

    public function test_update_is_atomic_when_the_payers_sync_fails(): void
    {
        [$creator, $expense] = $this->twoInstallmentsOfOneHundred();
        $other = User::factory()->create();
        Group::find($expense->group_id)->members()->attach($other->id);
        $idsBefore = $this->quotaIds($expense);

        // Falha na gravação dos pagadores, que vem depois da despesa e antes das quotas.
        $armed = true;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$armed) {
            if ($armed && str_starts_with($query->sql, 'insert into `ex_expenses_payers`')) {
                throw new \RuntimeException('falha simulada na gravação dos pagadores');
            }
        });

        try {
            $response = $this->withToken($this->tokenFor($creator))
                ->putJson("/api/expenses/{$expense->id}", ['total_value' => 300, 'payers' => [$other->id]]);
        } finally {
            $armed = false;
        }

        $response->assertStatus(500);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'total_value' => 200]);
        $this->assertSame([$creator->id], $expense->payers()->pluck('ex_users.id')->all());
        $this->assertSame($idsBefore, $this->quotaIds($expense));
    }

    /**
     * TASK-403 (achado A4 da revisão de segurança): a autorização vem antes de
     * qualquer geração ou escrita. Os testes antigos de não-membro mandavam só
     * `description`; estes mandam os campos que disparam a regeneração das quotas.
     */
    public function test_non_member_cannot_regenerate_the_quotas_of_an_expense(): void
    {
        [$creator, $expense] = $this->twoInstallmentsOfOneHundred();
        $outsider = User::factory()->create();
        $idsBefore = $this->quotaIds($expense);

        $response = $this->withToken($this->tokenFor($outsider))
            ->putJson("/api/expenses/{$expense->id}", ['total_value' => 999, 'date_payment' => '2026-08-20', 'installments' => 5]);

        $response->assertStatus(404);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'total_value' => 200, 'date_payment' => '2026-08-15', 'installments' => 2]);
        $this->assertSame($idsBefore, $this->quotaIds($expense));
    }

    public function test_member_who_is_not_creator_nor_payer_cannot_regenerate_the_quotas_of_an_expense(): void
    {
        [$creator, $expense] = $this->twoInstallmentsOfOneHundred();
        $member = User::factory()->create();
        Group::find($expense->group_id)->members()->attach($member->id);
        $idsBefore = $this->quotaIds($expense);

        $response = $this->withToken($this->tokenFor($member))
            ->putJson("/api/expenses/{$expense->id}", ['total_value' => 999, 'date_payment' => '2026-08-20', 'installments' => 5]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'total_value' => 200, 'date_payment' => '2026-08-15', 'installments' => 2]);
        $this->assertSame($idsBefore, $this->quotaIds($expense));
    }
}
