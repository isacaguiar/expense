<?php

namespace Tests\Concerns;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use App\Support\BillingCycle;
use App\Support\InstallmentSchedule;
use Carbon\Carbon;

/**
 * Monta compras (À Vista, Parcelada, Fixa) e as marca como pagas "à mão", sem
 * passar pelo `POST /api/expenses` nem por `pay()`. É a "forma equivalente por
 * factory" da feature 20261027-pagamento-no-cadastro-despesa
 * (specify.md §3.7): o cadastro com pagamento tem que produzir as mesmas
 * saídas que a compra cadastrada sem pagamento e depois marcada paga.
 *
 * A regra de `born_paid` espelha `ExpenseController::store`: parcela de
 * Parcelada cujo ciclo já está fechado POR DATA, em relação a "agora", nasce
 * quitada. Quem usa o trait fixa o "agora" com `Carbon::setTestNow` antes de
 * montar a compra.
 */
trait BuildsPurchaseScenarios
{
    /**
     * @return array{group: Group, users: list<User>}
     */
    protected function makeGroup(int $members, ?int $closingDay = null): array
    {
        $group = Group::create(['name' => 'Grupo de compra', 'closing_day' => $closingDay]);
        $users = [];

        for ($i = 0; $i < $members; $i++) {
            $users[] = User::factory()->create();
        }

        $group->members()->attach(array_map(fn (User $user) => $user->id, $users));

        return ['group' => $group, 'users' => $users];
    }

    /**
     * @param  list<User>  $participants  quem divide a compra (o credor só entra se estiver na lista)
     * @param  string  $type  IN_CASH, IN_INSTALLMENTS ou FIXED
     * @param  string  $start  Y-m-d: data da despesa (À Vista, Fixa) ou da 1ª parcela
     * @param  int  $n  parcelas (só vale para IN_INSTALLMENTS)
     */
    protected function makePurchase(
        Group $group,
        User $creditor,
        array $participants,
        string $type,
        float $total,
        string $start,
        int $n = 1
    ): Expense {
        $installments = $type === 'IN_INSTALLMENTS' ? $n : 1;

        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => $start,
            'description' => 'Compra de teste',
            'expense_type' => $type,
            'installments' => $installments,
            'total_value' => $total,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);
        $expense->payers()->sync(array_map(fn (User $user) => $user->id, $participants));

        foreach (InstallmentSchedule::build($total, $installments, $start) as $quota) {
            $bornPaid = $type === 'IN_INSTALLMENTS'
                && BillingCycle::statusFor($group->closing_day, Carbon::parse($quota['date_expected']), Carbon::now()) === 'closed';

            $expense->quotas()->create([
                'date_expected' => $quota['date_expected'],
                'number' => $quota['number'],
                'paid' => $bornPaid,
                'paid_at' => $bornPaid ? now() : null,
                'paid_by' => $bornPaid ? $creditor->id : null,
                'born_paid' => $bornPaid,
                'value_quota' => $quota['value_quota'],
            ]);
        }

        return $expense;
    }

    /**
     * Marca como pagas as quotas que não nasceram `born_paid`, do mesmo modo que
     * o credor faria com o ✓ em cada uma: `paid`, `paid_at` e `paid_by` = credor,
     * com comprovante e descrição opcionais. Em Fixa só existe a quota inicial
     * (as demais ocorrências são projetadas e não têm linha).
     */
    protected function markPaid(Expense $expense, ?string $proofPath = null, ?string $note = null): void
    {
        $expense->quotas()->where('born_paid', false)->update([
            'paid' => true,
            'paid_at' => now(),
            'paid_by' => $expense->user_payer_id,
            'payment_proof_path' => $proofPath,
            'payment_note' => $note,
        ]);
    }

    /**
     * Ciclos (início e fim) que contêm cada mês a partir de `$firstDate`.
     *
     * @return list<array{start: Carbon, end: Carbon}>
     */
    protected function cyclesFrom(Group $group, string $firstDate, int $count): array
    {
        $cycles = [];

        for ($i = 0; $i < $count; $i++) {
            $date = Carbon::parse($firstDate)->addMonthsNoOverflow($i);
            $cycle = BillingCycle::cycleFor($group->closing_day, $date);
            $cycles[] = ['start' => $cycle['start'], 'end' => $cycle['end']];
        }

        return $cycles;
    }
}
