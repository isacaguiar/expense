<?php

namespace App\Services;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Visão pessoal por grupo: para cada grupo do usuário, no ciclo em foco, o que
 * ele deve (e a quem), o que tem a receber (e de quem), os itens do ciclo de
 * que participa e as flags de ação, tudo calculado aqui para o cliente não
 * recompor rateio, ciclo nem permissão. Ver
 * docs/feature/20261010-painel-financeiro-pessoal/plan.md §1.3.
 *
 * Só lê: usa `CycleSummaryService::view()`, que não sela nem notifica.
 * "Devo" e "a receber" são o acerto **líquido** par a par (`settlements`), o
 * valor que a pessoa de fato transfere; "Paga/Pendente" nos itens é só
 * informação (o que o credor marcou) e nunca entra nesses totais.
 */
class PersonalOverviewService
{
    public function __construct(private readonly CycleSummaryService $cycles) {}

    /**
     * @return array{user: array{id: int, name: string}, groups: array<int, array<string, mixed>>}
     */
    public function forUser(User $user): array
    {
        // O escopo é a própria consulta: só grupos não deletados em que o
        // usuário é membro (mesmo filtro de GroupController::index).
        $groups = Group::where('deleted', 0)
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id))
            ->with('members')
            ->get();

        $items = $groups->map(fn (Group $group) => $this->forGroup($group, $user))->all();

        // Com algo em aberto primeiro, depois por nome (sem acento, sem caixa).
        usort($items, fn (array $a, array $b) => ($b['has_open'] <=> $a['has_open'])
            ?: strcasecmp(Str::ascii($a['group']['name']), Str::ascii($b['group']['name'])));

        return [
            'user' => ['id' => $user->id, 'name' => $user->name],
            'groups' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function forGroup(Group $group, User $user): array
    {
        $cyclesAgo = $this->cycles->focus($group);
        $view = $this->cycles->view($group, $cyclesAgo);

        $cycle = $view['cycle'] + ['cycles_ago' => $cyclesAgo];
        $summary = $view['summary'];

        // Mesma condição de `confirmSettlement`: ciclo fechado (por data ou
        // manualmente) e ainda não selado.
        $canConfirm = in_array($cycle['status'], ['closed', 'closed_manually'], true) && ! $cycle['settled'];

        $members = $group->members->keyBy('id');
        $toPay = [];
        $toReceive = [];

        foreach ($summary['settlements'] as $settlement) {
            $fromId = (int) $settlement['from_user_id'];
            $toId = (int) $settlement['to_user_id'];

            if ($fromId === $user->id) {
                $toPay[] = [
                    'to_user' => $this->person($members, $toId, withContact: true),
                    'amount' => (float) $settlement['amount'],
                    'confirmed_at' => $settlement['confirmedAt'] ?? null,
                    'confirmed_proof_url' => $settlement['confirmedProofUrl'] ?? null,
                    'can_confirm' => $canConfirm,
                ];
            } elseif ($toId === $user->id) {
                $toReceive[] = [
                    'from_user' => $this->person($members, $fromId, withContact: false),
                    'amount' => (float) $settlement['amount'],
                    'confirmed_at' => $settlement['confirmedAt'] ?? null,
                    'confirmed_proof_url' => $settlement['confirmedProofUrl'] ?? null,
                ];
            }
        }

        $owe = $this->totals($toPay);
        $receive = $this->totals($toReceive);
        $myItems = $this->itemsOf($summary['expenses'], $group, $user->id);

        return [
            'group' => ['id' => $group->id, 'name' => $group->name],
            'cycle' => $cycle,
            'owe' => $owe,
            'receive' => $receive,
            'to_pay' => $toPay,
            'to_receive' => $toReceive,
            'my_items' => $myItems,
            'pending_items_count' => count(array_filter($myItems, fn (array $item) => ! $item['paid'])),
            'has_open' => $owe['open'] > 0 || $receive['open'] > 0,
        ];
    }

    /**
     * Soma dos acertos, dos já confirmados com comprovante e do que falta.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{total: float, confirmed: float, open: float}
     */
    private function totals(array $rows): array
    {
        $total = round(array_sum(array_column($rows, 'amount')), 2);
        $confirmed = round(array_sum(array_column(
            array_filter($rows, fn (array $row) => $row['confirmed_at'] !== null),
            'amount'
        )), 2);

        return ['total' => $total, 'confirmed' => $confirmed, 'open' => round($total - $confirmed, 2)];
    }

    /**
     * A pessoa do outro lado de um acerto. Só o credor (a quem o usuário vai
     * pagar) leva e-mail e Pix, que o Pix precisa; o devedor não.
     *
     * @param  Collection<int, User>  $members
     * @return array<string, mixed>
     */
    private function person(Collection $members, int $userId, bool $withContact): array
    {
        $member = $members->get($userId);

        $person = ['id' => $userId, 'name' => $member?->name ?? 'Desconhecido'];

        if ($withContact) {
            $person += ['email' => $member?->email, 'pix' => $member?->pix];
        }

        return $person + ['avatar_url' => $member?->avatar_url];
    }

    /**
     * Os itens do ciclo de que o usuário participa ou que ele pagou, sem as
     * parcelas retroativas nascidas quitadas (histórico, não dívida — a mesma
     * regra do cálculo dos acertos).
     *
     * @param  array<int, array<string, mixed>>  $expenses
     * @return array<int, array<string, mixed>>
     */
    private function itemsOf(array $expenses, Group $group, int $userId): array
    {
        $idsByName = $group->members->pluck('id', 'name');
        $items = [];

        foreach ($expenses as $expense) {
            if (! empty($expense['bornPaid'])) {
                continue;
            }

            $participantIds = $this->participantIds($expense, $idsByName);
            $creditorId = isset($expense['userPayerId']) ? (int) $expense['userPayerId'] : null;

            $isCreditor = $creditorId === $userId;
            $isParticipant = in_array($userId, $participantIds, true);

            if (! $isCreditor && ! $isParticipant) {
                continue;
            }

            $paid = (bool) $expense['paid'];

            $items[] = [
                'expense_id' => (int) $expense['id'],
                'description' => $expense['description'],
                'date' => $expense['date'],
                'value' => (float) $expense['value'],
                'my_share' => $isParticipant ? $this->shareOf($expense) : 0.0,
                'paid' => $paid,
                'role' => $isCreditor ? 'creditor' : 'debtor',
                'creditor' => ['id' => $creditorId, 'name' => $expense['payerName'] ?? null],
                'installment' => $this->installmentOf($expense),
                'can_pay' => $isCreditor && ! $paid,
                'can_unpay' => $isCreditor && $paid,
            ];
        }

        return $items;
    }

    /**
     * Ids dos participantes da despesa. Snapshots antigos não têm
     * `participantDetails`: nesse caso resolve pelos nomes, pelos membros do grupo.
     *
     * @param  array<string, mixed>  $expense
     * @param  Collection<string, int>  $idsByName
     * @return array<int, int>
     */
    private function participantIds(array $expense, Collection $idsByName): array
    {
        $details = $expense['participantDetails'] ?? [];

        if (! empty($details)) {
            return array_map(fn (array $participant) => (int) $participant['id'], $details);
        }

        $ids = [];
        foreach ($expense['participants'] ?? [] as $name) {
            if ($idsByName->has($name)) {
                $ids[] = (int) $idsByName->get($name);
            }
        }

        return $ids;
    }

    /**
     * A parte de cada participante: o `valuePerPerson` do ciclo, ou valor ÷
     * participantes quando o snapshot é antigo demais para trazê-lo.
     *
     * @param  array<string, mixed>  $expense
     */
    private function shareOf(array $expense): float
    {
        if (isset($expense['valuePerPerson'])) {
            return (float) $expense['valuePerPerson'];
        }

        return round((float) $expense['value'] / max(count($expense['participants'] ?? []), 1), 2);
    }

    /**
     * `{number, total}` só em despesa parcelada; À Vista e Fixa não têm
     * semântica de "parcela n de N".
     *
     * @param  array<string, mixed>  $expense
     * @return array{number: int, total: int}|null
     */
    private function installmentOf(array $expense): ?array
    {
        if (($expense['expenseType'] ?? null) !== 'IN_INSTALLMENTS' || ! isset($expense['installmentNumber'])) {
            return null;
        }

        return [
            'number' => (int) $expense['installmentNumber'],
            'total' => (int) ($expense['installmentsTotal'] ?? 0),
        ];
    }
}
