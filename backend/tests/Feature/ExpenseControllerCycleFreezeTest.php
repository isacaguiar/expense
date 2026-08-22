<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseControllerCycleFreezeTest extends TestCase
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

    public function test_closed_cycle_snapshot_is_stable_after_expense_value_is_edited(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-07-10', 'total_value' => 100]);
        $expense->payers()->sync([$payer->id]);
        $expense->quotas()->create(['date_expected' => '2026-07-10', 'number' => 1, 'paid' => true, 'value_quota' => 100]);

        $first = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1");

        $first->assertStatus(200)
            ->assertJsonPath('cycle.status', 'closed')
            ->assertJsonPath('totals.total', 100);

        // Simula uma edição posterior ao fechamento (o bloqueio de API impede isso para
        // IN_CASH, mas o ponto aqui é provar que, mesmo que o dado ao vivo mude, a foto
        // já persistida não é afetada).
        $expense->update(['total_value' => 999]);
        $expense->quotas()->first()->update(['value_quota' => 999]);

        $second = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1");

        $second->assertStatus(200)
            ->assertJsonPath('cycle.status', 'closed')
            ->assertJsonPath('totals.total', 100);
    }

    public function test_deleting_expense_after_closed_cycle_snapshot_does_not_change_it(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-07-10', 'total_value' => 150]);
        $expense->payers()->sync([$payer->id]);
        $expense->quotas()->create(['date_expected' => '2026-07-10', 'number' => 1, 'paid' => true, 'value_quota' => 150]);

        $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1")
            ->assertStatus(200)
            ->assertJsonPath('totals.total', 150);

        $expense->update(['deleted' => true]);

        $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1")
            ->assertStatus(200)
            ->assertJsonPath('totals.total', 150)
            ->assertJsonFragment(['id' => $expense->id]);
    }

    public function test_open_cycle_keeps_reflecting_live_edits(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-05', 'total_value' => 100]);
        $expense->payers()->sync([$payer->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-05', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary")
            ->assertStatus(200)
            ->assertJsonPath('cycle.status', 'open')
            ->assertJsonPath('totals.total', 100);

        $expense->update(['total_value' => 200]);
        $expense->quotas()->first()->update(['value_quota' => 200]);

        $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary")
            ->assertStatus(200)
            ->assertJsonPath('totals.total', 200);
    }

    public function test_update_of_in_cash_expense_in_closed_cycle_is_rejected(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-07-10']);
        $expense->payers()->attach($payer->id);

        $response = $this->withToken($this->tokenFor($payer))
            ->putJson("/api/expenses/{$expense->id}", ['description' => 'Tentativa de alterar']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'description' => 'Despesa de teste']);
    }

    public function test_destroy_of_in_cash_expense_in_closed_cycle_is_rejected(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-07-10']);
        $expense->payers()->attach($payer->id);

        $response = $this->withToken($this->tokenFor($payer))
            ->deleteJson("/api/expenses/{$expense->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'deleted' => false]);
    }

    public function test_update_of_in_cash_expense_in_open_cycle_is_allowed(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-10']);
        $expense->payers()->attach($payer->id);

        $response = $this->withToken($this->tokenFor($payer))
            ->putJson("/api/expenses/{$expense->id}", ['description' => 'Alterado']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'description' => 'Alterado']);
    }

    public function test_fixed_expense_can_still_be_updated_and_destroyed_after_a_cycle_is_closed_and_frozen(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createExpense($group, $payer, [
            'date_payment' => '2026-06-05',
            'expense_type' => 'FIXED',
            'total_value' => 500,
        ]);
        $expense->payers()->attach($payer->id);
        $expense->quotas()->create(['date_expected' => '2026-06-05', 'number' => 1, 'paid' => false, 'value_quota' => 500]);

        // Materializa a foto de um ciclo já fechado que inclui esta despesa Fixa.
        $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1")
            ->assertStatus(200);

        $updateResponse = $this->withToken($this->tokenFor($payer))
            ->putJson("/api/expenses/{$expense->id}", ['total_value' => 600, 'description' => 'Aluguel']);

        $updateResponse->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'total_value' => 600, 'description' => 'Aluguel']);

        $destroyResponse = $this->withToken($this->tokenFor($payer))
            ->deleteJson("/api/expenses/{$expense->id}");

        $destroyResponse->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'deleted' => true]);
    }

    public function test_fixed_expense_value_edited_later_does_not_change_a_month_whose_quota_was_already_materialized(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createExpense($group, $payer, [
            'date_payment' => '2026-06-05',
            'expense_type' => 'FIXED',
            'total_value' => 350,
        ]);
        $expense->payers()->attach($payer->id);
        $expense->quotas()->create(['date_expected' => '2026-06-05', 'number' => 1, 'paid' => false, 'value_quota' => 350]);

        // Simula que a ocorrência de julho (competência já fechada hoje, mas cujo
        // resumo ninguém leu ainda — logo, sem GroupCycleSnapshot) já tinha sido
        // congelada com o valor vigente na época (350), via fechamento manual de
        // julho quando ainda era o mês vigente (materializeFixedOccurrenceQuota).
        $expense->quotas()->create(['date_expected' => '2026-07-05', 'number' => 1, 'paid' => false, 'value_quota' => 350]);

        // Só depois disso o valor da despesa Fixa é alterado.
        $expense->update(['total_value' => 999]);

        // Primeira leitura do resumo de julho (fechado) — é aqui que o snapshot
        // seria criado pela primeira vez. Antes da Quota materializada existir,
        // isso teria congelado o valor errado (999); com ela, usa o valor certo.
        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1");

        $response->assertStatus(200)
            ->assertJsonPath('cycle.status', 'closed')
            ->assertJsonPath('totals.total', 350)
            ->assertJsonFragment(['id' => $expense->id, 'date' => '2026-07-05', 'value' => 350]);
    }
}
