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
}
