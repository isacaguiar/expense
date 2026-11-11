<?php

namespace Tests\Feature;

use App\Http\Controllers\ExpenseController;
use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Notification;
use App\Models\SettlementConfirmation;
use App\Models\User;
use App\Services\CycleSealingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionMethod;
use Tests\TestCase;

/**
 * `CycleSealingService::sealIfSettled` (feature 20261110-quem-paga-se-declara-e-credor-recusa, plan §2,
 * TASK-596): o selo do ciclo, extraído de `ExpenseController::sealCycleIfSettled` **sem mudar
 * comportamento**. O que se quer provar aqui é o comportamento que já existia: sela só se o ciclo está
 * totalmente quitado, ignora ciclo sem despesa real nem acerto, não mexe nos campos do fechamento
 * manual e só avisa na transição para selado.
 */
class CycleSealingServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $start;

    private Carbon $end;

    protected function setUp(): void
    {
        parent::setUp();

        // closing_day nulo: competência de agosto/2026.
        $this->start = Carbon::parse('2026-08-01');
        $this->end = Carbon::parse('2026-08-31');
    }

    private function sealing(): CycleSealingService
    {
        return app(CycleSealingService::class);
    }

    /**
     * Despesa de $creditor dividida com $debtor, com a parcela de agosto paga ou não. Gera o acerto
     * líquido $debtor → $creditor de metade; `$confirmed` grava o comprovante desse par.
     *
     * @return array{0: Group, 1: User, 2: User, 3: Expense}
     */
    private function scenario(bool $paid = true, bool $confirmed = true): array
    {
        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
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
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => $paid, 'value_quota' => 200]);

        if ($confirmed) {
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
        }

        return [$group, $creditor, $debtor, $expense];
    }

    private function sealedSnapshot(Group $group): ?GroupCycleSnapshot
    {
        return GroupCycleSnapshot::where('group_id', $group->id)
            ->where('cycle_start', $this->start->toDateString())
            ->whereNotNull('settled_at')
            ->first();
    }

    private function cycleSettledNotices(Group $group): int
    {
        return Notification::where('type', 'cycle_settled')->where('group_id', $group->id)->count();
    }

    public function test_it_seals_a_fully_settled_cycle(): void
    {
        [$group] = $this->scenario();

        $this->assertTrue($this->sealing()->sealIfSettled($group, $this->start, $this->end));

        $snapshot = $this->sealedSnapshot($group);
        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->isSealed());
        $this->assertSame($this->end->toDateString(), $snapshot->cycle_end->toDateString());
        $this->assertCount(1, $snapshot->settlements, 'a foto guarda os acertos do ciclo');
    }

    public function test_it_does_not_seal_with_a_pending_entry(): void
    {
        [$group] = $this->scenario(paid: false);

        $this->assertFalse($this->sealing()->sealIfSettled($group, $this->start, $this->end));

        $this->assertNull($this->sealedSnapshot($group));
        $this->assertSame(0, $this->cycleSettledNotices($group));
    }

    public function test_it_does_not_seal_when_a_settlement_pair_has_no_confirmation(): void
    {
        [$group] = $this->scenario(confirmed: false);

        $this->assertFalse($this->sealing()->sealIfSettled($group, $this->start, $this->end));

        $this->assertNull($this->sealedSnapshot($group));
    }

    public function test_it_does_not_seal_a_cycle_without_a_real_expense_or_settlement(): void
    {
        // (a) um ciclo sem nenhuma entrada, que `isFullySettled` considera quitado;
        $empty = Group::create(['name' => 'Vazio']);
        $empty->members()->attach(User::factory()->create()->id);
        $this->assertFalse($this->sealing()->sealIfSettled($empty, $this->start, $this->end));
        $this->assertNull($this->sealedSnapshot($empty));

        // (b) só uma parcela que nasceu quitada (retroativa), sem acerto.
        [$group, , , $expense] = $this->scenario(confirmed: false);
        $expense->quotas()->update(['born_paid' => true]);
        $this->assertFalse($this->sealing()->sealIfSettled($group, $this->start, $this->end));
        $this->assertNull($this->sealedSnapshot($group));
    }

    public function test_it_seals_a_cycle_with_a_real_expense_and_no_settlement(): void
    {
        $solo = User::factory()->create();
        $group = Group::create(['name' => 'Sozinho']);
        $group->members()->attach($solo->id);
        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Despesa própria',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 80,
            'group_id' => $group->id,
            'user_creator_id' => $solo->id,
            'user_payer_id' => $solo->id,
            'deleted' => false,
        ]);
        $expense->payers()->sync([$solo->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => true, 'value_quota' => 80]);

        $this->assertTrue($this->sealing()->sealIfSettled($group, $this->start, $this->end));
        $this->assertNotNull($this->sealedSnapshot($group));
    }

    public function test_it_notifies_only_on_the_transition_to_sealed(): void
    {
        [$group] = $this->scenario();

        $this->assertTrue($this->sealing()->sealIfSettled($group, $this->start, $this->end));
        $this->assertSame(2, $this->cycleSettledNotices($group), 'um aviso por membro do grupo');

        // De novo, num ciclo que já está selado: continua `true` e não avisa outra vez.
        $this->assertTrue($this->sealing()->sealIfSettled($group, $this->start, $this->end));
        $this->assertSame(2, $this->cycleSettledNotices($group));
    }

    public function test_it_preserves_the_manual_closure_fields(): void
    {
        [$group] = $this->scenario();
        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => $this->start->toDateString(),
            'cycle_end' => $this->end->toDateString(),
            'totals' => ['total' => 0, 'paid' => 0, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => '2026-08-31 10:00:00',
            'reopened_at' => '2026-09-01 08:00:00',
        ]);

        $this->assertTrue($this->sealing()->sealIfSettled($group, $this->start, $this->end));

        $snapshot = $this->sealedSnapshot($group);
        $this->assertSame('2026-08-31 10:00:00', $snapshot->closed_manually_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-01 08:00:00', $snapshot->reopened_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, GroupCycleSnapshot::where('group_id', $group->id)->count(), 'atualiza a linha, não cria outra');
    }

    public function test_the_controller_seals_through_the_service(): void
    {
        [$group] = $this->scenario();
        $this->mock(CycleSealingService::class, function ($mock) {
            $mock->shouldReceive('sealIfSettled')->once()->andReturn(true);
        });

        $method = new ReflectionMethod(ExpenseController::class, 'sealCycleIfSettled');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(new ExpenseController, $group, $group->id, $this->start, $this->end));
    }
}
