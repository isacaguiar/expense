<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Quota;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseControllerPayTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    private function createExpense(Group $group, User $creditor, array $overrides = []): Expense
    {
        return Expense::create(array_merge([
            'create_date' => now(),
            'date_payment' => '2026-08-01',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ], $overrides));
    }

    public function test_creditor_can_pay_in_cash_expense(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $participant->id]);

        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-08-10']);
        $expense->payers()->sync([$creditor->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/expenses/{$expense->id}/pay");

        $response->assertStatus(200)->assertJsonPath('paid', true);

        $quota = Quota::where('expense_id', $expense->id)->firstOrFail();
        $this->assertTrue((bool) $quota->paid);
        $this->assertNotNull($quota->paid_at);
        $this->assertSame($creditor->id, $quota->paid_by);
    }

    public function test_creditor_can_pay_fixed_expense_materializing_the_current_month_quota(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        $expense = $this->createExpense($group, $creditor, [
            'date_payment' => '2026-06-05',
            'expense_type' => 'FIXED',
            'total_value' => 300,
        ]);
        $expense->payers()->attach($creditor->id);
        $expense->quotas()->create(['date_expected' => '2026-06-05', 'number' => 1, 'paid' => false, 'value_quota' => 300]);

        $this->assertSame(1, Quota::where('expense_id', $expense->id)->count());

        $response = $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/expenses/{$expense->id}/pay");

        $response->assertStatus(200)->assertJsonPath('paid', true);

        $this->assertSame(2, Quota::where('expense_id', $expense->id)->count());
        $this->assertDatabaseHas('ex_quotas', [
            'expense_id' => $expense->id,
            'date_expected' => '2026-08-05',
            'paid' => true,
        ]);
    }

    public function test_non_creditor_cannot_pay(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $participant->id]);

        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-08-10']);
        $expense->payers()->sync([$creditor->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($participant))
            ->postJson("/api/expenses/{$expense->id}/pay");

        $response->assertStatus(403);
        $this->assertDatabaseHas('ex_quotas', ['expense_id' => $expense->id, 'paid' => false]);
    }

    public function test_pay_fails_when_expense_has_no_occurrence_in_the_current_competence(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-07-10']);
        $expense->payers()->attach($creditor->id);
        $expense->quotas()->create(['date_expected' => '2026-07-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        // "Agora" é agosto — a despesa foi lançada em julho (IN_CASH, não
        // recorrente) e pay() sempre opera sobre a competência vigente
        // (agosto), onde ela não tem ocorrência nenhuma.
        $response = $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/expenses/{$expense->id}/pay");

        $response->assertStatus(422);
    }

    public function test_cannot_pay_in_a_manually_closed_cycle(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-08-10']);
        $expense->payers()->attach($creditor->id);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'totals' => ['total' => 0, 'paid' => 0, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => now(),
        ]);

        $response = $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/expenses/{$expense->id}/pay");

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_quotas', ['expense_id' => $expense->id, 'paid' => false]);
    }

    public function test_pay_requires_group_membership(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-08-10']);
        $expense->payers()->attach($creditor->id);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($outsider))
            ->postJson("/api/expenses/{$expense->id}/pay");

        $response->assertStatus(404);
    }
}
