<?php

namespace App\Services;

use App\Exceptions\PurchaseUndoRefused;
use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Quota;
use App\Support\BillingCycle;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Regras do pagamento registrado junto do cadastro da despesa
 * (docs/feature/concluidas/202610/20261027-pagamento-no-cadastro-despesa, plan.md §4 e §5).
 *
 * Esta classe guarda a regra de negócio; o `ExpenseController` orquestra
 * (Constitution §1 item 3). Nada aqui grava arquivo nem notifica.
 */
class ExpensePaymentService
{
    /**
     * Atributos de uma quota paga no cadastro (specify R4): o pagamento é do
     * credor, `paid_at` é o momento do cadastro (o mesmo em todas as quotas da
     * compra, quem chama reutiliza o array) e `born_paid` fica falso: `born_paid`
     * nunca representa um pagamento comum.
     *
     * @return array{paid: bool, paid_at: Carbon, paid_by: int, payment_proof_path: ?string, payment_note: ?string, born_paid: bool}
     */
    public function paidAttributes(int $creditorId, ?string $proofPath, ?string $note): array
    {
        return [
            'paid' => true,
            'paid_at' => Carbon::now(),
            'paid_by' => $creditorId,
            'payment_proof_path' => $proofPath,
            // `filled()`, não `empty()`: `empty("0")` descartaria a descrição "0".
            'payment_note' => filled($note) ? $note : null,
            'born_paid' => false,
        ];
    }

    /**
     * A ocorrência inicial da despesa, a que não pode cair em competência futura
     * (specify R6): `date_payment` em À Vista e Fixa; a menor `date_expected` na
     * Parcelada, sem confiar na ordem nem no `number` das quotas que o cliente
     * enviou (item 079 do backlog).
     *
     * @param  array<int, array{date_expected: string, number?: int|string}>  $quotas
     */
    public function earliestOccurrence(string $type, string $datePayment, array $quotas): Carbon
    {
        if ($type !== 'IN_INSTALLMENTS' || $quotas === []) {
            return Carbon::parse($datePayment);
        }

        return collect($quotas)
            ->map(fn (array $quota) => Carbon::parse($quota['date_expected']))
            ->sort()
            ->first();
    }

    /**
     * Se `$date` cai numa competência que ainda não começou, em relação a "agora".
     * É a regra de `pay()`, que só alcança a competência vigente ou passada
     * (`cycles_ago >= 0`): não existe pagamento antecipado (specify R6, D5).
     */
    public function isFuture(Group $group, Carbon $date): bool
    {
        return BillingCycle::statusFor($group->closing_day, $date, Carbon::now()) === 'future';
    }

    /**
     * Desfaz de uma vez o pagamento de uma compra parcelada (specify R19, D11): zera `paid`,
     * `paid_at`, `paid_by`, comprovante e descrição de todas as quotas pagas que não nasceram
     * `born_paid`. Atômico, com lock de linha nas quotas: se uma ação concorrente (✓, `unpay`,
     * edição de comprovante) estiver no meio, esta espera e relê o estado.
     *
     * Devolve quantas quotas limpou e os caminhos de arquivo que ficaram soltos; **quem chama
     * remove os arquivos depois do commit** (`ProofStorage::deleteIfUnreferenced`), nunca aqui
     * dentro da transação.
     *
     * @return array{cleared: int, paths: list<string>}
     *
     * @throws PurchaseUndoRefused tipo diferente de Parcelada, nada a desfazer, ou ciclo encerrado
     */
    public function undoPurchase(Expense $expense): array
    {
        if ($expense->expense_type !== 'IN_INSTALLMENTS') {
            throw new PurchaseUndoRefused('O desfazer da compra vale só para despesa parcelada. Use "Desfazer pagamento" na ocorrência.');
        }

        return DB::transaction(function () use ($expense) {
            $eligible = $expense->quotas()->lockForUpdate()->get()
                ->filter(fn (Quota $quota) => $quota->paid && ! $quota->born_paid)
                ->values();

            if ($eligible->isEmpty()) {
                throw new PurchaseUndoRefused('Esta despesa não tem pagamento a desfazer.');
            }

            if (! $this->windowOpen($expense->group, $eligible)) {
                throw new PurchaseUndoRefused('O ciclo desta compra já foi encerrado; o pagamento não pode mais ser desfeito.');
            }

            $paths = $eligible->pluck('payment_proof_path')->filter()->unique()->values()->all();

            // Uma a uma, e não num `update` em massa: dispara os eventos do modelo e mantém o
            // comportamento igual ao de `unpay()` por parcela.
            foreach ($eligible as $quota) {
                $quota->update([
                    'paid' => false,
                    'paid_at' => null,
                    'paid_by' => null,
                    'payment_proof_path' => null,
                    'payment_note' => null,
                ]);
            }

            return ['cleared' => $eligible->count(), 'paths' => $paths];
        });
    }

