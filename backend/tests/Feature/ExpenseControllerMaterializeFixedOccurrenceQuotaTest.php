<?php

namespace Tests\Feature;

use App\Http\Controllers\ExpenseController;
use App\Models\Expense;
use App\Models\Group;
use App\Models\Quota;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

class ExpenseControllerMaterializeFixedOccurrenceQuotaTest extends TestCase
{
    use DatabaseTransactions;

    private function createFixedExpense(Group $group, User $payer, array $overrides = []): Expense
    {
        return Expense::create(array_merge([
            'create_date' => now(),
            'date_payment' => '2026-06-05',
            'description' => 'Internet',
            'expense_type' => 'FIXED',
            'installments' => 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $payer->id,
            'user_payer_id' => $payer->id,
            'deleted' => false,
        ], $overrides));
    }

    private function materialize(Expense $expense, Carbon $occurrenceDate): Quota
    {
        $method = new ReflectionMethod(ExpenseController::class, 'materializeFixedOccurrenceQuota');
        $method->setAccessible(true);

        return $method->invoke(new ExpenseController, $expense, $occurrenceDate);
    }

    public function test_materializes_a_quota_with_the_current_value_for_a_month_without_one(): void
    {
        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createFixedExpense($group, $payer, ['total_value' => 120]);

        $quota = $this->materialize($expense, Carbon::parse('2026-08-05'));

        $this->assertDatabaseHas('ex_quotas', [
            'expense_id' => $expense->id,
            'date_expected' => '2026-08-05',
            'value_quota' => 120,
            'paid' => false,
        ]);
        $this->assertSame('2026-08-05', $quota->date_expected->toDateString());
    }

    public function test_calling_again_for_the_same_month_does_not_duplicate(): void
    {
        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createFixedExpense($group, $payer, ['total_value' => 100]);

        $first = $this->materialize($expense, Carbon::parse('2026-08-05'));
        $second = $this->materialize($expense, Carbon::parse('2026-08-05'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Quota::where('expense_id', $expense->id)->count());
    }

    public function test_materialized_value_stays_frozen_after_the_expense_total_value_changes(): void
    {
        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createFixedExpense($group, $payer, ['total_value' => 100]);

        $this->materialize($expense, Carbon::parse('2026-08-05'));

        $expense->update(['total_value' => 150]);

        $again = $this->materialize($expense->fresh(), Carbon::parse('2026-08-05'));

        $this->assertEquals(100, $again->value_quota);
    }
}
