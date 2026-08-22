<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseControllerSummaryTest extends TestCase
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

    public function test_default_cycle_is_the_current_calendar_month_and_is_open(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $participant->id]);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-05']);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-05', 'number' => 1, 'paid' => true, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200)
            ->assertJsonPath('cycle.start', '2026-08-01')
            ->assertJsonPath('cycle.end', '2026-08-31')
            ->assertJsonPath('cycle.status', 'open')
            ->assertJsonPath('totals.total', 100)
            ->assertJsonPath('totals.paid', 100)
            ->assertJsonPath('totals.pending', 0);
    }

    public function test_expense_entries_include_creditor_and_creator_ids(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $creator->id]);

        $expense = $this->createExpense($group, $payer, [
            'date_payment' => '2026-08-05',
            'user_creator_id' => $creator->id,
            'user_payer_id' => $payer->id,
        ]);
        $expense->payers()->sync([$payer->id, $creator->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-05', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200)->assertJsonFragment([
            'id' => $expense->id,
            'userPayerId' => $payer->id,
            'userCreatorId' => $creator->id,
        ]);
    }

    public function test_future_cycle_without_expenses_returns_zero_totals(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=-1");

        $response->assertStatus(200)
            ->assertJsonPath('cycle.start', '2026-09-01')
            ->assertJsonPath('cycle.end', '2026-09-30')
            ->assertJsonPath('cycle.status', 'future')
            ->assertJsonPath('totals.total', 0)
            ->assertJsonPath('expenses', [])
            ->assertJsonPath('balances.0.balance', 0);
    }

    public function test_installment_with_date_expected_in_a_later_cycle_still_appears(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $participant->id]);

        $expense = $this->createExpense($group, $payer, [
            'date_payment' => '2026-07-01',
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 3,
            'total_value' => 300,
        ]);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-07-01', 'number' => 1, 'paid' => true, 'value_quota' => 100]);
        $expense->quotas()->create(['date_expected' => '2026-08-01', 'number' => 2, 'paid' => false, 'value_quota' => 100]);
        $expense->quotas()->create(['date_expected' => '2026-09-01', 'number' => 3, 'paid' => false, 'value_quota' => 100]);

        // Ciclo futuro (setembro): a despesa foi criada (date_payment) em julho,
        // mas a 3ª parcela vence em setembro — precisa aparecer mesmo assim.
        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=-1");

        $response->assertStatus(200)->assertJsonFragment([
            'id' => $expense->id,
            'date' => '2026-09-01',
            'value' => 100,
            'paid' => false,
        ]);
    }

    public function test_installment_row_shows_quota_value_and_paid_status_not_total(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'closing_day' => 15]);
        $group->members()->attach([$payer->id, $participant->id]);

        $expense = $this->createExpense($group, $payer, [
            'date_payment' => '2026-07-20',
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 3,
            'total_value' => 900,
        ]);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-07-20', 'number' => 1, 'paid' => false, 'value_quota' => 300]);
        $expense->quotas()->create(['date_expected' => '2026-08-20', 'number' => 2, 'paid' => false, 'value_quota' => 300]);
        $expense->quotas()->create(['date_expected' => '2026-09-20', 'number' => 3, 'paid' => false, 'value_quota' => 300]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200)->assertJsonFragment([
            'id' => $expense->id,
            'value' => 300,
            'paid' => false,
        ]);
    }

    public function test_fixed_expense_projected_without_own_quota_defaults_to_pending(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'closing_day' => 15]);
        $group->members()->attach([$payer->id, $participant->id]);

        $expense = $this->createExpense($group, $payer, [
            'date_payment' => '2026-06-05',
            'expense_type' => 'FIXED',
            'total_value' => 500,
        ]);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-06-05', 'number' => 1, 'paid' => false, 'value_quota' => 500]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        // Ciclo corrente (aberto): 16/ago-15/set; a ocorrência projetada cai em 05/set, sem Quota própria.
        $response->assertStatus(200)->assertJsonFragment([
            'id' => $expense->id,
            'date' => '2026-09-05',
            'value' => 500,
            'paid' => false,
            'isFixed' => true,
        ]);
    }

    public function test_fixed_expense_uses_materialized_quota_value_when_present(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'closing_day' => 15]);
        $group->members()->attach([$payer->id, $participant->id]);

        $expense = $this->createExpense($group, $payer, [
            'date_payment' => '2026-06-05',
            'expense_type' => 'FIXED',
            'total_value' => 500,
        ]);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-06-05', 'number' => 1, 'paid' => false, 'value_quota' => 500]);

        // Ocorrência do ciclo corrente (16/ago-15/set cai em 05/set) já foi congelada
        // com um valor diferente do total_value atual — o resumo deve refletir isso,
        // não o total_value ao vivo (500).
        $expense->quotas()->create(['date_expected' => '2026-09-05', 'number' => 1, 'paid' => true, 'value_quota' => 350]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200)->assertJsonFragment([
            'id' => $expense->id,
            'date' => '2026-09-05',
            'value' => 350,
            'paid' => true,
            'isFixed' => true,
        ]);
    }

    public function test_balances_include_every_member_and_sum_to_zero(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $uninvolved = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $participant->id, $uninvolved->id]);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-10', 'total_value' => 200]);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => true, 'value_quota' => 200]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200)
            ->assertJsonFragment(['user_id' => $payer->id, 'balance' => 100])
            ->assertJsonFragment(['user_id' => $participant->id, 'balance' => -100])
            ->assertJsonFragment(['user_id' => $uninvolved->id, 'balance' => 0]);
    }

    public function test_balances_are_unchanged_after_the_fixed_occurrence_quota_is_materialized(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $participant->id]);

        $expense = $this->createExpense($group, $payer, [
            'date_payment' => '2026-06-05',
            'expense_type' => 'FIXED',
            'total_value' => 200,
        ]);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-06-05', 'number' => 1, 'paid' => false, 'value_quota' => 200]);

        $before = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $before->assertStatus(200);
        $balancesBefore = $before->json('balances');

        // Materializa a ocorrência do mês corrente (agosto) exatamente como
        // materializeFixedOccurrenceQuota faria — a origem do dado (Quota
        // real em vez de projeção ao vivo) muda, mas o valor é o mesmo.
        $expense->quotas()->create(['date_expected' => '2026-08-05', 'number' => 1, 'paid' => false, 'value_quota' => 200]);

        $after = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $after->assertStatus(200);
        $balancesAfter = $after->json('balances');

        $this->assertSame($balancesBefore, $balancesAfter);
        $after->assertJsonFragment(['user_id' => $payer->id, 'balance' => 100])
            ->assertJsonFragment(['user_id' => $participant->id, 'balance' => -100]);
    }

    public function test_cycles_ago_navigates_to_previous_closed_cycle(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $juneExpense = $this->createExpense($group, $payer, ['date_payment' => '2026-06-10', 'total_value' => 50]);
        $juneExpense->payers()->sync([$payer->id]);
        $juneExpense->quotas()->create(['date_expected' => '2026-06-10', 'number' => 1, 'paid' => true, 'value_quota' => 50]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=2");

        $response->assertStatus(200)
            ->assertJsonPath('cycle.start', '2026-06-01')
            ->assertJsonPath('cycle.end', '2026-06-30')
            ->assertJsonPath('cycle.status', 'closed')
            ->assertJsonPath('totals.total', 50);
    }

    public function test_non_member_cannot_view_group_summary(): void
    {
        $outsider = User::factory()->create();
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($outsider))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(404);
    }
}
