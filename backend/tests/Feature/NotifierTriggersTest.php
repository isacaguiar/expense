<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Notification;
use App\Models\User;
use App\Support\Notifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Gatilhos do serviço App\Support\Notifier gravando linhas em ex_notifications
 * nos pontos de evento do domínio. Ver
 * docs/feature/20260903-notificacoes-in-app/plan.md §3.
 *
 * Este arquivo cresce a cada task de gatilho (TASK-262..265).
 */
class NotifierTriggersTest extends TestCase
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

    // --- expense_paid (ExpenseController@pay) -------------------------------

    private function payScenario(): array
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $debtorA = User::factory()->create();
        $debtorB = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $debtorA->id, $debtorB->id]);

        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Conta de luz',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 90,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);
        $expense->payers()->sync([$creditor->id, $debtorA->id, $debtorB->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 90]);

        return [$group, $creditor, $debtorA, $debtorB, $expense];
    }

    public function test_expense_paid_notifies_debtors_not_the_creditor(): void
    {
        [$group, $creditor, $debtorA, $debtorB, $expense] = $this->payScenario();

        $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/expenses/{$expense->id}/pay")
            ->assertStatus(200);

        $rows = Notification::where('type', 'expense_paid')->get();

        $this->assertEqualsCanonicalizing(
            [$debtorA->id, $debtorB->id],
            $rows->pluck('user_id')->all()
        );
        $this->assertFalse($rows->contains('user_id', $creditor->id));

        $data = $rows->first()->data;
        $this->assertSame($creditor->name, $data['actorName']);
        $this->assertSame('Conta de luz', $data['expenseDescription']);
        $this->assertSame('90.00', $data['amount']);
        $this->assertSame($group->id, $data['groupId']);
        $this->assertSame('agosto/2026', $data['cycleLabel']);
    }

    public function test_expense_paid_is_not_duplicated_when_pay_is_called_again(): void
    {
        [, $creditor, , , $expense] = $this->payScenario();
        $token = $this->tokenFor($creditor);

        $this->withToken($token)->postJson("/api/expenses/{$expense->id}/pay")->assertStatus(200);
        $this->withToken($token)->postJson("/api/expenses/{$expense->id}/pay")->assertStatus(200);

        $this->assertSame(2, Notification::where('type', 'expense_paid')->count()); // 2 devedores, 1 cada
    }

    // --- settlement_confirmed (ExpenseController@confirmSettlement) --------

    private function settlementScenario(): array
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 200,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);
        $expense->payers()->sync([$creditor->id, $debtor->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 200]);

        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'totals' => ['total' => 0, 'paid' => 0, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => now(),
        ]);

        return [$group, $creditor, $debtor];
    }

    public function test_settlement_confirmed_notifies_the_creditor_only(): void
    {
        [$group, $creditor, $debtor] = $this->settlementScenario();

        $this->withToken($this->tokenFor($debtor))
            ->post("/api/groups/{$group->id}/settlements/confirm", [
                'to_user_id' => $creditor->id,
                'comprovante' => UploadedFile::fake()->image('c.jpg'),
            ])
            ->assertStatus(200);

        $rows = Notification::where('type', 'settlement_confirmed')->get();

        $this->assertCount(1, $rows);
        $this->assertSame($creditor->id, $rows->first()->user_id);

        $data = $rows->first()->data;
        $this->assertSame($debtor->name, $data['actorName']);
        $this->assertSame('100.00', $data['amount']); // 200 / 2
        $this->assertSame($group->id, $data['groupId']);
        $this->assertSame('agosto/2026', $data['cycleLabel']);
    }

    public function test_settlement_confirmed_is_not_duplicated_on_proof_resend(): void
    {
        [$group, $creditor, $debtor] = $this->settlementScenario();
        $token = $this->tokenFor($debtor);

        $payload = fn () => [
            'to_user_id' => $creditor->id,
            'comprovante' => UploadedFile::fake()->image('c.jpg'),
        ];

        $this->withToken($token)->post("/api/groups/{$group->id}/settlements/confirm", $payload())->assertStatus(200);
        $this->withToken($token)->post("/api/groups/{$group->id}/settlements/confirm", $payload())->assertStatus(200);

        $this->assertSame(1, Notification::where('type', 'settlement_confirmed')->count());
    }

    public function test_notifier_guard_swallows_a_write_failure(): void
    {
        Log::spy();

        $group = Group::create(['name' => 'Grupo de teste']);
        $creditor = User::factory()->create();

        // actorName com byte inválido em UTF-8 → json_encode do cast 'data' lança.
        Notifier::settlementConfirmed($group, $creditor->id, "Jo\xE3o", '10.00', '2026-08-01');

        $this->assertSame(0, Notification::count());
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    // --- cycle_settled (ExpenseController@sealCycleIfSettled) -------------

    /**
     * Ciclo de agosto/2026 já fechado manualmente (snapshot criado direto, sem
     * passar pela rota `close()` — não dispara `cycle_closed`), com uma única
     * despesa do credor como único pagador (sem settlements). Pagar a última
     * quota sela o ciclo.
     */
    private function sealableScenario(): array
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $memberB = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $memberB->id]);

        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Conta de luz',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 60,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);
        $expense->payers()->sync([$creditor->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 60]);

        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'totals' => ['total' => 60, 'paid' => 0, 'pending' => 60],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => now(),
        ]);

        return [$group, $creditor, $memberB, $expense];
    }

    public function test_cycle_settled_notifies_all_members_once(): void
    {
        [$group, $creditor, $memberB, $expense] = $this->sealableScenario();
        $token = $this->tokenFor($creditor);

        $this->withToken($token)->postJson("/api/expenses/{$expense->id}/pay")->assertStatus(200);

        $rows = Notification::where('type', 'cycle_settled')->get();
        $this->assertEqualsCanonicalizing([$creditor->id, $memberB->id], $rows->pluck('user_id')->all());
        $this->assertSame('agosto/2026', $rows->first()->data['cycleLabel']);
        $this->assertSame($group->id, $rows->first()->data['groupId']);

        // Releitura preguiçosa do summary chama sealCycleIfSettled de novo — não repete.
        $this->withToken($token)->getJson("/api/groups/{$group->id}/expenses/summary")->assertStatus(200);
        $this->assertSame(2, Notification::where('type', 'cycle_settled')->count());
    }

    // --- cycle_closed (ExpenseController@close) --------------------------

    private function manualCloseScenario(): array
    {
        Carbon::setTestNow('2026-08-19');

        $closer = User::factory()->create();
        $memberB = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$closer->id, $memberB->id]);

        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $closer->id,
            'user_payer_id' => $closer->id,
            'deleted' => false,
        ]);
        $expense->payers()->sync([$closer->id, $memberB->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        return [$group, $closer, $memberB];
    }

    public function test_cycle_closed_notifies_members_except_the_actor(): void
    {
        [$group, $closer, $memberB] = $this->manualCloseScenario();

        $this->withToken($this->tokenFor($closer))
            ->postJson("/api/groups/{$group->id}/expenses/close")
            ->assertStatus(200)
            ->assertJsonPath('cycle.status', 'closed_manually');

        $rows = Notification::where('type', 'cycle_closed')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($memberB->id, $rows->first()->user_id);
        $this->assertSame($closer->name, $rows->first()->data['actorName']);
        $this->assertSame('agosto/2026', $rows->first()->data['cycleLabel']);
    }

    public function test_cycle_closed_is_not_duplicated_on_reclose(): void
    {
        [$group, $closer] = $this->manualCloseScenario();
        $token = $this->tokenFor($closer);

        $this->withToken($token)->postJson("/api/groups/{$group->id}/expenses/close")->assertStatus(200);
        $this->withToken($token)->postJson("/api/groups/{$group->id}/expenses/close")->assertStatus(200);

        $this->assertSame(1, Notification::where('type', 'cycle_closed')->count());
    }

    public function test_closing_a_fully_settled_cycle_emits_only_cycle_settled(): void
    {
        Carbon::setTestNow('2026-08-19');

        $closer = User::factory()->create();
        $memberB = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$closer->id, $memberB->id]);

        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 40,
            'group_id' => $group->id,
            'user_creator_id' => $closer->id,
            'user_payer_id' => $closer->id,
            'deleted' => false,
        ]);
        $expense->payers()->sync([$closer->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => true, 'value_quota' => 40]);

        $this->withToken($this->tokenFor($closer))
            ->postJson("/api/groups/{$group->id}/expenses/close")
            ->assertStatus(200)
            ->assertJsonPath('cycle.status', 'closed');

        $this->assertSame(2, Notification::where('type', 'cycle_settled')->count());
        $this->assertSame(0, Notification::where('type', 'cycle_closed')->count());
    }

    // --- group_member_added (GroupMemberController@store) ----------------

    public function test_group_member_added_notifies_the_new_member_only(): void
    {
        $adder = User::factory()->create();
        $group = Group::create(['name' => 'Casa']);
        $group->members()->attach($adder->id);

        $newMember = User::factory()->create();

        $this->withToken($this->tokenFor($adder))
            ->postJson("/api/groups/{$group->id}/members", ['email' => $newMember->email])
            ->assertStatus(201);

        $rows = Notification::where('type', 'group_member_added')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($newMember->id, $rows->first()->user_id);
        $this->assertSame($adder->name, $rows->first()->data['actorName']);
        $this->assertSame('Casa', $rows->first()->data['groupName']);
        $this->assertSame($group->id, $rows->first()->data['groupId']);
    }

    public function test_group_member_added_does_not_notify_when_already_a_member(): void
    {
        $adder = User::factory()->create();
        $existing = User::factory()->create();
        $group = Group::create(['name' => 'Casa']);
        $group->members()->attach([$adder->id, $existing->id]);

        $this->withToken($this->tokenFor($adder))
            ->postJson("/api/groups/{$group->id}/members", ['email' => $existing->email])
            ->assertStatus(409);

        $this->assertSame(0, Notification::where('type', 'group_member_added')->count());
    }

    // --- expense_created (ExpenseController@store) -----------------------

    private function storePayload(Group $group, User $creator, array $payerIds): array
    {
        return [
            'date_payment' => '2026-08-15',
            'description' => 'Conta de internet',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 120,
            'group_id' => $group->id,
            'user_creator_id' => $creator->id,
            'user_payer_id' => $creator->id,
            'payers' => $payerIds,
            'quotas' => [['date_expected' => '2026-08-15', 'number' => 1, 'value_quota' => 120]],
        ];
    }

    public function test_expense_created_notifies_payers_except_the_creator(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $creator = User::factory()->create();
        $payerA = User::factory()->create();
        $payerB = User::factory()->create();
        $group = Group::create(['name' => 'República']);
        $group->members()->attach([$creator->id, $payerA->id, $payerB->id]);

        $this->withToken($this->tokenFor($creator))
            ->postJson('/api/expenses', $this->storePayload($group, $creator, [$creator->id, $payerA->id, $payerB->id]))
            ->assertStatus(201);

        $rows = Notification::where('type', 'expense_created')->get();
        $this->assertEqualsCanonicalizing([$payerA->id, $payerB->id], $rows->pluck('user_id')->all());

        $data = $rows->first()->data;
        $this->assertSame($creator->name, $data['actorName']);
        $this->assertSame('Conta de internet', $data['expenseDescription']);
        $this->assertSame('120.00', $data['amount']);
        $this->assertSame('República', $data['groupName']);
        $this->assertSame('agosto/2026', $data['cycleLabel']);
    }

    public function test_expense_created_notifies_nobody_when_creator_is_sole_payer(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Sozinho']);
        $group->members()->attach($creator->id);

        $this->withToken($this->tokenFor($creator))
            ->postJson('/api/expenses', $this->storePayload($group, $creator, [$creator->id]))
            ->assertStatus(201);

        $this->assertSame(0, Notification::where('type', 'expense_created')->count());
    }
}
