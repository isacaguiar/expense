<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Quota;
use App\Models\SettlementConfirmation;
use App\Support\BillingCycle;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Lado de **leitura** do cálculo de ciclo: entradas da competência, totais,
 * saldos, liquidação par-a-par, confirmações e a checagem de "totalmente
 * quitado". Extraído de `ExpenseController` sem mudar regra
 * (docs/feature/20261010-painel-financeiro-pessoal/plan.md §0.2): o Painel
 * Financeiro Pessoal precisa do mesmo cálculo fora do controller.
 *
 * Nada aqui grava: selagem (`GroupCycleSnapshot`) e notificação continuam no
 * controller, que tem os efeitos colaterais.
 */
class CycleSummaryService
{
    /**
     * Quantos ciclos para trás o `focus()` varre à procura de um ciclo
     * fechado ainda não quitado. Um ciclo pendente há mais de um ano é caso
     * de exceção — não vale pagar o custo de varrer o histórico inteiro a
     * cada abertura de grupo.
     */
    public const FOCUS_LOOKBACK = 12;

    /**
     * Despesas cujo valor conta para um ciclo [start, end]: diretas (date_payment
     * dentro do intervalo) + Fixa projetada mês a mês dentro do intervalo (mesma
     * regra de corte de recorrência de indexByGroup, adaptada de mês único para
     * intervalo de datas, que pode atravessar dois meses calendário).
     *
     * @return \Illuminate\Support\Collection<int, array{expense: Expense, date: Carbon, value: float, paid: bool, bornPaid: bool, paymentProofUrl: ?string, paymentNote: ?string}>
     */
    public function entries($groupId, Carbon $start, Carbon $end): Collection
    {
        $entries = collect();

        $direct = Expense::where('group_id', $groupId)
            ->where('deleted', false)
            ->where('expense_type', '!=', 'IN_INSTALLMENTS')
            ->whereBetween('date_payment', [$start->toDateString(), $end->toDateString()])
            ->where(function ($query) use ($start) {
                $query->where('expense_type', '!=', 'FIXED')
                    ->orWhereNull('fixed_recurrence_ends_at')
                    ->orWhere('fixed_recurrence_ends_at', '>', $start->copy()->startOfMonth());
            })
            ->with(['payer', 'payers', 'quotas'])
            ->get();

        foreach ($direct as $expense) {
            $quota = $expense->quotas->first(fn (Quota $quota) => $quota->date_expected->between($start, $end));
            $quota?->setRelation('expense', $expense);

            $entries->push([
                'expense' => $expense,
                'date' => $expense->date_payment->copy(),
                'value' => (float) ($quota->value_quota ?? $expense->total_value),
                'paid' => (bool) ($quota->paid ?? false),
                'bornPaid' => (bool) ($quota->born_paid ?? false),
                'quotaNumber' => $quota->number ?? null,
                'paymentProofUrl' => $quota->payment_proof_url ?? null,
                'paymentNote' => $quota->payment_note ?? null,
            ]);
        }

        // Parcelas de despesas IN_INSTALLMENTS cujo vencimento (date_expected) cai neste
        // ciclo — inclui o ciclo de criação (1ª parcela) e os seguintes, ao contrário de
        // $direct, que só olharia o date_payment da despesa (mês de criação).
        $installmentQuotas = Quota::whereBetween('date_expected', [$start->toDateString(), $end->toDateString()])
            ->whereHas('expense', function ($query) use ($groupId) {
                $query->where('group_id', $groupId)
                    ->where('deleted', false)
                    ->where('expense_type', 'IN_INSTALLMENTS');
            })
            ->with(['expense.payer', 'expense.payers'])
            ->get();

        foreach ($installmentQuotas as $quota) {
            $entries->push([
                'expense' => $quota->expense,
                'date' => $quota->date_expected->copy(),
                'value' => (float) $quota->value_quota,
                'paid' => (bool) $quota->paid,
                'bornPaid' => (bool) $quota->born_paid,
                // Nº da parcela que vence NESTE ciclo — é o que a UI usa pra
                // montar "Parcelada 2/6". Só faz sentido em IN_INSTALLMENTS.
                'quotaNumber' => $quota->number,
                'paymentProofUrl' => $quota->payment_proof_url,
                'paymentNote' => $quota->payment_note,
            ]);
        }

        $fixedCandidates = Expense::where('group_id', $groupId)
            ->where('deleted', false)
            ->where('expense_type', 'FIXED')
            ->where('date_payment', '<', $start->toDateString())
            ->where(function ($query) use ($start) {
                $query->whereNull('fixed_recurrence_ends_at')
                    ->orWhere('fixed_recurrence_ends_at', '>', $start);
            })
            ->with(['payer', 'payers', 'quotas'])
            ->get();

        foreach ($fixedCandidates as $expense) {
            $cursor = $start->copy()->startOfMonth();

            while ($cursor->lte($end)) {
                $day = min($expense->date_payment->day, $cursor->daysInMonth);
                $occurrence = $cursor->copy()->day($day)->startOfDay();

                $recurrenceActive = is_null($expense->fixed_recurrence_ends_at)
                    || $expense->fixed_recurrence_ends_at->gt($occurrence);

                if ($occurrence->between($start, $end) && $recurrenceActive) {
                    // Se a ocorrência já foi congelada (materializeFixedOccurrenceQuota,
                    // chamado no fechamento ou no pagamento), usa o valor/status
                    // persistidos; senão projeta ao vivo a partir do total_value atual.
                    $quota = $expense->quotas->first(fn (Quota $quota) => $quota->date_expected->isSameDay($occurrence));
                    $quota?->setRelation('expense', $expense);

                    $entries->push([
                        'expense' => $expense,
                        'date' => $occurrence,
                        'value' => (float) ($quota->value_quota ?? $expense->total_value),
                        'paid' => (bool) ($quota->paid ?? false),
                        // FIXED nunca nasce born_paid (store()/materializeFixedOccurrenceQuota
                        // não gravam o flag) — sempre false aqui.
                        'bornPaid' => (bool) ($quota->born_paid ?? false),
                        // Ocorrência ainda projetada (não materializada) não tem Quota,
                        // logo não tem número — e FIXED não tem semântica de "parcela n/N".
                        'quotaNumber' => $quota->number ?? null,
                        'paymentProofUrl' => $quota->payment_proof_url ?? null,
                        'paymentNote' => $quota->payment_note ?? null,
                    ]);
                }

                $cursor->addMonth();
            }
        }

        return $entries;
    }

