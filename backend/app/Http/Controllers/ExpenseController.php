<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Quota;
use App\Support\BillingCycle;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function indexByGroup($groupId, Request $request)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        $data = $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|between:1,12',
        ]);

        $monthStart = Carbon::create($data['year'], $data['month'], 1);

        $mapRow = function (Expense $expense, ?Carbon $projectedDate = null) {
            return [
                'id' => $expense->id,
                'description' => $expense->description,
                'date' => ($projectedDate ?? $expense->date_payment)->toDateString(),
                'value' => $expense->total_value,
                'payerName' => $expense->payers->pluck('name')->implode(', '),
                'isFixed' => $expense->expense_type === 'FIXED',
            ];
        };

        // Despesas cujo date_payment cai neste mês (à vista, parcelas, e a criação da Fixa).
        // Fixa também respeita o corte de recorrência aqui: se o corte é o próprio mês de
        // criação (ou anterior), a despesa não deve aparecer nem nesse mês.
        $direct = Expense::where('group_id', $groupId)
            ->where('deleted', false)
            ->where('expense_type', '!=', 'IN_INSTALLMENTS')
            ->whereYear('date_payment', $data['year'])
            ->whereMonth('date_payment', $data['month'])
            ->where(function ($query) use ($monthStart) {
                $query->where('expense_type', '!=', 'FIXED')
                    ->orWhereNull('fixed_recurrence_ends_at')
                    ->orWhere('fixed_recurrence_ends_at', '>', $monthStart);
            })
            ->with('payers')
            ->get();

        // Despesas Fixa criadas em mês anterior, ainda ativas neste mês (projeção virtual).
        $projectedFixed = Expense::where('group_id', $groupId)
            ->where('deleted', false)
            ->where('expense_type', 'FIXED')
            ->where('date_payment', '<', $monthStart)
            ->where(function ($query) use ($monthStart) {
                $query->whereNull('fixed_recurrence_ends_at')
                    ->orWhere('fixed_recurrence_ends_at', '>', $monthStart);
            })
            ->with('payers')
            ->get();

        // Parcelas de despesas IN_INSTALLMENTS cujo vencimento (date_expected) cai neste
        // mês — inclui o mês de criação (1ª parcela) e os seguintes, ao contrário de
        // $direct, que só olha o date_payment da despesa (mês de criação).
        $installmentQuotas = Quota::whereYear('date_expected', $data['year'])
            ->whereMonth('date_expected', $data['month'])
            ->whereHas('expense', function ($query) use ($groupId) {
                $query->where('group_id', $groupId)
                    ->where('deleted', false)
                    ->where('expense_type', 'IN_INSTALLMENTS');
            })
            ->with('expense.payers')
            ->get();

        $mapQuotaRow = fn (Quota $quota) => [
            'id' => $quota->expense->id,
            'description' => $quota->expense->description,
            'date' => $quota->date_expected->toDateString(),
            'value' => $quota->value_quota,
            'payerName' => $quota->expense->payers->pluck('name')->implode(', '),
            'isFixed' => false,
        ];

        $expenses = $direct->map(fn (Expense $expense) => $mapRow($expense))
            ->concat($projectedFixed->map(function (Expense $expense) use ($monthStart, $mapRow) {
                $day = min($expense->date_payment->day, $monthStart->daysInMonth);

                return $mapRow($expense, $monthStart->copy()->day($day));
            }))
            ->concat($installmentQuotas->map($mapQuotaRow))
            ->sortBy('date')
            ->values();

        return response()->json($expenses);
    }

    public function show($id)
    {
        $expense = $this->findExpenseForMember($id);
        $expense->load(['payers', 'quotas']);

        return response()->json($expense);
    }

    public function update(Request $request, $id)
    {
        $expense = $this->findExpenseForMember($id);
        $this->authorizeExpenseOwner($expense);

        if ($response = $this->rejectIfCycleClosed($expense)) {
            return $response;
        }

        $data = $request->validate([
            'description' => 'sometimes|required|string|max:255',
            'date_payment' => 'sometimes|required|date',
            'total_value' => 'sometimes|required|numeric|min:0',
            'user_payer_id' => ['sometimes', 'required', Rule::exists('ex_groups_members', 'user_id')->where('group_id', $expense->group_id)],
            'payers' => 'sometimes|required|array|min:1',
            'payers.*' => Rule::exists('ex_groups_members', 'user_id')->where('group_id', $expense->group_id),
        ]);

        $expense->update(Arr::except($data, ['payers']));

        if (array_key_exists('payers', $data)) {
            $expense->payers()->sync($data['payers']);
        }

        return response()->json($expense->fresh(['payers', 'quotas']));
    }

    public function destroy($id)
    {
        $expense = $this->findExpenseForMember($id);
        $this->authorizeExpenseOwner($expense);

        if ($response = $this->rejectIfCycleClosed($expense)) {
            return $response;
        }

        $expense->update(['deleted' => true]);

        return response()->json(['message' => 'Despesa marcada como deletada.']);
    }

    /**
     * Competência passada é só leitura: `IN_CASH`/`IN_INSTALLMENTS` cujo
     * `date_payment` cai numa competência já fechada não podem mais ser
     * editados/apagados. `FIXED` fica de fora dessa checagem — é uma definição
     * recorrente, não presa a uma única competência; a materialização da
     * `Quota` de cada mês (`materializeFixedOccurrenceQuota`) já garante que
     * editar o valor depois não muda competências já congeladas.
     */
    private function rejectIfCycleClosed(Expense $expense): ?\Illuminate\Http\JsonResponse
    {
        if ($expense->expense_type === 'FIXED') {
            return null;
        }

        return $this->rejectIfCompetenceClosed($expense->group, $expense->date_payment);
    }

    /**
     * Retorna uma resposta 422 se a competência (mês de fatura, `BillingCycle`)
     * que contém `$referenceDate` está fechada — automaticamente (por data,
     * definitivo) ou manualmente (`GroupCycleSnapshot::closed_manually_at`
     * ainda ativo, revisável até a virada do mês). `null` se estiver aberta.
     * Único ponto de checagem de competência fechada, reaproveitado por toda
     * ação nova (fechar, reabrir, pagar, despagar, criar).
     */
    private function rejectIfCompetenceClosed(Group $group, Carbon $referenceDate): ?\Illuminate\Http\JsonResponse
    {
        $closedResponse = response()->json(['error' => 'Não é possível alterar dados de uma competência já fechada.'], 422);

        if (BillingCycle::statusFor($group->closing_day, $referenceDate, Carbon::now()) === 'closed') {
            return $closedResponse;
        }

        $cycleStart = BillingCycle::cycleFor($group->closing_day, $referenceDate)['start'];

        $snapshot = GroupCycleSnapshot::where('group_id', $group->id)
            ->where('cycle_start', $cycleStart->toDateString())
            ->first();

        if ($snapshot && $snapshot->isManuallyClosedAndActive()) {
            return $closedResponse;
        }

        return null;
    }

    public function store(Request $request)
    {
        $request->validate([
            'group_id' => 'required|exists:ex_groups,id',
        ]);

        $group = Group::findOrFail($request->group_id);
        $this->authorizeGroupMembership($group);

        $request->validate([
            'date_payment' => 'required|date',
            'description' => 'required|string|max:255',
            'expense_type' => 'required|in:IN_CASH,IN_INSTALLMENTS,FIXED',
            'installments' => 'required|integer|min:1',
            'total_value' => 'required|numeric|min:0',
            'user_creator_id' => 'required|exists:ex_users,id',
            'user_payer_id' => ['required', Rule::exists('ex_groups_members', 'user_id')->where('group_id', $request->group_id)],
            'payers' => 'required|array|min:1',
            'payers.*' => Rule::exists('ex_groups_members', 'user_id')->where('group_id', $request->group_id),
            'quotas' => 'required|array|min:1',
            'quotas.*.date_expected' => 'required|date',
            'quotas.*.number' => 'required|integer',
            'quotas.*.value_quota' => 'required|numeric|min:0',
        ]);

        // Ao contrário de update()/destroy(), aqui vale para todo expense_type
        // (inclusive FIXED) — criar uma despesa nova com date_payment dentro de
        // uma competência já fechada não tem a mesma justificativa de "definição
        // recorrente não presa a um ciclo" que existe para editar uma FIXED já
        // existente.
        if ($response = $this->rejectIfCompetenceClosed($group, Carbon::parse($request->date_payment))) {
            return $response;
        }

        if ($request->expense_type === 'FIXED') {
            if ((int) $request->installments !== 1) {
                return response()->json(['error' => 'Despesa fixa deve ter installments=1.'], 422);
            }

            if (count($request->quotas) !== 1) {
                return response()->json(['error' => 'Despesa fixa deve ter exatamente 1 quota.'], 422);
            }
        }

        if ($request->expense_type === 'IN_INSTALLMENTS') {
            if (count($request->quotas) !== (int) $request->installments) {
                return response()->json(['error' => 'A quantidade de quotas deve ser igual a installments.'], 422);
            }

            $quotasSum = round(array_sum(array_column($request->quotas, 'value_quota')), 2);
            $totalValue = round((float) $request->total_value, 2);

            if (abs($quotasSum - $totalValue) > 0.01) {
                return response()->json(['error' => 'A soma das quotas deve ser igual a total_value.'], 422);
            }
        }

        DB::beginTransaction();

        try {
            $expense = Expense::create([
                'create_date' => now(),
                'date_payment' => $request->date_payment,
                'description' => $request->description,
                'expense_type' => $request->expense_type,
                'installments' => $request->installments,
                'total_value' => $request->total_value,
                'group_id' => $request->group_id,
                'user_creator_id' => auth()->id(),
                'user_payer_id' => $request->user_payer_id,
                'deleted' => false,
            ]);

            // Pagadores
            $expense->payers()->syncWithoutDetaching($request->payers);

            // Quotas
            foreach ($request->quotas as $quotaData) {
                // Despesa nasce sempre PENDENTE — o cliente não decide o status
                // inicial de pagamento, mesmo que envie 'paid' no payload.
                $expense->quotas()->create([
                    'date_expected' => $quotaData['date_expected'],
                    'number' => $quotaData['number'],
                    'paid' => false,
                    'value_quota' => $quotaData['value_quota'],
                ]);
            }

            DB::commit();

            return response()->json(['message' => 'Despesa criada com sucesso', 'expense_id' => $expense->id], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['error' => 'Erro ao criar despesa', 'details' => $e->getMessage()], 500);
        }
    }

    public function stopRecurrence($expenseId, Request $request)
    {
        $data = $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|between:1,12',
        ]);

        $expense = Expense::findOrFail($expenseId);
        $group = Group::findOrFail($expense->group_id);
        $this->authorizeGroupMembership($group);

        if ($expense->expense_type !== 'FIXED') {
            return response()->json(['error' => 'Esta despesa não é do tipo Fixa.'], 422);
        }

        $cutoff = Carbon::create($data['year'], $data['month'], 1);
        $creationMonth = $expense->date_payment->copy()->startOfMonth();

        if ($cutoff->lt($creationMonth)) {
            return response()->json(['error' => 'O mês informado é anterior à criação da despesa.'], 422);
        }

        if (BillingCycle::statusFor($group->closing_day, $cutoff, Carbon::now()) === 'closed') {
            return response()->json(['error' => 'Não é possível interromper a recorrência em um ciclo já fechado.'], 422);
        }

        $expense->update(['fixed_recurrence_ends_at' => $cutoff]);

        return response()->json([
            'message' => 'Recorrência da despesa fixa interrompida.',
            'expense_id' => $expense->id,
            'fixed_recurrence_ends_at' => $cutoff->toDateString(),
        ]);
    }

    public function getMonthlyExpenses($groupId)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        $expenses = DB::table('ex_expenses')
            ->selectRaw('
                YEAR(date_payment) as year,
                MONTH(date_payment) as month,
                COUNT(*) as total_expenses,
                SUM(total_value) as total_value
            ')
            ->where('group_id', $groupId)
            ->where('deleted', false)
            ->groupBy(DB::raw('YEAR(date_payment), MONTH(date_payment)'))
            ->orderByRaw('YEAR(date_payment) DESC, MONTH(date_payment) DESC')
            ->get();

        return response()->json($expenses);
    }

    public function summary($groupId, Request $request)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        $data = $request->validate([
            'cycles_ago' => 'nullable|integer',
        ]);

        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $data['cycles_ago'] ?? 0);
        $start = $cycle['start'];
        $end = $cycle['end'];
        $status = $cycle['status'];

        // Fechamento manual só existe para uma competência ainda `open` por
        // data (`close()` só opera sobre "agora") — `closed`/`future` nunca
        // têm um a considerar.
        $manualSnapshot = $status === 'open'
            ? GroupCycleSnapshot::where('group_id', $groupId)->where('cycle_start', $start->toDateString())->first()
            : null;

        if ($status === 'closed') {
            $summary = $this->cycleSnapshotFor($group, $groupId, $start, $end);
        } elseif ($manualSnapshot && $manualSnapshot->isManuallyClosedAndActive()) {
            $status = 'closed_manually';
            $summary = [
                'totals' => $manualSnapshot->totals,
                'expenses' => $manualSnapshot->expenses,
                'balances' => $manualSnapshot->balances,
            ];
        } else {
            $summary = $this->computeCycleSummary($group, $groupId, $start, $end);
        }

        return response()->json([
            'cycle' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'status' => $status],
            'totals' => $summary['totals'],
            'expenses' => $summary['expenses'],
            'balances' => $summary['balances'],
        ]);
    }

    /**
     * Fechamento manual da competência vigente: congela a composição e os
     * valores considerados (inclusive de despesas FIXED, materializando a
     * Quota de cada ocorrência antes de calcular) num `GroupCycleSnapshot`.
     * Sempre opera sobre a competência que contém "agora" — que, por
     * construção de `BillingCycle::cycleFor` com `cyclesAgo=0`, está sempre
     * aberta em relação a "agora" (não há como chamar isto sobre uma
     * competência já fechada por data). Chamar de novo (re-fechar) recalcula
     * e sobrescreve a foto (upsert), o que cobre "a cópia pode ser
     * atualizada até a virada do mês".
     */
    public function close($groupId)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now());
        $start = $cycle['start'];
        $end = $cycle['end'];

        foreach ($this->collectCycleEntries($groupId, $start, $end) as $entry) {
            if ($entry['expense']->expense_type === 'FIXED') {
                $this->materializeFixedOccurrenceQuota($entry['expense'], $entry['date']);
            }
        }

        $summary = $this->computeCycleSummary($group, $groupId, $start, $end);

        $snapshot = GroupCycleSnapshot::updateOrCreate(
            ['group_id' => $groupId, 'cycle_start' => $start->toDateString()],
            [
                'cycle_end' => $end->toDateString(),
                'totals' => $summary['totals'],
                'expenses' => $summary['expenses'],
                'balances' => $summary['balances'],
                'closed_manually_at' => Carbon::now(),
                'reopened_at' => null,
            ]
        );

        return response()->json([
            'cycle' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'status' => 'closed_manually'],
            'totals' => $snapshot->totals,
            'expenses' => $snapshot->expenses,
            'balances' => $snapshot->balances,
        ]);
    }

    /**
     * Foto imutável de um ciclo já fechado: devolve a foto já persistida, ou
     * computa ao vivo (uma única vez) e persiste antes de devolver. A partir
     * daí, este ciclo nunca mais é recalculado — edições/exclusões de
     * despesa depois de fotografado não mudam mais o resultado.
     *
     * @return array{totals: array, expenses: array, balances: array}
     */
    private function cycleSnapshotFor(Group $group, $groupId, Carbon $start, Carbon $end): array
    {
        $snapshot = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', $start->toDateString())
            ->first();

        if ($snapshot) {
            return [
                'totals' => $snapshot->totals,
                'expenses' => $snapshot->expenses,
                'balances' => $snapshot->balances,
            ];
        }

        $summary = $this->computeCycleSummary($group, $groupId, $start, $end);

        try {
            GroupCycleSnapshot::create([
                'group_id' => $groupId,
                'cycle_start' => $start->toDateString(),
                'cycle_end' => $end->toDateString(),
                'totals' => $summary['totals'],
                'expenses' => $summary['expenses'],
                'balances' => $summary['balances'],
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Violação de unique(group_id, cycle_start): outra requisição já
            // fotografou este ciclo entre a consulta acima e este insert.
            // Usa a foto que a outra requisição já persistiu.
            $snapshot = GroupCycleSnapshot::where('group_id', $groupId)
                ->where('cycle_start', $start->toDateString())
                ->firstOrFail();

            return [
                'totals' => $snapshot->totals,
                'expenses' => $snapshot->expenses,
                'balances' => $snapshot->balances,
            ];
        }

        return $summary;
    }

    /**
     * Calcula totais, lista de despesas e saldos por pessoa de um ciclo
     * [start, end], sempre ao vivo a partir de `Expense`/`Quota`.
     *
     * @return array{totals: array, expenses: array, balances: array}
     */
    private function computeCycleSummary(Group $group, $groupId, Carbon $start, Carbon $end): array
    {
        $entries = $this->collectCycleEntries($groupId, $start, $end);

        $expenses = $entries
            ->map(fn (array $entry) => [
                'id' => $entry['expense']->id,
                'description' => $entry['expense']->description,
                'date' => $entry['date']->toDateString(),
                'value' => $entry['value'],
                'paid' => $entry['paid'],
                'payerName' => $entry['expense']->payer->name ?? null,
                'participants' => $entry['expense']->payers->pluck('name')->values()->all(),
                'isFixed' => $entry['expense']->expense_type === 'FIXED',
            ])
            ->sortBy('date')
            ->values()
            ->all();

        $totals = [
            'total' => round($entries->sum('value'), 2),
            'paid' => round($entries->where('paid', true)->sum('value'), 2),
            'pending' => round($entries->where('paid', false)->sum('value'), 2),
        ];

        $balances = [];
        foreach ($group->members as $member) {
            $balances[$member->id] = ['user_id' => $member->id, 'name' => $member->name, 'balance' => 0.0];
        }

        foreach ($entries as $entry) {
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
            }
        }

        $balances = collect($balances)
            ->map(function (array $balance) {
                $balance['balance'] = round($balance['balance'], 2);

                return $balance;
            })
            ->values()
            ->all();

        return ['totals' => $totals, 'expenses' => $expenses, 'balances' => $balances];
    }

    /**
     * Despesas cujo valor conta para um ciclo [start, end]: diretas (date_payment
     * dentro do intervalo) + Fixa projetada mês a mês dentro do intervalo (mesma
     * regra de corte de recorrência de indexByGroup, adaptada de mês único para
     * intervalo de datas, que pode atravessar dois meses calendário).
     *
     * @return \Illuminate\Support\Collection<int, array{expense: Expense, date: Carbon, value: float, paid: bool}>
     */
    private function collectCycleEntries($groupId, Carbon $start, Carbon $end)
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

            $entries->push([
                'expense' => $expense,
                'date' => $expense->date_payment->copy(),
                'value' => (float) ($quota->value_quota ?? $expense->total_value),
                'paid' => (bool) ($quota->paid ?? false),
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

                    $entries->push([
                        'expense' => $expense,
                        'date' => $occurrence,
                        'value' => (float) ($quota->value_quota ?? $expense->total_value),
                        'paid' => (bool) ($quota->paid ?? false),
                    ]);
                }

                $cursor->addMonth();
            }
        }

        return $entries;
    }

    /**
     * Congela a ocorrência mensal de uma despesa FIXED numa Quota real, usando
     * o `total_value` vigente no momento da chamada. Chamar de novo para o
     * mesmo mês não duplica — devolve a Quota já materializada. A partir daqui,
     * essa competência passa a ler o valor congelado (via `collectCycleEntries`),
     * independente de o `total_value` da despesa mudar depois.
     */
    private function materializeFixedOccurrenceQuota(Expense $expense, Carbon $occurrenceDate): Quota
    {
        $existing = $expense->quotas()
            ->whereDate('date_expected', $occurrenceDate->toDateString())
            ->first();

        if ($existing) {
            return $existing;
        }

        return $expense->quotas()->create([
            'date_expected' => $occurrenceDate->toDateString(),
            'number' => 1,
            'paid' => false,
            'value_quota' => $expense->total_value,
        ]);
    }

    private function findExpenseForMember($id): Expense
    {
        $expense = Expense::where('deleted', false)->findOrFail($id);
        $group = Group::findOrFail($expense->group_id);
        $this->authorizeGroupMembership($group);

        return $expense;
    }

    private function authorizeExpenseOwner(Expense $expense): void
    {
        $isOwner = auth()->id() === $expense->user_creator_id || auth()->id() === $expense->user_payer_id;

        abort_unless($isOwner, 403);
    }
}
