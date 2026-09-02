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

class ExpenseControllerCloseTest extends TestCase
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

    public function test_close_creates_manual_snapshot_for_current_cycle(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-10', 'total_value' => 100]);
        $expense->payers()->sync([$payer->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($payer))
            ->postJson("/api/groups/{$group->id}/expenses/close");

        $response->assertStatus(200)
            ->assertJsonPath('cycle.status', 'closed_manually')
            ->assertJsonPath('totals.total', 100);

        $this->assertDatabaseHas('ex_group_cycle_snapshots', [
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
        ]);

        $snapshot = GroupCycleSnapshot::where('group_id', $group->id)->where('cycle_start', '2026-08-01')->firstOrFail();
        $this->assertNotNull($snapshot->closed_manually_at);
        $this->assertNull($snapshot->reopened_at);
        $this->assertTrue($snapshot->isManuallyClosedAndActive());
    }

    public function test_cannot_close_deleted_group(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'deleted' => true]);
        $group->members()->attach($payer->id);

        $response = $this->withToken($this->tokenFor($payer))
            ->postJson("/api/groups/{$group->id}/expenses/close");

        $response->assertStatus(404);
        $this->assertDatabaseMissing('ex_group_cycle_snapshots', ['group_id' => $group->id]);
    }

    public function test_close_materializes_fixed_occurrence_quota(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createExpense($group, $payer, [
            'date_payment' => '2026-06-05',
            'expense_type' => 'FIXED',
            'total_value' => 200,
        ]);
        $expense->payers()->sync([$payer->id]);
        $expense->quotas()->create(['date_expected' => '2026-06-05', 'number' => 1, 'paid' => false, 'value_quota' => 200]);

        $this->assertSame(1, Quota::where('expense_id', $expense->id)->count());

        $this->withToken($this->tokenFor($payer))
            ->postJson("/api/groups/{$group->id}/expenses/close")
            ->assertStatus(200);

        $this->assertDatabaseHas('ex_quotas', [
            'expense_id' => $expense->id,
            'date_expected' => '2026-08-05',
            'value_quota' => 200,
        ]);
        $this->assertSame(2, Quota::where('expense_id', $expense->id)->count());
    }

    public function test_closing_again_recomputes_and_does_not_duplicate_snapshot(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-10', 'total_value' => 100]);
        $expense->payers()->sync([$payer->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $this->withToken($this->tokenFor($payer))
            ->postJson("/api/groups/{$group->id}/expenses/close")
            ->assertStatus(200)
            ->assertJsonPath('totals.total', 100);

        // Segunda despesa lançada depois do primeiro fechamento, ainda dentro
        // do mesmo mês (o fechamento manual não trava lançamentos novos por
        // si só — só quem já foi fechado antes de existir).
        $second = $this->createExpense($group, $payer, ['date_payment' => '2026-08-15', 'total_value' => 50]);
        $second->payers()->sync([$payer->id]);
        $second->quotas()->create(['date_expected' => '2026-08-15', 'number' => 1, 'paid' => false, 'value_quota' => 50]);

        $this->withToken($this->tokenFor($payer))
            ->postJson("/api/groups/{$group->id}/expenses/close")
            ->assertStatus(200)
            ->assertJsonPath('totals.total', 150);

        $this->assertSame(
            1,
            GroupCycleSnapshot::where('group_id', $group->id)->where('cycle_start', '2026-08-01')->count()
        );
    }

    public function test_summary_of_a_manually_closed_cycle_reflects_live_state(): void
    {
        // TASK-247 (feature 20260902): um ciclo fechado (manual) ainda não
        // quitado passa a ser recalculado AO VIVO no summary — é o que faz
        // "pagar depois de fechar" aparecer na tela. (A edição de valor via
        // API continua bloqueada; aqui a mudança é simulada direto no modelo.)
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-10', 'total_value' => 100]);
        $expense->payers()->sync([$payer->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $this->withToken($this->tokenFor($payer))
            ->postJson("/api/groups/{$group->id}/expenses/close")
            ->assertStatus(200);

        $expense->update(['total_value' => 999]);
        $expense->quotas()->first()->update(['value_quota' => 999]);

        $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary")
            ->assertStatus(200)
            ->assertJsonPath('cycle.status', 'closed_manually')
            ->assertJsonPath('cycle.settled', false)
            ->assertJsonPath('totals.total', 999);
    }

    public function test_summary_reflects_live_edits_again_after_manual_reopening(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-10', 'total_value' => 100]);
        $expense->payers()->sync([$payer->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $this->withToken($this->tokenFor($payer))
            ->postJson("/api/groups/{$group->id}/expenses/close")
            ->assertStatus(200);

        GroupCycleSnapshot::where('group_id', $group->id)
            ->where('cycle_start', '2026-08-01')
            ->update(['reopened_at' => now()]);

        $expense->update(['total_value' => 999]);
        $expense->quotas()->first()->update(['value_quota' => 999]);

        $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary")
            ->assertStatus(200)
            ->assertJsonPath('cycle.status', 'open')
            ->assertJsonPath('totals.total', 999);
    }

    public function test_full_close_flow_blocks_update_destroy_and_new_expenses_in_the_same_competence(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-10', 'total_value' => 100]);
        $expense->payers()->sync([$payer->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $token = $this->tokenFor($payer);

        // Fecha via a rota real (não simula o estado do snapshot direto no
        // banco, ao contrário dos testes das TASK-156/159) — cobre a
        // integração de ponta a ponta entre close() e as checagens de
        // competência fechada em update/destroy/store.
        $this->withToken($token)
            ->postJson("/api/groups/{$group->id}/expenses/close")
            ->assertStatus(200)
            ->assertJsonPath('cycle.status', 'closed_manually');

        $this->withToken($token)
            ->putJson("/api/expenses/{$expense->id}", ['description' => 'Tentativa de alterar'])
            ->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'description' => 'Despesa de teste']);

        $this->withToken($token)
            ->deleteJson("/api/expenses/{$expense->id}")
            ->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'deleted' => false]);

        $newExpensePayload = [
            'date_payment' => '2026-08-20',
            'description' => 'Nova despesa na competência fechada',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 50,
            'group_id' => $group->id,
            'user_creator_id' => $payer->id,
            'user_payer_id' => $payer->id,
            'payers' => [$payer->id],
            'quotas' => [['date_expected' => '2026-08-20', 'number' => 1, 'value_quota' => 50]],
        ];

        $this->withToken($token)
            ->postJson('/api/expenses', $newExpensePayload)
            ->assertStatus(422);
        $this->assertDatabaseMissing('ex_expenses', ['description' => 'Nova despesa na competência fechada']);

        // Re-fechar (upsert) continua funcionando mesmo com tudo bloqueado —
        // não cria um segundo registro para a mesma competência.
        $this->withToken($token)
            ->postJson("/api/groups/{$group->id}/expenses/close")
            ->assertStatus(200);

        $this->assertSame(
            1,
            GroupCycleSnapshot::where('group_id', $group->id)->where('cycle_start', '2026-08-01')->count()
        );
    }

    public function test_close_persists_settlements_in_the_snapshot(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $participant->id]);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-10', 'total_value' => 200]);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 200]);

        $response = $this->withToken($this->tokenFor($payer))
            ->postJson("/api/groups/{$group->id}/expenses/close");

        $response->assertStatus(200)->assertJsonFragment([
            'from_user_id' => $participant->id,
            'to_user_id' => $payer->id,
            'amount' => 100,
        ]);

        $snapshot = GroupCycleSnapshot::where('group_id', $group->id)->where('cycle_start', '2026-08-01')->firstOrFail();
        $this->assertNotNull($snapshot->settlements);
        $this->assertCount(1, $snapshot->settlements);
    }

    public function test_close_requires_group_membership(): void
    {
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);

        $response = $this->withToken($this->tokenFor($outsider))
            ->postJson("/api/groups/{$group->id}/expenses/close");

        $response->assertStatus(404);
        $this->assertDatabaseMissing('ex_group_cycle_snapshots', ['group_id' => $group->id]);
    }
}
