<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Group;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
        $direct = Expense::where('group_id', $groupId)
            ->where('deleted', false)
            ->whereYear('date_payment', $data['year'])
            ->whereMonth('date_payment', $data['month'])
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

        $expenses = $direct->map(fn (Expense $expense) => $mapRow($expense))
            ->concat($projectedFixed->map(function (Expense $expense) use ($monthStart, $mapRow) {
                $day = min($expense->date_payment->day, $monthStart->daysInMonth);

                return $mapRow($expense, $monthStart->copy()->day($day));
            }))
            ->sortBy('date')
            ->values();

        return response()->json($expenses);
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
            'quotas.*.paid' => 'required|boolean',
            'quotas.*.value_quota' => 'required|numeric|min:0',
        ]);

        if ($request->expense_type === 'FIXED') {
            if ((int) $request->installments !== 1) {
                return response()->json(['error' => 'Despesa fixa deve ter installments=1.'], 422);
            }

            if (count($request->quotas) !== 1) {
                return response()->json(['error' => 'Despesa fixa deve ter exatamente 1 quota.'], 422);
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
                $expense->quotas()->create([
                    'date_expected' => $quotaData['date_expected'],
                    'number' => $quotaData['number'],
                    'paid' => $quotaData['paid'],
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
}
