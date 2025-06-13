<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Expense;
use App\Models\Quota;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ExpenseController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'date_payment'     => 'required|date',
            'description'      => 'required|string|max:255',
            'expense_type'     => 'required|in:IN_CASH,IN_INSTALLMENTS',
            'installments'     => 'required|integer|min:1',
            'total_value'      => 'required|numeric|min:0',
            'group_id'         => 'required|exists:ex_groups,id',
            'user_creator_id'  => 'required|exists:ex_users,id',
            'user_payer_id'    => 'required|exists:ex_users,id',
            'payers'           => 'required|array|min:1',
            'payers.*'         => 'exists:ex_users,id',
            'quotas'           => 'required|array|min:1',
            'quotas.*.date_expected' => 'required|date',
            'quotas.*.number'        => 'required|integer',
            'quotas.*.paid'          => 'required|boolean',
            'quotas.*.value_quota'   => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $expense = Expense::create([
                'create_date'      => now(),
                'date_payment'     => $request->date_payment,
                'description'      => $request->description,
                'expense_type'     => $request->expense_type,
                'installments'     => $request->installments,
                'total_value'      => $request->total_value,
                'group_id'         => $request->group_id,
                'user_creator_id'  => $request->user_creator_id,
                'user_payer_id'    => $request->user_payer_id,
                'deleted'          => false,
            ]);

            // Pagadores
            $expense->payers()->syncWithoutDetaching($request->payers);

            // Quotas
            foreach ($request->quotas as $quotaData) {
                $expense->quotas()->create([
                    'date_expected' => $quotaData['date_expected'],
                    'number'        => $quotaData['number'],
                    'paid'          => $quotaData['paid'],
                    'value_quota'   => $quotaData['value_quota'],
                ]);
            }

            DB::commit();

            return response()->json(['message' => 'Despesa criada com sucesso', 'expense_id' => $expense->id], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Erro ao criar despesa', 'details' => $e->getMessage()], 500);
        }
    }

    public function getMonthlyExpenses($groupId)
    {
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