    /**
     * Calcula totais, lista de despesas, saldos por pessoa e liquidação
     * par-a-par (quem paga a quem) de um ciclo [start, end], sempre ao vivo
     * a partir de `Expense`/`Quota`.
     *
     * @return array{totals: array, expenses: array, balances: array, settlements: array}
     */
    public function compute(Group $group, Carbon $start, Carbon $end): array
    {
        $entries = $this->entries($group->id, $start, $end);

        $expenses = $entries
            ->map(function (array $entry) {
                $participantsCount = max($entry['expense']->payers->count(), 1);

                return [
                    'id' => $entry['expense']->id,
                    'description' => $entry['expense']->description,
                    'date' => $entry['date']->toDateString(),
                    'value' => $entry['value'],
                    'valuePerPerson' => round($entry['value'] / $participantsCount, 2),
                    'paid' => $entry['paid'],
                    'bornPaid' => $entry['bornPaid'],
                    'paymentProofUrl' => $entry['paymentProofUrl'],
                    // Aditivo (Constitution §4.1): descrição do pagamento; snapshots
                    // selados antes da feature 20261021 não trazem este campo.
                    'paymentNote' => $entry['paymentNote'],
                    'payerName' => $entry['expense']->payer->name ?? null,
                    // Aditivo (Constitution §4.1): sibling de payerName/participants,
                    // não substitui — avatar do credor/participantes pra exibição
                    // (docs/feature/20260904-avatar-usuario-listagens/plan.md §1).
                    'payerAvatarUrl' => $entry['expense']->payer->avatar_url ?? null,
                    'participants' => $entry['expense']->payers->pluck('name')->values()->all(),
                    'participantDetails' => $entry['expense']->payers
                        ->map(fn ($payer) => [
                            'id' => $payer->id,
                            'name' => $payer->name,
                            'avatarUrl' => $payer->avatar_url,
                        ])
                        ->values()
                        ->all(),
                    'isFixed' => $entry['expense']->expense_type === 'FIXED',
                    // Aditivos (Constitution §4.1): `isFixed` continua — filtro/ícone de
                    // ExpenseManager, Payments e testes dependem dele. Estes 4 permitem à
                    // UI distinguir À Vista de Parcelada e montar "Parcelada 2/6" com o
                    // total da despesa (docs/feature/concluidas/202609/20260904-detalhe-despesa-tipo-parcela-valores/plan.md §1).
                    'expenseType' => $entry['expense']->expense_type,
                    'installmentNumber' => $entry['quotaNumber'],
                    'installmentsTotal' => $entry['expense']->installments,
                    'totalValue' => (float) $entry['expense']->total_value,
                    // IDs (não só nomes) — o frontend precisa deles pra decidir se o
                    // usuário logado é o credor (pode pagar) ou dono (pode editar/
                    // excluir: authorizeExpenseOwner() aceita criador OU credor),
                    // sem depender de comparar nomes.
                    'userPayerId' => $entry['expense']->user_payer_id,
                    'userCreatorId' => $entry['expense']->user_creator_id,
                ];
            })
            // Não pago primeiro (o que falta quitar fica em alerta no topo),
            // cronológico dentro de cada bloco. `paid` é bool → `false` < `true`.
            ->sortBy([['paid', 'asc'], ['date', 'asc']])
            ->values()
            ->all();

        $totals = [
            'total' => round($entries->sum('value'), 2),
            'paid' => round($entries->where('paid', true)->sum('value'), 2),
            'pending' => round($entries->where('paid', false)->sum('value'), 2),
        ];

        $balances = [];
        foreach ($group->members as $member) {
            $balances[$member->id] = [
                'user_id' => $member->id,
                'name' => $member->name,
                'avatarUrl' => $member->avatar_url,
                'balance' => 0.0,
            ];
        }

        // $owed[$credorId][$devedorId] = quanto o devedor deve ao credor,
        // somado em bruto por despesa (antes de netar par a par). Base para
        // $settlements abaixo; $balances continua vindo do mesmo loop, só
        // agregando direto por pessoa em vez de por par.
        $owed = [];

        foreach ($entries as $entry) {
            // Parcela retroativa nascida quitada (born_paid, ver
            // ExpenseController::store()) é registro histórico, não dívida — não
            // entra em balances/settlements. NÃO filtrar por $entry['paid']: uma
            // quota que o credor pagou depois via pay() continua gerando
            // settlement até o devedor confirmar (feature
            // 20260902-pagamento-ciclo-fechado). Ver
            // docs/feature/concluidas/202609/20260904-parcela-retroativa-contabilizacao/specify.md §2.1.
            if ($entry['bornPaid']) {
                continue;
            }

            $expense = $entry['expense'];
            $participants = $expense->payers;
            $participantsCount = max($participants->count(), 1);
            $valuePerPerson = $entry['value'] / $participantsCount;
            $payerId = $expense->user_payer_id;

            foreach ($participants as $participant) {
                if ($participant->id === $payerId) {
                    continue;
                }

                if (isset($balances[$participant->id])) {
                    $balances[$participant->id]['balance'] -= $valuePerPerson;
                }

                if (isset($balances[$payerId])) {
                    $balances[$payerId]['balance'] += $valuePerPerson;
                }

                $owed[$payerId][$participant->id] = ($owed[$payerId][$participant->id] ?? 0) + $valuePerPerson;
            }
        }

        $balances = collect($balances)
            ->map(function (array $balance) {
                $balance['balance'] = round($balance['balance'], 2);

                return $balance;
            })
            ->values()
            ->all();

        // Liquidação par-a-par: neta cada par (credor, devedor) contra o
        // sentido inverso (mesma lógica de
        // GroupExpenseReportController::reportByGroupAndYearMonthlySettlement,
        // aqui chaveada por user_id em vez de nome) — cada par gera no máximo
        // 1 entrada, na direção líquida. Marca pares já resolvidos por uma
        // chave normalizada (não por "quem tem o menor id"): um par só existe
        // em $owed[maiorCredorId] se aquela pessoa alguma vez pagou por
        // alguém — pode não existir do lado do menor id, então não dá pra
        // usar "id < id" pra decidir quem processa sem perder pares.
        $settlements = [];
        $processedPairs = [];
        foreach ($owed as $creditorId => $debtors) {
            foreach ($debtors as $debtorId => $amount) {
                if ($debtorId === $creditorId) {
                    continue;
                }

                $pairKey = min($creditorId, $debtorId).':'.max($creditorId, $debtorId);
                if (isset($processedPairs[$pairKey])) {
                    continue;
                }
                $processedPairs[$pairKey] = true;

                $reverse = $owed[$debtorId][$creditorId] ?? 0;
                $net = round($amount - $reverse, 2);

                if ($net > 0) {
                    $settlements[] = ['from_user_id' => $debtorId, 'to_user_id' => $creditorId, 'amount' => $net];
                } elseif ($net < 0) {
                    $settlements[] = ['from_user_id' => $creditorId, 'to_user_id' => $debtorId, 'amount' => round(-$net, 2)];
                }
            }
        }

        return ['totals' => $totals, 'expenses' => $expenses, 'balances' => $balances, 'settlements' => $settlements];
    }

