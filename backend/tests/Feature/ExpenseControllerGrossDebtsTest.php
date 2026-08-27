<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseControllerGrossDebtsTest extends TestCase
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

    private function createExpense(Group $group, User $payer, array $overrides = []): Expense
    {
        return Expense::create(array_merge([
            'create_date' => now(),
            'date_payment' => '2026-08-01',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $payer->id,
            'user_payer_id' => $payer->id,
            'deleted' => false,
        ], $overrides));
    }

    public function test_cycle_without_pending_expenses_returns_an_empty_tree(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/gross-debts");

        $response->assertStatus(200)
            ->assertJsonPath('creditors', []);
    }

    public function test_a_creditor_with_multiple_debtors_shows_each_gross_share(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create(['name' => 'Credor', 'pix' => 'credor@pix.example']);
        $debtorA = User::factory()->create(['name' => 'Devedor A']);
        $debtorB = User::factory()->create(['name' => 'Devedor B']);
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $debtorA->id, $debtorB->id]);

        // 300 divididos entre 3 (credor + 2 devedores) = 100 cada.
        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-05', 'total_value' => 300]);
        $expense->payers()->sync([$payer->id, $debtorA->id, $debtorB->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-05', 'number' => 1, 'paid' => false, 'value_quota' => 300]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/gross-debts");

        $response->assertStatus(200);
        $creditors = $response->json('creditors');
        $this->assertCount(1, $creditors);
        $this->assertSame($payer->id, $creditors[0]['creditor']['id']);
        $this->assertSame($payer->email, $creditors[0]['creditor']['email']);
        $this->assertSame('credor@pix.example', $creditors[0]['creditor']['pix']);

        $debtors = collect($creditors[0]['debtors'])->keyBy('id');
        $this->assertSame(100, $debtors[$debtorA->id]['amount']);
        $this->assertSame(100, $debtors[$debtorB->id]['amount']);
        $this->assertFalse($debtors->has($payer->id), 'O credor não deve aparecer como seu próprio devedor.');
    }

    public function test_already_paid_expense_does_not_appear(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $debtor->id]);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-05', 'total_value' => 200]);
        $expense->payers()->sync([$payer->id, $debtor->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-05', 'number' => 1, 'paid' => true, 'value_quota' => 200]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/gross-debts");

        $response->assertStatus(200)
            ->assertJsonPath('creditors', []);
    }

    public function test_cycles_ago_navigates_to_a_previous_cycle(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $debtor->id]);

        $juneExpense = $this->createExpense($group, $payer, ['date_payment' => '2026-06-10', 'total_value' => 60]);
        $juneExpense->payers()->sync([$payer->id, $debtor->id]);
        $juneExpense->quotas()->create(['date_expected' => '2026-06-10', 'number' => 1, 'paid' => false, 'value_quota' => 60]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/gross-debts?cycles_ago=2");

        $response->assertStatus(200)
            ->assertJsonPath('cycle.start', '2026-06-01')
            ->assertJsonPath('cycle.end', '2026-06-30');

        $debtors = collect($response->json('creditors.0.debtors'))->keyBy('id');
        $this->assertSame(30, $debtors[$debtor->id]['amount']);
    }

    public function test_non_member_cannot_view_gross_debts(): void
    {
        $outsider = User::factory()->create();
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($outsider))
            ->getJson("/api/groups/{$group->id}/expenses/gross-debts");

        $response->assertStatus(404);
    }
}
