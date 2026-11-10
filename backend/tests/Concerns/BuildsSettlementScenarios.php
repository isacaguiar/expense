<?php

namespace Tests\Concerns;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\User;

/**
 * Monta o acerto "o devedor deve ao credor" de agosto/2026, para os testes do pagamento por um
 * responsável (feature 20261109-responsavel-do-devedor). Quem usa o trait fixa o "agora" com
 * `Carbon::setTestNow('2026-08-19')` antes. É a forma por model do helper privado de
 * `SettlementConfirmationControllerTest`, que não é editado.
 */
trait BuildsSettlementScenarios
{
    /**
     * Cria uma despesa de `$totalValue` paga por `$creditor` e dividida com `$debtor`: o acerto
     * líquido do devedor é `$totalValue / 2`. Fecha manualmente a competência de agosto/2026, porque
     * o acerto só é aceito com a competência fechada; passe `closeCycle: false` para os casos que
     * testam a recusa com o ciclo aberto (e `closeAugustCycle()` para fechá-lo depois).
     */
    protected function makeSettlement(Group $group, User $creditor, User $debtor, float $totalValue, bool $closeCycle = true): Expense
    {
        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => $totalValue,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);
        $expense->payers()->sync([$creditor->id, $debtor->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => $totalValue]);

        if ($closeCycle) {
            $this->closeAugustCycle($group);
        }

        return $expense;
    }

    /** Fecha manualmente a competência de agosto/2026 do grupo. */
    protected function closeAugustCycle(Group $group): void
    {
        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'totals' => ['total' => 0, 'paid' => 0, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => now(),
        ]);
    }
}