    /**
     * Decora cada settlement com o comprovante de confirmação do devedor, se
     * existir (`SettlementConfirmation`, specify.md §2.7) — chamado nos 3
     * pontos que devolvem `settlements` na resposta HTTP (`summary()`,
     * `close()`, `reopen()`), depois que qualquer um dos 3 caminhos que
     * produzem o array (`compute` ao vivo, snapshot manual,
     * snapshot fechado por data) já rodou — não precisa decorar dentro de
     * cada um separadamente.
     */
    public function withConfirmations($groupId, Carbon $start, array $settlements): array
    {
        $confirmations = SettlementConfirmation::where('group_id', $groupId)
            ->where('cycle_start', $start->toDateString())
            ->get()
            ->keyBy(fn ($c) => "{$c->from_user_id}-{$c->to_user_id}");

        return collect($settlements)
            ->map(function (array $settlement) use ($confirmations) {
                $confirmation = $confirmations->get("{$settlement['from_user_id']}-{$settlement['to_user_id']}");

                $settlement['confirmedProofUrl'] = $confirmation?->proof_url;
                $settlement['confirmedNote'] = $confirmation?->note;
                $settlement['confirmedAt'] = $confirmation?->confirmed_at?->toIso8601String();

                return $settlement;
            })
            // Acerto ainda não confirmado primeiro (pagamento em aberto fica em
            // alerta no topo), depois por valor decrescente.
            ->sortBy(fn (array $s) => [$s['confirmedAt'] === null ? 0 : 1, -$s['amount']])
            ->values()
            ->all();
    }