    /**
     * A janela de correção está aberta enquanto NENHUMA das quotas pagas está em ciclo
     * encerrado: fechado por data (`closed`), com fechamento manual ainda ativo ou selado
     * (specify D11). Ciclo em carência e ciclo futuro não encerraram.
     *
     * @param  Collection<int, Quota>  $paidQuotas  as quotas pagas e não `born_paid` da compra
     * @param  Collection<string, GroupCycleSnapshot>|null  $snapshots  snapshots já carregados por
     *                                                                  `cycle_start` (versão em lote); null carrega aqui
     */
    public function windowOpen(Group $group, Collection $paidQuotas, ?Collection $snapshots = null): bool
    {
        $snapshots ??= $this->snapshotsFor($group, $paidQuotas);

        foreach ($paidQuotas as $quota) {
            if (BillingCycle::statusFor($group->closing_day, $quota->date_expected, Carbon::now()) === 'closed') {
                return false;
            }

            $snapshot = $snapshots->get($this->cycleStartOf($group, $quota));

            if ($snapshot && ($snapshot->isManuallyClosedAndActive() || $snapshot->isSealed())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Dentre `$expenseIds`, as despesas parceladas do grupo cujo desfazer da compra ainda é
     * possível (tem quota paga e não `born_paid`, e a janela está aberta). Versão em lote para o
     * `summary()`: uma consulta de quotas e uma de snapshots, qualquer que seja o número de
     * compras. Não confere quem é o credor (isso é de quem chama).
     *
     * @param  array<int, int>  $expenseIds
     * @return array<int, int>
     */
    public function undoableExpenseIds(Group $group, array $expenseIds): array
    {
        if ($expenseIds === []) {
            return [];
        }

        $byExpense = Quota::whereIn('expense_id', $expenseIds)
            ->where('paid', true)
            ->where('born_paid', false)
            ->whereHas('expense', fn ($query) => $query
                ->where('group_id', $group->id)
                ->where('deleted', false)
                ->where('expense_type', 'IN_INSTALLMENTS'))
            ->get()
            ->groupBy('expense_id');

        $snapshots = $this->snapshotsFor($group, $byExpense->flatten());

        return $byExpense
            ->filter(fn (Collection $quotas) => $this->windowOpen($group, $quotas, $snapshots))
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Os snapshots do grupo dos ciclos que contêm as quotas, indexados por `cycle_start`.
     *
     * @param  Collection<int, Quota>  $quotas
     * @return Collection<string, GroupCycleSnapshot>
     */
    private function snapshotsFor(Group $group, Collection $quotas): Collection
    {
        return GroupCycleSnapshot::where('group_id', $group->id)
            ->whereIn('cycle_start', $quotas->map(fn (Quota $quota) => $this->cycleStartOf($group, $quota))->unique()->values()->all())
            ->get()
            ->keyBy(fn (GroupCycleSnapshot $snapshot) => $snapshot->cycle_start->toDateString());
    }

    private function cycleStartOf(Group $group, Quota $quota): string
    {
        return BillingCycle::cycleFor($group->closing_day, $quota->date_expected)['start']->toDateString();
    }
}
