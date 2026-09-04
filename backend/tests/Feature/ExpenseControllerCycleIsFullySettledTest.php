<?php

namespace Tests\Feature;

use App\Http\Controllers\ExpenseController;
use App\Models\Expense;
use App\Models\Group;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

/**
 * cycleIsFullySettled(): "totalmente quitado" = toda entrada da competência
 * paga E todo par de settlements com SettlementConfirmation. Base da selagem
 * (settled_at) e do endpoint focus-cycle — ver
 * docs/feature/concluidas/202609/20260902-pagamento-ciclo-fechado/plan.md §0.3.
 */
class ExpenseControllerCycleIsFullySettledTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $start;

    private Carbon $end;

    protected function setUp(): void
    {
        parent::setUp();

        // closing_day nulo → mês calendário: competência de agosto/2026.
        $this->start = Carbon::parse('2026-08-01');
        $this->end = Carbon::parse('2026-08-31');
    }

    private function isFullySettled(Group $group): bool
    {
        $method = new ReflectionMethod(ExpenseController::class, 'cycleIsFullySettled');
        $method->setAccessible(true);

        return $method->invoke(new ExpenseController, $group, $group->id, $this->start, $this->end);
    }

    /**
     * Despesa À Vista de $creditor, dividida com $debtor, com a Quota de agosto
     * marcada como paga. Gera um settlement líquido $debtor → $creditor de metade.
     */
    private function paidExpenseBetween(Group $group, User $creditor, User $debtor, float $total): Expense
    {
        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => $total,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);
        $expense->payers()->sync([$creditor->id, $debtor->id]);
        $expense->quotas()->create([
            'date_expected' => '2026-08-10',
            'number' => 1,
            'paid' => true,
            'value_quota' => $total,
        ]);

        return $expense;
    }

    public function test_false_when_there_is_a_pending_expense(): void
    {
        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        $expense = $this->paidExpenseBetween($group, $creditor, $debtor, 200);
        $expense->quotas()->update(['paid' => false]);

        $this->assertFalse($this->isFullySettled($group));
    }

    public function test_false_when_a_settlement_pair_has_no_confirmation(): void
    {
        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        // Quota paga (pending == 0), mas o acerto devedor → credor não foi confirmado.
        $this->paidExpenseBetween($group, $creditor, $debtor, 200);

        $this->assertFalse($this->isFullySettled($group));
    }

    public function test_true_when_all_paid_and_all_settlements_confirmed(): void
    {
        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        $this->paidExpenseBetween($group, $creditor, $debtor, 200);

        SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => $this->start->toDateString(),
            'cycle_end' => $this->end->toDateString(),
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'amount' => 100,
            'proof_path' => 'comprovantes/'.$group->id.'/x.jpg',
            'confirmed_at' => now(),
        ]);

        $this->assertTrue($this->isFullySettled($group));
    }

    public function test_true_for_a_cycle_with_no_entries(): void
    {
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach(User::factory()->create()->id);

        $this->assertTrue($this->isFullySettled($group));
    }
}