    /**
     * Um ciclo [start, end] está "totalmente quitado" quando toda entrada da
     * competência está paga (`totals.pending == 0`) E todo par de `settlements`
     * tem uma `SettlementConfirmation` correspondente. Ciclo sem entradas conta
     * como quitado. Base da selagem (`ExpenseController::sealCycleIfSettled`) e do
     * endpoint focus-cycle — ver
     * docs/feature/concluidas/202609/20260902-pagamento-ciclo-fechado/plan.md §0.3.
     */
    public function isFullySettled(Group $group, Carbon $start, Carbon $end): bool
    {
        return $this->summaryIsFullySettled($this->compute($group, $start, $end), $group->id, $start);
    }

    /**
     * A mesma checagem de `isFullySettled`, sobre um `$summary` que o chamador
     * já tem em mãos (evita calcular o ciclo duas vezes).
     *
     * @param  array{totals: array, settlements: array}  $summary
     */
    public function summaryIsFullySettled(array $summary, $groupId, Carbon $start): bool
    {
        if (round((float) $summary['totals']['pending'], 2) > 0) {
            return false;
        }

        $settlements = $summary['settlements'];

        if (empty($settlements)) {
            return true;
        }

        $confirmedPairs = SettlementConfirmation::where('group_id', $groupId)
            ->where('cycle_start', $start->toDateString())
            ->get()
            ->map(fn ($confirmation) => "{$confirmation->from_user_id}-{$confirmation->to_user_id}")
            ->all();

        foreach ($settlements as $settlement) {
            $pair = "{$settlement['from_user_id']}-{$settlement['to_user_id']}";

            if (! in_array($pair, $confirmedPairs, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Em qual competência o app deve abrir o grupo, como `cycles_ago`: o ciclo
     * **fechado (ou na janela de carência) mais recente que ainda não está
     * totalmente quitado** (`isFullySettled`), varrendo até `FOCUS_LOOKBACK`
     * ciclos para trás. Se nenhum tem pendência, devolve `0` — o app abre na
     * competência vigente (comportamento pré-`20260902`). Ciclo `future` (ou
     * `open` cuja fronteira ainda não passou) não conta; ciclo selado é pulado.
     * Um ciclo em carência (`open` mas com `end` no passado) conta: a Home fica
     * nele durante os dias de carência e não pula quando ele vira `closed` em
     * `closesAt`. Ver
     * docs/feature/concluidas/202609/20260902-pagamento-ciclo-fechado/plan.md §4 e §10.
     *
     * Custo: um ciclo que termina antes da primeira data em que o grupo pode ter
     * entrada (`firstPossibleEntryDate`) não tem entrada alguma, logo é
     * "quitado" e o laço o pularia — então nem é calculado, e os snapshots dos
     * demais vêm numa só consulta. O resultado é o mesmo da varredura completa
     * (docs/feature/20261010-painel-financeiro-pessoal/tasks.md, TASK-427).
     */
    public function focus(Group $group): int
    {
        $firstEntryDate = $this->firstPossibleEntryDate($group);

        // Sem despesa viva não há entrada em ciclo nenhum.
        if ($firstEntryDate === null) {
            return 0;
        }

        $cycles = [];

        for ($ago = 0; $ago <= self::FOCUS_LOOKBACK; $ago++) {
            $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $ago);

            // Os ciclos recuam no tempo: se este já termina antes da primeira
            // entrada possível, os seguintes também.
            if ($cycle['end']->toDateString() < $firstEntryDate->toDateString()) {
                break;
            }

            $cycles[$ago] = $cycle;
        }

        $snapshots = GroupCycleSnapshot::where('group_id', $group->id)
            ->whereIn('cycle_start', array_map(fn (array $cycle) => $cycle['start']->toDateString(), $cycles))
            ->get()
            ->keyBy(fn (GroupCycleSnapshot $snapshot) => $snapshot->cycle_start->toDateString());

        foreach ($cycles as $ago => $cycle) {
            $snapshot = $snapshots->get($cycle['start']->toDateString());

            if ($snapshot && $snapshot->isSealed()) {
                continue;
            }

            $cycleIsClosed = $cycle['status'] === 'closed'
                || ($cycle['status'] === 'open' && $snapshot && $snapshot->isManuallyClosedAndActive());

            // Ciclo na carência: fronteira já passou, mas ainda `open` (fecha só
            // em `closesAt`). Nunca acontece para `$ago = 0` (o ciclo vigente
            // nunca tem `end` no passado).
            $inGrace = $cycle['status'] === 'open'
                && Carbon::now()->startOfDay()->gt($cycle['end']);

            if (! $cycleIsClosed && ! $inGrace) {
                continue;
            }

            if (! $this->isFullySettled($group, $cycle['start'], $cycle['end'])) {
                return $ago;
            }
        }

        return 0;
    }

    /**
     * A primeira data em que o grupo pode ter uma entrada de ciclo (`entries`):
     * a menor entre o `date_payment` das despesas vivas e o `date_expected` das
     * quotas delas. Toda entrada cai nessa data ou depois — À Vista e Fixa a
     * partir do `date_payment`, Fixa projetada só para a frente, e Parcelada
     * pelo vencimento da quota, que numa parcela retroativa pode ser anterior
     * ao `date_payment`. `null` quando o grupo não tem despesa viva.
     */
    private function firstPossibleEntryDate(Group $group): ?Carbon
    {
        $firstExpense = Expense::where('group_id', $group->id)
            ->where('deleted', false)
            ->min('date_payment');

        // Quota de despesa viva implica despesa viva: uma consulta a menos.
        if ($firstExpense === null) {
            return null;
        }

        $firstQuota = Quota::whereHas('expense', function ($query) use ($group) {
            $query->where('group_id', $group->id)->where('deleted', false);
        })->min('date_expected');

        $first = Carbon::parse($firstExpense)->startOfDay();

        if ($firstQuota !== null && Carbon::parse($firstQuota)->startOfDay()->lt($first)) {
            return Carbon::parse($firstQuota)->startOfDay();
        }

        return $first;
    }

    /**
     * O ciclo `$cyclesAgo` de um grupo como o `summary()` o serviria — ciclo,
     * totais, despesas, saldos e acertos com as confirmações — **sem a selagem
     * preguiçosa**: quem só quer ler (a visão pessoal do Painel) não pode gravar
     * snapshot nem disparar `Notifier::cycleSettled` por causa de um GET.
     *
     * A escolha da fonte é a de `ExpenseController::summary()`:
     * - ciclo selado → a foto imutável do snapshot (`status` `closed`, `settled`);
     * - fechado por data, ou por fechamento manual ainda ativo, e não selado →
     *   cálculo ao vivo (`closed`, ou `closed_manually` no manual);
     * - demais → cálculo ao vivo (`open`, ou `future`).
     * A única diferença deliberada é não selar: `summary()` sela, na leitura, um
     * ciclo fechado que já esteja todo quitado. `in_grace` é novo: a fronteira
     * já passou e o ciclo ainda está `open` (a carência de `BillingCycle`).
     *
     * @return array{
     *     cycle: array{start: string, end: string, closes_at: string, status: string, settled: bool, in_grace: bool},
     *     summary: array{totals: array, expenses: array, balances: array, settlements: array},
     *     sealed: bool
     * }
     */
    public function view(Group $group, int $cyclesAgo): array
    {
        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $cyclesAgo);
        $start = $cycle['start'];
        $end = $cycle['end'];
        $status = $cycle['status'];

        $snapshot = GroupCycleSnapshot::where('group_id', $group->id)
            ->where('cycle_start', $start->toDateString())
            ->first();
        $sealed = (bool) ($snapshot && $snapshot->isSealed());

        $inGrace = false;

        if ($sealed) {
            // Ciclo totalmente quitado → foto imutável.
            $status = 'closed';
            $summary = [
                'totals' => $snapshot->totals,
                'expenses' => $snapshot->expenses,
                'balances' => $snapshot->balances,
                'settlements' => $snapshot->settlements,
            ];
        } else {
            $inGrace = $status === 'open' && Carbon::now()->startOfDay()->gt($end);

            if ($status === 'open' && $snapshot && $snapshot->isManuallyClosedAndActive()) {
                $status = 'closed_manually';
            }

            $summary = $this->compute($group, $start, $end);
        }

        $summary['settlements'] = $this->withConfirmations($group->id, $start, $summary['settlements']);

        return [
            'cycle' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'closes_at' => BillingCycle::closesAt($end)->toDateString(),
                'status' => $status,
                'settled' => $sealed,
                'in_grace' => $inGrace,
            ],
            'summary' => $summary,
            'sealed' => $sealed,
        ];
    }
}
