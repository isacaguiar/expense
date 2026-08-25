<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Quota;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_pay_without_file_leaves_payment_proof_path_null(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-08-10']);
        $expense->payers()->attach($creditor->id);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        // Fluxo antigo de ExpenseManager.tsx chama /pay sem corpo nenhum — o
        // comprovante tem que continuar opcional pra não quebrar esse fluxo.
        $response = $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/expenses/{$expense->id}/pay");

        $response->assertStatus(200)->assertJsonPath('payment_proof_url', null);

        $quota = Quota::where('expense_id', $expense->id)->firstOrFail();
        $this->assertNull($quota->payment_proof_path);
    }

    public function test_pay_with_photo_stores_it_and_exposes_the_url(): void
    {
        Storage::fake('public');
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-08-10']);
        $expense->payers()->attach($creditor->id);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($creditor))
            ->post("/api/expenses/{$expense->id}/pay", [
                'comprovante' => UploadedFile::fake()->image('comprovante.jpg'),
            ]);

        $response->assertStatus(200)->assertJsonPath('paid', true);
        $this->assertNotNull($response->json('payment_proof_url'));

        $quota = Quota::where('expense_id', $expense->id)->firstOrFail();
        $this->assertNotNull($quota->payment_proof_path);
        Storage::disk('public')->assertExists($quota->payment_proof_path);
    }

    public function test_pay_rejects_non_image_proof_file(): void
    {
        Storage::fake('public');
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-08-10']);
        $expense->payers()->attach($creditor->id);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($creditor))
            ->post("/api/expenses/{$expense->id}/pay", [
                'comprovante' => UploadedFile::fake()->create('comprovante.pdf', 100, 'application/pdf'),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_quotas', ['expense_id' => $expense->id, 'paid' => false, 'payment_proof_path' => null]);
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

    public function test_creditor_can_unpay_in_cash_expense(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-08-10']);
        $expense->payers()->attach($creditor->id);
        $quota = $expense->quotas()->create([
            'date_expected' => '2026-08-10',
            'number' => 1,
            'paid' => true,
            'paid_at' => now(),
            'paid_by' => $creditor->id,
            'value_quota' => 100,
        ]);

        $response = $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/expenses/{$expense->id}/unpay");

        $response->assertStatus(200)->assertJsonPath('paid', false);

        $quota->refresh();
        $this->assertFalse((bool) $quota->paid);
        $this->assertNull($quota->paid_at);
        $this->assertNull($quota->paid_by);
    }

    public function test_unpay_deletes_stored_proof_photo_and_clears_the_path(): void
    {
        Storage::fake('public');
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-08-10']);
        $expense->payers()->attach($creditor->id);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $this->withToken($this->tokenFor($creditor))
            ->post("/api/expenses/{$expense->id}/pay", [
                'comprovante' => UploadedFile::fake()->image('comprovante.jpg'),
            ])->assertStatus(200);

        $path = Quota::where('expense_id', $expense->id)->firstOrFail()->payment_proof_path;
        Storage::disk('public')->assertExists($path);

        $response = $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/expenses/{$expense->id}/unpay");

        $response->assertStatus(200)->assertJsonPath('payment_proof_url', null);

        $quota = Quota::where('expense_id', $expense->id)->firstOrFail();
        $this->assertNull($quota->payment_proof_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_non_creditor_cannot_unpay(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $participant->id]);

        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-08-10']);
        $expense->payers()->sync([$creditor->id, $participant->id]);
        $expense->quotas()->create([
            'date_expected' => '2026-08-10', 'number' => 1, 'paid' => true,
            'paid_at' => now(), 'paid_by' => $creditor->id, 'value_quota' => 100,
        ]);

        $response = $this->withToken($this->tokenFor($participant))
            ->postJson("/api/expenses/{$expense->id}/unpay");

        $response->assertStatus(403);
        $this->assertDatabaseHas('ex_quotas', ['expense_id' => $expense->id, 'paid' => true]);
    }

    public function test_unpay_fails_when_expense_is_not_paid(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-08-10']);
        $expense->payers()->attach($creditor->id);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/expenses/{$expense->id}/unpay");

        $response->assertStatus(422);
    }

    public function test_unpay_does_not_materialize_a_fixed_quota_that_was_never_paid(): void
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

        $response = $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/expenses/{$expense->id}/unpay");

        $response->assertStatus(422);
        // Continua só com a Quota original — unpay() não congela o valor do
        // mês vigente à toa quando não havia nada pago pra desfazer.
        $this->assertSame(1, Quota::where('expense_id', $expense->id)->count());
    }

    public function test_cannot_unpay_in_a_manually_closed_cycle(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-08-10']);
        $expense->payers()->attach($creditor->id);
        $expense->quotas()->create([
            'date_expected' => '2026-08-10', 'number' => 1, 'paid' => true,
            'paid_at' => now(), 'paid_by' => $creditor->id, 'value_quota' => 100,
        ]);

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
            ->postJson("/api/expenses/{$expense->id}/unpay");

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_quotas', ['expense_id' => $expense->id, 'paid' => true]);
    }

    public function test_full_pay_unpay_update_flow_blocks_and_unblocks_value_edits(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-08-10', 'total_value' => 100]);
        $expense->payers()->attach($creditor->id);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $token = $this->tokenFor($creditor);

        // Pendente: editar valor funciona normalmente.
        $this->withToken($token)
            ->putJson("/api/expenses/{$expense->id}", ['total_value' => 120])
            ->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'total_value' => 120]);

        // Paga (TASK-163): editar valor passa a ser bloqueado (TASK-165),
        // mas outros campos continuam editáveis.
        $this->withToken($token)->postJson("/api/expenses/{$expense->id}/pay")->assertStatus(200);

        $this->withToken($token)
            ->putJson("/api/expenses/{$expense->id}", ['total_value' => 999])
            ->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'total_value' => 120]);

        $this->withToken($token)
            ->putJson("/api/expenses/{$expense->id}", ['description' => 'Ajuste de descrição'])
            ->assertStatus(200);

        // Despagar (TASK-164): editar valor volta a funcionar.
        $this->withToken($token)->postJson("/api/expenses/{$expense->id}/unpay")->assertStatus(200);

        $this->withToken($token)
            ->putJson("/api/expenses/{$expense->id}", ['total_value' => 150])
            ->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'total_value' => 150]);
    }

    public function test_full_pay_unpay_destroy_flow_blocks_and_unblocks_deletion(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-08-10', 'total_value' => 100]);
        $expense->payers()->attach($creditor->id);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $token = $this->tokenFor($creditor);

        // Paga (TASK-163): excluir passa a ser bloqueado (TASK-167).
        $this->withToken($token)->postJson("/api/expenses/{$expense->id}/pay")->assertStatus(200);

        $this->withToken($token)
            ->deleteJson("/api/expenses/{$expense->id}")
            ->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'deleted' => false]);

        // Despagar (TASK-164): excluir volta a funcionar.
        $this->withToken($token)->postJson("/api/expenses/{$expense->id}/unpay")->assertStatus(200);

        $this->withToken($token)
            ->deleteJson("/api/expenses/{$expense->id}")
            ->assertStatus(200);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'deleted' => true]);
    }

    public function test_destroy_rejects_paid_expense_even_when_cycle_is_also_closed(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        // Despesa de julho (competência já fechada automaticamente) que
        // também está paga — os dois motivos de bloqueio coexistem, e
        // qualquer um dos dois já basta pra recusar a exclusão.
        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-07-10', 'total_value' => 100]);
        $expense->payers()->attach($creditor->id);
        $expense->quotas()->create(['date_expected' => '2026-07-10', 'number' => 1, 'paid' => true, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($creditor))
            ->deleteJson("/api/expenses/{$expense->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id, 'deleted' => false]);
    }

    public function test_unpay_requires_group_membership(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        $expense = $this->createExpense($group, $creditor, ['date_payment' => '2026-08-10']);
        $expense->payers()->attach($creditor->id);
        $expense->quotas()->create([
            'date_expected' => '2026-08-10', 'number' => 1, 'paid' => true,
            'paid_at' => now(), 'paid_by' => $creditor->id, 'value_quota' => 100,
        ]);

        $response = $this->withToken($this->tokenFor($outsider))
            ->postJson("/api/expenses/{$expense->id}/unpay");

        $response->assertStatus(404);
    }
}
