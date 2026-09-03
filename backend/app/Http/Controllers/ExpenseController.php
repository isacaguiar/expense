<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Quota;
use App\Models\SettlementConfirmation;
use App\Support\BillingCycle;
use App\Support\Notifier;
use App\Support\ProofStorage;
use App\Support\WhatsApp\WhatsAppNotifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    /**
     * Quantos ciclos para trás o `focusCycle()` varre à procura de um ciclo
     * fechado ainda não quitado. Um ciclo pendente há mais de um ano é caso
     * de exceção — não vale pagar o custo de varrer o histórico inteiro a
     * cada abertura de grupo.
     */
    private const FOCUS_LOOKBACK = 12;

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
        $this->hydrateQuotaExpense($expense);

        return response()->json($expense);
    }

    /**
     * Aponta a relação `expense` de cada Quota já carregada de volta para o
     * próprio `$expense` — o accessor `payment_proof_url` precisa de
     * `expense->group_id` para montar a URL assinada, e sem isso cada Quota
     * dispararia um SELECT (N+1).
     */
    private function hydrateQuotaExpense(Expense $expense): void
    {
        if ($expense->relationLoaded('quotas')) {
            $expense->quotas->each(fn (Quota $quota) => $quota->setRelation('expense', $expense));
        }
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
            // expense_type/installments/quotas: edição de tipo (só À Vista <->
            // Parcelada — Fixa fica de fora dos dois lados, ver abaixo).
            // docs/feature/20260826-editar-tipo-despesa/plan.md §1.
            'expense_type' => 'sometimes|required|in:IN_CASH,IN_INSTALLMENTS',
            'installments' => 'sometimes|required|integer|min:2',
            'quotas' => 'sometimes|required|array|min:1',
            'quotas.*.date_expected' => 'required_with:quotas|date',
            'quotas.*.number' => 'required_with:quotas|integer',
            'quotas.*.value_quota' => 'required_with:quotas|numeric|min:0',
        ]);

        $changingType = array_key_exists('expense_type', $data);

        // Transição de/para FIXED não é suportada por esta feature — é um
        // compromisso recorrente (materializa quota novo mês a mês), converter
        // de/pra ela é uma decisão maior que fica pra outro pedido. A regra
        // `in:IN_CASH,IN_INSTALLMENTS` já recusa 'FIXED' como alvo; falta só
        // recusar a origem.
        if ($expense->expense_type === 'FIXED' && $changingType) {
            return response()->json(['error' => 'Não é possível mudar o tipo de uma despesa fixa.'], 422);
        }

        // FIXED fica de fora: seu total_value é o valor do template pra
        // ocorrências futuras/ainda não materializadas — uma ocorrência já
        // paga já tem Quota própria congelada (materializeFixedOccurrenceQuota)
        // e não é afetada por essa edição, então não há o que proteger aqui.
        if ($expense->expense_type !== 'FIXED') {
            $anyQuotaPaid = $expense->quotas()->where('paid', true)->exists();

            // Regra pedida explicitamente pelo usuário: despesa parcelada com
            // qualquer parcela paga (na prática, sempre a 1ª primeiro) trava a
            // edição inteira — não só tipo/valor. Mesmo precedente de
            // destroy() (bloqueia a ação inteira, não um campo).
            if ($expense->expense_type === 'IN_INSTALLMENTS' && $anyQuotaPaid) {
                return response()->json(['error' => 'Não é possível editar uma despesa parcelada depois que a primeira parcela foi paga.'], 422);
            }

            if ($anyQuotaPaid && (array_key_exists('total_value', $data) || $changingType)) {
                return response()->json(['error' => 'Não é possível alterar o valor ou o tipo de uma despesa já paga.'], 422);
            }
        }

        if ($changingType) {
            $finalTotalValue = round((float) ($data['total_value'] ?? $expense->total_value), 2);

            if ($data['expense_type'] === 'IN_INSTALLMENTS') {
                if (! array_key_exists('installments', $data) || ! array_key_exists('quotas', $data)) {
                    return response()->json(['error' => 'Informe installments e quotas para parcelar a despesa.'], 422);
                }

                if (count($data['quotas']) !== (int) $data['installments']) {
                    return response()->json(['error' => 'A quantidade de quotas deve ser igual a installments.'], 422);
                }

                $quotasSum = round(array_sum(array_column($data['quotas'], 'value_quota')), 2);
                if (abs($quotasSum - $finalTotalValue) > 0.01) {
                    return response()->json(['error' => 'A soma das quotas deve ser igual a total_value.'], 422);
                }

                $newQuotas = $data['quotas'];
            } else {
                $data['installments'] = 1;
                $newQuotas = [[
                    'number' => 1,
                    'date_expected' => $data['date_payment'] ?? $expense->date_payment->toDateString(),
                    'value_quota' => $finalTotalValue,
                ]];
            }
        }

        $expense->update(Arr::except($data, ['payers', 'quotas']));

        if (array_key_exists('payers', $data)) {
            $expense->payers()->sync($data['payers']);
        }

        if ($changingType) {
            // Seguro: só alcançado depois de confirmar acima que nenhuma quota
            // está paga. ex_quotas não tem coluna de soft delete — são linhas
            // geradas a partir de expense_type/installments/total_value, não
            // uma entidade de negócio própria (Constitution §1.5 é sobre
            // grupo/despesa).
            $expense->quotas()->delete();

            foreach ($newQuotas as $quota) {
                $expense->quotas()->create([
                    'date_expected' => $quota['date_expected'],
                    'number' => $quota['number'],
                    'paid' => false,
                    'value_quota' => $quota['value_quota'],
                ]);
            }
        }

        $fresh = $expense->fresh(['payers', 'quotas']);
        $this->hydrateQuotaExpense($fresh);

        return response()->json($fresh);
    }

    public function destroy($id)
    {
        $expense = $this->findExpenseForMember($id);
        $this->authorizeExpenseOwner($expense);

        if ($response = $this->rejectIfCycleClosed($expense)) {
            return $response;
        }

        // Ao contrário de `update()`, aqui não há bypass de FIXED: excluir
        // apaga a definição inteira (todas as ocorrências, inclusive as já
        // pagas), não só o template pra frente — se qualquer ocorrência está
        // paga, é preciso desfazer o pagamento antes.
        if ($expense->quotas()->where('paid', true)->exists()) {
            return response()->json(['error' => 'Não é possível excluir uma despesa já paga. Desfaça o pagamento primeiro.'], 422);
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
        abort_if($group->deleted, 404);

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

        $snapshot = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', $start->toDateString())
            ->first();
        $sealed = $snapshot && $snapshot->isSealed();

        if ($sealed) {
            // Ciclo totalmente quitado → foto imutável.
            $status = 'closed';
            $summary = [
                'totals' => $snapshot->totals,
                'expenses' => $snapshot->expenses,
                'balances' => $snapshot->balances,
                'settlements' => $snapshot->settlements,
            ];
        } elseif ($status === 'closed' || ($status === 'open' && $snapshot && $snapshot->isManuallyClosedAndActive())) {
            // Ciclo fechado (por data ou manualmente) mas ainda não quitado:
            // recalcula AO VIVO para refletir pagamentos/confirmações feitos
            // depois do fechamento (feature 20260902-pagamento-ciclo-fechado).
            // Se com isso a competência ficou toda quitada, sela agora.
            if ($status === 'open') {
                $status = 'closed_manually';
            }

            $summary = $this->computeCycleSummary($group, $groupId, $start, $end);

            if ($this->sealCycleIfSettled($group, $groupId, $start, $end)) {
                $sealed = true;
                $status = 'closed';
            }
        } else {
            $summary = $this->computeCycleSummary($group, $groupId, $start, $end);
        }

        return response()->json([
            'cycle' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'closes_at' => BillingCycle::closesAt($end)->toDateString(),
                'status' => $status,
                'settled' => $sealed,
            ],
            'totals' => $summary['totals'],
            'expenses' => $summary['expenses'],
            'balances' => $summary['balances'],
            'settlements' => $this->attachSettlementConfirmations($groupId, $start, $summary['settlements']),
        ]);
    }

    /**
     * Diz em qual competência o app deve abrir o grupo: o ciclo **fechado (ou na
     * janela de carência) mais recente que ainda não está totalmente quitado**
     * (`cycleIsFullySettled`), varrendo até `FOCUS_LOOKBACK` ciclos para trás.
     * Se nenhum tem pendência, devolve `0` — o app abre na competência vigente
     * (comportamento pré-`20260902`). Ciclo `future` (ou `open` cuja fronteira
     * ainda não passou) não conta; ciclo selado é pulado. Um ciclo em carência
     * (`open` mas com `end` no passado) conta: a Home fica nele durante os dias
     * de carência e não pula quando ele vira `closed` em `closesAt`. Ver
     * docs/feature/20260902-pagamento-ciclo-fechado/plan.md §4 e §10.
     */
    public function focusCycle($groupId)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        for ($ago = 0; $ago <= self::FOCUS_LOOKBACK; $ago++) {
            $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $ago);

            $snapshot = GroupCycleSnapshot::where('group_id', $groupId)
                ->where('cycle_start', $cycle['start']->toDateString())
                ->first();

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

            if (! $this->cycleIsFullySettled($group, $groupId, $cycle['start'], $cycle['end'])) {
                return response()->json(['cycles_ago' => $ago]);
            }
        }

        return response()->json(['cycles_ago' => 0]);
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
        abort_if($group->deleted, 404);

        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now());
        $start = $cycle['start'];
        $end = $cycle['end'];

        $wasManuallyClosed = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', $start->toDateString())
            ->whereNotNull('closed_manually_at')
            ->exists();

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
                'settlements' => $summary['settlements'],
                'closed_manually_at' => Carbon::now(),
                'reopened_at' => null,
            ]
        );

        // Fechar um mês que já está todo pago e com todos os acertos
        // confirmados sela o ciclo direto (sem passar por `closed_manually`).
        $sealed = $this->sealCycleIfSettled($group, $groupId, $start, $end);

        if ($sealed) {
            $snapshot->refresh();
        }

        // Fechamento manual "de verdade" (não selou direto — isso já dispara
        // `cycle_settled` no `sealCycleIfSettled`) e não é um re-fechamento.
        if (! $sealed && ! $wasManuallyClosed) {
            Notifier::cycleClosed($group, $start, auth()->id(), auth()->user()->name);
        }

        return response()->json([
            'cycle' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'closes_at' => BillingCycle::closesAt($end)->toDateString(),
                'status' => $sealed ? 'closed' : 'closed_manually',
                'settled' => $sealed,
            ],
            'totals' => $snapshot->totals,
            'expenses' => $snapshot->expenses,
            'balances' => $snapshot->balances,
            'settlements' => $this->attachSettlementConfirmations($groupId, $start, $snapshot->settlements),
        ]);
    }

    /**
     * Reabertura de um fechamento manual ainda vigente. Só encontra algo pra
     * reabrir quando o fechamento manual pertence à competência que contém
     * "agora" — depois da virada do mês, a competência antiga simplesmente
     * não é mais a que este método consulta (sem precisar de uma checagem
     * extra de `BillingCycle`), então "reabrir competência antiga" já falha
     * por não achar snapshot nenhum aqui.
     */
    public function reopen($groupId)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);
        abort_if($group->deleted, 404);

        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now());
        $start = $cycle['start'];
        $end = $cycle['end'];

        $snapshot = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', $start->toDateString())
            ->first();

        if ($snapshot && $snapshot->isSealed()) {
            return response()->json(['error' => 'Esta competência já foi encerrada.'], 422);
        }

        if (! $snapshot || ! $snapshot->isManuallyClosedAndActive()) {
            return response()->json(['error' => 'Não há fechamento manual ativo para reabrir nesta competência.'], 422);
        }

        $snapshot->update(['reopened_at' => Carbon::now()]);

        $summary = $this->computeCycleSummary($group, $groupId, $start, $end);

        return response()->json([
            'cycle' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'closes_at' => BillingCycle::closesAt($end)->toDateString(), 'status' => 'open', 'settled' => false],
            'totals' => $summary['totals'],
            'expenses' => $summary['expenses'],
            'balances' => $summary['balances'],
            'settlements' => $this->attachSettlementConfirmations($groupId, $start, $summary['settlements']),
        ]);
    }

    /**
     * Histórico paginado de ciclos já fechados por data (anteriores à
     * competência vigente) — usado pela tela de Relatórios. Só lê
     * `GroupCycleSnapshot` já persistidos (nunca recalcula), no mesmo formato
     * que `summary()` devolve para a competência vigente, mas sempre com
     * `status: 'closed'`. A competência vigente nunca aparece aqui, mesmo
     * fechada manualmente (`close()`) — fechamento manual é reversível
     * (`reopen()`), então não é "histórico" imutável.
     */
    public function cycleHistory($groupId, Request $request)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        $currentStart = BillingCycle::cycleFor($group->closing_day, Carbon::now())['start'];

        // Só ciclo selado (totalmente quitado) é "histórico" imutável de
        // Relatórios; um ciclo fechado ainda com pendência fica na navegação
        // principal (`focus-cycle`), não aqui.
        $paginator = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', '<', $currentStart->toDateString())
            ->whereNotNull('settled_at')
            ->orderByDesc('cycle_start')
            ->paginate(10);

        $paginator->getCollection()->transform(fn (GroupCycleSnapshot $snapshot) => [
            'cycle' => [
                'start' => $snapshot->cycle_start->toDateString(),
                'end' => $snapshot->cycle_end->toDateString(),
                'status' => 'closed',
            ],
            'totals' => $snapshot->totals,
            'expenses' => $snapshot->expenses,
            'balances' => $snapshot->balances,
            'settlements' => $snapshot->settlements,
        ]);

        return response()->json($paginator);
    }

    /**
     * Árvore Credor→devedores da competência (valores brutos, um por par
     * credor/devedor, sem o netting que `computeCycleSummary`/`settlements`
     * fazem) — usada pela linha expansível do Dashboard. Reaproveita
     * `collectCycleEntries` (mesma fonte de `computeCycleSummary`), mas soma
     * só as participações ainda não pagas, agrupadas por par, sem sugerir
     * menor número de transferências.
     */
    public function grossDebts($groupId, Request $request)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        $data = $request->validate([
            'cycles_ago' => 'nullable|integer',
        ]);

        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $data['cycles_ago'] ?? 0);
        $start = $cycle['start'];
        $end = $cycle['end'];

        $sealed = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', $start->toDateString())
            ->whereNotNull('settled_at')
            ->exists();

        $entries = $this->collectCycleEntries($groupId, $start, $end);

        $creditors = [];
        foreach ($entries as $entry) {
            if ($entry['paid']) {
                continue;
            }

            $expense = $entry['expense'];
            $creditor = $expense->payer;
            $debtorsInExpense = $expense->payers->reject(fn ($debtor) => $debtor->id === $creditor->id);

            if ($debtorsInExpense->isEmpty()) {
                continue;
            }

            // Mesma fórmula de valuePerPerson em computeCycleSummary(): o valor
            // é dividido por TODOS os participantes (inclusive o credor, se ele
            // também participa da divisão) — só quem não é o credor entra na
            // lista de devedores, mas o valor de cada fatia não muda por isso.
            $participantsCount = max($expense->payers->count(), 1);
            $share = round($entry['value'] / $participantsCount, 2);

            if (! isset($creditors[$creditor->id])) {
                $creditors[$creditor->id] = [
                    'creditor' => ['id' => $creditor->id, 'name' => $creditor->name, 'email' => $creditor->email, 'pix' => $creditor->pix],
                    'debtors' => [],
                ];
            }

            foreach ($debtorsInExpense as $debtor) {
                if (! isset($creditors[$creditor->id]['debtors'][$debtor->id])) {
                    $creditors[$creditor->id]['debtors'][$debtor->id] = ['id' => $debtor->id, 'name' => $debtor->name, 'amount' => 0.0];
                }

                $creditors[$creditor->id]['debtors'][$debtor->id]['amount'] = round(
                    $creditors[$creditor->id]['debtors'][$debtor->id]['amount'] + $share,
                    2
                );
            }
        }

        $tree = collect($creditors)->values()->map(function (array $node) {
            $node['debtors'] = collect($node['debtors'])->values()->all();

            return $node;
        })->all();

        return response()->json([
            'cycle' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'closes_at' => BillingCycle::closesAt($end)->toDateString(),
                'status' => $cycle['status'],
                'settled' => $sealed,
            ],
            'creditors' => $tree,
        ]);
    }

    /**
     * Marca como paga a ocorrência desta despesa numa competência. Só o credor
     * (`user_payer_id`) pode confirmar o pagamento, e — desde a feature
     * `20260902-pagamento-ciclo-fechado` — pode fazê-lo a qualquer momento,
     * inclusive numa competência já fechada (por data ou manualmente). O
     * parâmetro opcional `cycles_ago` (>= 0, default 0) escolhe a competência
     * alvo; `cycles_ago >= 0` nunca resolve para uma competência futura, então
     * não há guard de "futuro" aqui. Despesa `FIXED` nasce sem Quota na
     * competência — materializa antes de marcar.
     *
     * Ao quitar a última pendência da competência, sela o ciclo
     * (`sealCycleIfSettled`): a partir daí ele vira histórico imutável.
     *
     * Comprovante (`comprovante`) é opcional no contrato da API: o fluxo
     * antigo de `ExpenseManager` chama este endpoint sem corpo e precisa
     * continuar funcionando; a Tela de Pagamentos é quem exige a foto,
     * do lado do cliente, antes de chamar isto.
     */
    public function pay($expenseId, Request $request)
    {
        $expense = $this->findExpenseForMember($expenseId);

        if (auth()->id() !== $expense->user_payer_id) {
            abort(403, 'Só o credor pode marcar esta despesa como paga.');
        }

        $data = $request->validate([
            'comprovante' => 'nullable|image|max:5120',
            'cycles_ago' => 'nullable|integer|min:0',
        ]);

        $group = $expense->group;
        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $data['cycles_ago'] ?? 0);

        $quota = $this->resolveQuotaForCycle($expense, $cycle['start'], $cycle['end']);

        if (! $quota) {
            return response()->json(['error' => 'Esta despesa não tem ocorrência nessa competência.'], 422);
        }

        $update = [
            'paid' => true,
            'paid_at' => Carbon::now(),
            'paid_by' => auth()->id(),
        ];

        if (! empty($data['comprovante'])) {
            $update['payment_proof_path'] = ProofStorage::store($request->file('comprovante'), $expense->group_id);
        }

        $wasPaid = (bool) $quota->paid;

        $quota->update($update);

        $this->sealCycleIfSettled($group, $group->id, $cycle['start'], $cycle['end']);

        // Só na transição não-paga → paga: re-`pay()` do mesmo quota (ex.:
        // trocar o comprovante) não gera notificação nova.
        if (! $wasPaid) {
            Notifier::expensePaid($expense, $quota);
        }

        // Comprovante anexado pelo credor → avisa os pagadores por WhatsApp,
        // depois da resposta (não segura o request, não quebra o pagamento se
        // a Meta estiver fora). No-op se a feature está desligada.
        if (array_key_exists('payment_proof_path', $update)) {
            $quotaId = $quota->id;

            dispatch(function () use ($quotaId) {
                $quota = Quota::with('expense.payers', 'expense.payer')->find($quotaId);

                if ($quota) {
                    WhatsAppNotifier::expenseProofPaid($quota);
                }
            })->afterResponse();
        }

        return response()->json($quota->fresh()->load('expense'));
    }

    /**
     * Desfaz o pagamento da ocorrência desta despesa numa competência. Mesmas
     * regras de `pay`: só o credor, a qualquer momento (inclusive competência
     * fechada), com `cycles_ago` (>= 0, default 0) escolhendo o alvo. Ao
     * contrário de `pay`, não materializa `Quota` de `FIXED` sob demanda —
     * uma ocorrência ainda virtual nunca está paga (`collectCycleEntries`
     * projeta `paid: false` até existir uma Quota real), então não há o que
     * desfazer nesse caso.
     *
     * Se o `unpay` quebra a quitação total de um ciclo já selado, dessela
     * (`unsealIfBroken`): o ciclo volta a ser recalculado ao vivo.
     *
     * Se a quota tinha comprovante, apaga o arquivo do disco — um pagamento
     * desfeito não deve manter "prova" de um estado que não vale mais.
     */
    public function unpay($expenseId, Request $request)
    {
        $expense = $this->findExpenseForMember($expenseId);

        if (auth()->id() !== $expense->user_payer_id) {
            abort(403, 'Só o credor pode desfazer o pagamento desta despesa.');
        }

        $data = $request->validate([
            'cycles_ago' => 'nullable|integer|min:0',
        ]);

        $group = $expense->group;
        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $data['cycles_ago'] ?? 0);

        $quota = $expense->quotas()
            ->whereBetween('date_expected', [$cycle['start']->toDateString(), $cycle['end']->toDateString()])
            ->first();

        if (! $quota || ! $quota->paid) {
            return response()->json(['error' => 'Esta despesa não está paga nessa competência.'], 422);
        }

        ProofStorage::delete($quota->payment_proof_path);

        $quota->update([
            'paid' => false,
            'paid_at' => null,
            'paid_by' => null,
            'payment_proof_path' => null,
        ]);

        $this->unsealIfBroken($group, $group->id, $cycle['start'], $cycle['end']);

        return response()->json($quota->fresh());
    }

    /**
     * Confirma que o usuário autenticado (devedor) pagou via Pix o valor
     * líquido que deve a `to_user_id` num ciclo — conceito distinto de
     * `pay()`/`unpay()`, que confirmam uma despesa específica do lado do
     * credor. O comprovante aqui é obrigatório (é o conteúdo da ação, não
     * um anexo opcional como em `pay()`).
     *
     * Desde a feature `20260902-pagamento-ciclo-fechado`, o acerto do devedor
     * só é aceito quando a competência-alvo está **fechada** (`closed` por data
     * ou fechamento manual ativo) — enquanto aberta, os valores ainda podem
     * mudar. `cycles_ago` (>= 0, default 0) escolhe a competência. Só aceita se
     * existir de fato um settlement `from_user_id === auth()->id()` pra esse
     * `to_user_id` naquela competência. Reenviar substitui o comprovante
     * anterior (`updateOrCreate` — sem endpoint de "desfazer", ver
     * docs/feature/20260825-pagamentos-grid-pix/specify.md §4). Ao confirmar o
     * último acerto pendente de um ciclo já todo pago, sela o ciclo.
     */
    public function confirmSettlement(Request $request, $groupId)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        $data = $request->validate([
            'to_user_id' => 'required|integer|exists:ex_users,id',
            'comprovante' => 'required|image|max:5120',
            'cycles_ago' => 'nullable|integer|min:0',
        ]);

        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $data['cycles_ago'] ?? 0);

        $snapshot = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', $cycle['start']->toDateString())
            ->first();

        $cycleIsClosed = $cycle['status'] === 'closed'
            || ($cycle['status'] === 'open' && $snapshot && $snapshot->isManuallyClosedAndActive());

        if (! $cycleIsClosed) {
            return response()->json(['error' => 'O acerto só pode ser confirmado depois que a competência é fechada.'], 422);
        }

        if ($snapshot && $snapshot->isSealed()) {
            return response()->json(['error' => 'Esta competência já foi encerrada.'], 422);
        }

        $summary = $this->computeCycleSummary($group, $groupId, $cycle['start'], $cycle['end']);

        $settlement = collect($summary['settlements'])->first(
            fn ($s) => $s['from_user_id'] === auth()->id() && $s['to_user_id'] === (int) $data['to_user_id']
        );

        if (! $settlement) {
            return response()->json(['error' => 'Não há valor a pagar para esse credor nesta competência.'], 422);
        }

        $key = [
            'group_id' => $groupId,
            'cycle_start' => $cycle['start']->toDateString(),
            'from_user_id' => auth()->id(),
            'to_user_id' => $data['to_user_id'],
        ];

        $previousProofPath = SettlementConfirmation::where($key)->value('proof_path');

        $path = ProofStorage::store($request->file('comprovante'), (int) $groupId);

        $confirmation = SettlementConfirmation::updateOrCreate($key, [
            'cycle_end' => $cycle['end']->toDateString(),
            'amount' => $settlement['amount'],
            'proof_path' => $path,
            'confirmed_at' => Carbon::now(),
        ]);

        if ($previousProofPath && $previousProofPath !== $path) {
            ProofStorage::delete($previousProofPath);
        }

        $this->sealCycleIfSettled($group, $groupId, $cycle['start'], $cycle['end']);

        // Só na 1ª confirmação desse par no ciclo — reenviar o comprovante
        // (`updateOrCreate` que atualiza) não gera notificação nova.
        if ($confirmation->wasRecentlyCreated) {
            Notifier::settlementConfirmed(
                $group,
                (int) $data['to_user_id'],
                auth()->user()->name,
                $settlement['amount'],
                $cycle['start'],
            );
        }

        // Devedor confirmou o acerto com comprovante → avisa o credor por
        // WhatsApp, depois da resposta. No-op se a feature está desligada.
        $confirmationId = $confirmation->id;

        dispatch(function () use ($confirmationId) {
            $confirmation = SettlementConfirmation::with('fromUser', 'toUser')->find($confirmationId);

            if ($confirmation) {
                WhatsAppNotifier::settlementProofConfirmed($confirmation);
            }
        })->afterResponse();

        return response()->json($confirmation->fresh());
    }

    /**
     * Calcula totais, lista de despesas, saldos por pessoa e liquidação
     * par-a-par (quem paga a quem) de um ciclo [start, end], sempre ao vivo
     * a partir de `Expense`/`Quota`.
     *
     * @return array{totals: array, expenses: array, balances: array, settlements: array}
     */
    private function computeCycleSummary(Group $group, $groupId, Carbon $start, Carbon $end): array
    {
        $entries = $this->collectCycleEntries($groupId, $start, $end);

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
                    'paymentProofUrl' => $entry['paymentProofUrl'],
                    'payerName' => $entry['expense']->payer->name ?? null,
                    'participants' => $entry['expense']->payers->pluck('name')->values()->all(),
                    'isFixed' => $entry['expense']->expense_type === 'FIXED',
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
            $balances[$member->id] = ['user_id' => $member->id, 'name' => $member->name, 'balance' => 0.0];
        }

        // $owed[$credorId][$devedorId] = quanto o devedor deve ao credor,
        // somado em bruto por despesa (antes de netar par a par). Base para
        // $settlements abaixo; $balances continua vindo do mesmo loop, só
        // agregando direto por pessoa em vez de por par.
        $owed = [];

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
     * produzem o array (`computeCycleSummary` ao vivo, snapshot manual,
     * snapshot fechado por data) já rodou — não precisa decorar dentro de
     * cada um separadamente.
     */
    private function attachSettlementConfirmations($groupId, Carbon $start, array $settlements): array
    {
        $confirmations = SettlementConfirmation::where('group_id', $groupId)
            ->where('cycle_start', $start->toDateString())
            ->get()
            ->keyBy(fn ($c) => "{$c->from_user_id}-{$c->to_user_id}");

        return collect($settlements)
            ->map(function (array $settlement) use ($confirmations) {
                $confirmation = $confirmations->get("{$settlement['from_user_id']}-{$settlement['to_user_id']}");

                $settlement['confirmedProofUrl'] = $confirmation?->proof_url;
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
     * como quitado. Base da selagem (`sealCycleIfSettled`) e do endpoint
     * focus-cycle — ver docs/feature/20260902-pagamento-ciclo-fechado/plan.md §0.3.
     */
    private function cycleIsFullySettled(Group $group, $groupId, Carbon $start, Carbon $end): bool
    {
        $summary = $this->computeCycleSummary($group, $groupId, $start, $end);

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
     * Se o ciclo [start, end] está totalmente quitado, congela a foto num
     * `GroupCycleSnapshot` com `settled_at` — a partir daí `summary()` serve
     * essa cópia e o ciclo vira histórico. `updateOrCreate` não passa
     * `closed_manually_at`/`reopened_at`, então preserva o registro de
     * fechamento manual, se houver. Devolve `true` se selou, `false` se ainda
     * há pendência.
     */
    private function sealCycleIfSettled(Group $group, $groupId, Carbon $start, Carbon $end): bool
    {
        if (! $this->cycleIsFullySettled($group, $groupId, $start, $end)) {
            return false;
        }

        $summary = $this->computeCycleSummary($group, $groupId, $start, $end);

        // Ciclo sem nenhuma despesa nem acerto não vira "histórico selado": não
        // há foto a congelar e um `settled_at` aqui só travaria o `reopen()` à
        // toa. `cycleIsFullySettled` ainda devolve `true` (nada pendente) para
        // o `focus-cycle` pular esse ciclo.
        if (empty($summary['expenses']) && empty($summary['settlements'])) {
            return false;
        }

        $wasSealed = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', $start->toDateString())
            ->first()?->isSealed() ?? false;

        GroupCycleSnapshot::updateOrCreate(
            ['group_id' => $groupId, 'cycle_start' => $start->toDateString()],
            [
                'cycle_end' => $end->toDateString(),
                'totals' => $summary['totals'],
                'expenses' => $summary['expenses'],
                'balances' => $summary['balances'],
                'settlements' => $summary['settlements'],
                'settled_at' => Carbon::now(),
            ]
        );

        // Só na transição não-selado → selado: chamadas repetidas de
        // `sealCycleIfSettled` num ciclo já selado (inclusive as preguiçosas
        // de `summary()`) não geram notificação nova.
        if (! $wasSealed) {
            Notifier::cycleSettled($group, $start);
        }

        return true;
    }

    /**
     * Se o ciclo [start, end] tinha um snapshot selado mas deixou de estar
     * totalmente quitado (ex.: o credor desfez um pagamento), limpa
     * `settled_at` — o ciclo volta a ser recalculado ao vivo em `summary()` e
     * reentra na rotação do focus-cycle. No-op se não há snapshot, se não está
     * selado, ou se continua quitado.
     */
    private function unsealIfBroken(Group $group, $groupId, Carbon $start, Carbon $end): void
    {
        $snapshot = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', $start->toDateString())
            ->first();

        if ($snapshot && $snapshot->isSealed() && ! $this->cycleIsFullySettled($group, $groupId, $start, $end)) {
            $snapshot->update(['settled_at' => null]);
        }
    }

    /**
     * Despesas cujo valor conta para um ciclo [start, end]: diretas (date_payment
     * dentro do intervalo) + Fixa projetada mês a mês dentro do intervalo (mesma
     * regra de corte de recorrência de indexByGroup, adaptada de mês único para
     * intervalo de datas, que pode atravessar dois meses calendário).
     *
     * @return \Illuminate\Support\Collection<int, array{expense: Expense, date: Carbon, value: float, paid: bool, paymentProofUrl: ?string}>
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
            $quota?->setRelation('expense', $expense);

            $entries->push([
                'expense' => $expense,
                'date' => $expense->date_payment->copy(),
                'value' => (float) ($quota->value_quota ?? $expense->total_value),
                'paid' => (bool) ($quota->paid ?? false),
                'paymentProofUrl' => $quota->payment_proof_url ?? null,
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
                'paymentProofUrl' => $quota->payment_proof_url,
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
                        'paymentProofUrl' => $quota->payment_proof_url ?? null,
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

    /**
     * Quota que representa esta despesa na competência [start, end] — para
     * `FIXED`, materializa a ocorrência se ainda não existir; para as demais,
     * a Quota já existe desde a criação (uma por competência, por design).
     * `null` se a despesa não tiver ocorrência nessa competência (ex.: FIXED
     * cuja recorrência já foi cortada antes dela). A competência pode ser
     * passada (`cycles_ago > 0` em `pay()`), não só a vigente.
     */
    private function resolveQuotaForCycle(Expense $expense, Carbon $start, Carbon $end): ?Quota
    {
        if ($expense->expense_type === 'FIXED') {
            $entry = $this->collectCycleEntries($expense->group_id, $start, $end)
                ->first(fn (array $entry) => $entry['expense']->id === $expense->id);

            return $entry ? $this->materializeFixedOccurrenceQuota($expense, $entry['date']) : null;
        }

        return $expense->quotas()
            ->whereBetween('date_expected', [$start->toDateString(), $end->toDateString()])
            ->first();
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
