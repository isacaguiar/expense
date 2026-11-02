<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Group;
use App\Support\BillingCycle;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Despesas que impedem alguém de sair (ou ser removido) de um grupo.
 *
 * Sair do grupo só tira a linha de `ex_groups_members`: despesas, quotas e
 * participantes ficam. Isso é inofensivo para quem apenas **deve** uma parcela
 * (o credor continua vendo a dívida e confirmando o pagamento), mas quebra dois
 * casos, e são só eles que bloqueiam (D3-B de
 * docs/feature/20261030-saida-e-remocao-de-membro/specify.md):
 *
 * - `creditor_open_quotas`: o usuário é o **credor** de despesa com parcela não
 *   paga de outra pessoa. Só o credor confirma o pagamento (`pay()`), e
 *   confirmar exige ser membro; sem ele ninguém mais fecha essa parcela.
 * - `active_fixed`: o usuário participa de despesa **fixa** ainda ativa (ou é
 *   credor dela, com outros participantes). A fixa materializa uma ocorrência
 *   por ciclo, então o ex-membro continuaria sendo cobrado todo mês.
 */
class MemberExitBlockers
{
    public const LIMIT = 10;

    public const ACTIVE_FIXED = 'active_fixed';

    public const CREDITOR_OPEN_QUOTAS = 'creditor_open_quotas';

    /**
     * @return list<array{expense_id: int, description: string, reason: string}>
     */
    public function for(Group $group, int $userId): array
    {
        $activeFixed = $this->activeFixed($group, $userId)->limit(self::LIMIT)->get(['id', 'description']);

        // Uma fixa ativa já foi listada: a quota inicial dela, ainda sem pagar,
        // também cairia aqui e apareceria duas vezes.
        $openQuotas = $this->creditorOpenQuotas($group, $userId)
            ->whereNotIn('id', $activeFixed->modelKeys())
            ->limit(self::LIMIT)
            ->get(['id', 'description']);

        return $activeFixed->map(fn (Expense $expense) => $this->item($expense, self::ACTIVE_FIXED))
            ->concat($openQuotas->map(fn (Expense $expense) => $this->item($expense, self::CREDITOR_OPEN_QUOTAS)))
            ->take(self::LIMIT)
            ->values()
            ->all();
    }

    /**
     * @return Builder<Expense>
     */
    private function expensesOf(Group $group): Builder
    {
        return Expense::where('group_id', $group->id)->where('deleted', false)->orderBy('id');
    }

    /**
     * Mesma condição com que `CycleSummaryService::entries` inclui uma fixa num
     * ciclo: sem fim de recorrência, ou com fim depois do início do mês do
     * ciclo corrente (`stopRecurrence` grava o dia 1 do mês de corte).
     *
     * @return Builder<Expense>
     */
    private function activeFixed(Group $group, int $userId): Builder
    {
        $currentMonthStart = BillingCycle::cycleFor($group->closing_day, Carbon::now())['start']->copy()->startOfMonth();

        return $this->expensesOf($group)
            ->where('expense_type', 'FIXED')
            ->where(function (Builder $query) use ($currentMonthStart) {
                $query->whereNull('fixed_recurrence_ends_at')
                    ->orWhere('fixed_recurrence_ends_at', '>', $currentMonthStart->toDateString());
            })
            ->where(function (Builder $query) use ($userId) {
                $query
                    // credor, desde que alguém mais divida a despesa
                    ->where(fn (Builder $creditor) => $creditor
                        ->where('user_payer_id', $userId)
                        ->whereHas('payers', fn (Builder $payers) => $payers->whereKeyNot($userId)))
                    // ou participante que não é o credor
                    ->orWhere(fn (Builder $participant) => $participant
                        ->where('user_payer_id', '!=', $userId)
                        ->whereHas('payers', fn (Builder $payers) => $payers->whereKey($userId)));
            });
    }

    /**
     * @return Builder<Expense>
     */
    private function creditorOpenQuotas(Group $group, int $userId): Builder
    {
        return $this->expensesOf($group)
            ->where('user_payer_id', $userId)
            ->whereHas('quotas', fn (Builder $quotas) => $quotas->where('paid', false))
            ->whereHas('payers', fn (Builder $payers) => $payers->whereKeyNot($userId));
    }

    /**
     * @return array{expense_id: int, description: string, reason: string}
     */
    private function item(Expense $expense, string $reason): array
    {
        return ['expense_id' => $expense->id, 'description' => $expense->description, 'reason' => $reason];
    }
}
