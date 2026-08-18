<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class GroupExpenseReportController extends Controller
{
    public function reportByGroupAndYear($groupId, $year)
    {
        Log::info("Iniciando relatório para o grupo {$groupId} no ano {$year}");

        $group = Group::find($groupId);
        if (!$group) {
            Log::warning("Grupo {$groupId} não encontrado.");
            return response()->json(['message' => 'Grupo não encontrado'], 404);
        }

        $expenses = Expense::with(['payer', 'payers'])
            ->where('group_id', $groupId)
            ->get();

        Log::info("Despesas encontradas: " . $expenses->count());

        if ($expenses->isEmpty()) {
            return response()->json(['message' => 'Nenhuma despesa encontrada para este ano'], 404);
        }

        $monthlyReport = [];
        $annualReceivedSummary = [];

        foreach ($expenses as $expense) {
            Log::info("----------------------------------");
            $startDate = Carbon::parse($expense->date_payment)->addMonth(); // começa a contar no mês seguinte
            $installments = ($expense->expense_type === 'IN_INSTALLMENTS') ? max((int) $expense->installments, 1) : 1;
            $installmentValue = $expense->total_value / $installments;

            Log::info("Valor: {$installmentValue} total pago: {$expense->total_value} parcelas: {$installments}");

            for ($i = 0; $i < $installments; $i++) {
                $date = $startDate->copy()->addMonths($i);
                $yearKey = $date->format('Y');

                if ($yearKey != $year) continue;

                $month = $date->format('m');
                $payerName = $expense->payer->name ?? 'Desconhecido';
                $participantNames = $expense->payers->pluck('name')->toArray();
                $participantsCount = count($participantNames);
                $valuePerPerson = $installmentValue / max($participantsCount, 1);

                // 1. Lista de despesas
                $monthlyReport[$month]['expenses'][] = [
                    'description' => $expense->description . ($installments > 1 ? " (Parcela " . ($i + 1) . "/$installments)" : ''),
                    'value' => round($installmentValue, 2),
                    'payer' => $payerName,
                    'type' => $expense->expense_type,
                    'installments' => $installments,
                    'participants' => $participantNames,
                    'participants_count' => $participantsCount
                ];

                // 2. receivedSummary (exclui o pagador)
                foreach ($participantNames as $participant) {
                    if ($participant === $payerName) continue;

                    $monthlyReport[$month]['receivedSummary'][$payerName][$participant] =
                        ($monthlyReport[$month]['receivedSummary'][$payerName][$participant] ?? 0) + $valuePerPerson;

                    // acumula para finalSettlement
                    $annualReceivedSummary[$payerName][$participant] =
                        ($annualReceivedSummary[$payerName][$participant] ?? 0) + $valuePerPerson;
                }
            }
        }

        // 3. Cálculo Final Anual
        $finalSettlement = [];

        /*foreach ($annualReceivedSummary as $receiver => $payerList) {
            foreach ($payerList as $payer => $amount) {
                if ($payer === $receiver) continue;

                $reverse = $annualReceivedSummary[$payer][$receiver] ?? 0;
                $netAmount = round($amount - $reverse, 2);

                if ($netAmount > 0) {
                    $finalSettlement[$receiver][$payer] = $netAmount;
                }
            }
        }*/

        return response()->json([
            $year => $monthlyReport,
            //'finalSettlement' => $finalSettlement
        ]);
    }

    public function reportByGroupAndYearMonthlySettlement($groupId, $year)
    {
        $group = Group::find($groupId);
        if (!$group) {
            return response()->json(['message' => 'Grupo não encontrado'], 404);
        }

        $expenses = Expense::with(['payer', 'payers'])
            ->where('group_id', $groupId)
            ->get();

        if ($expenses->isEmpty()) {
            return response()->json(['message' => 'Nenhuma despesa encontrada para este ano'], 404);
        }

        $monthlyReport = [];

        foreach ($expenses as $expense) {
            $startDate = Carbon::parse($expense->date_payment)->addMonth(); // inicia mês seguinte
            $installments = ($expense->expense_type === 'IN_INSTALLMENTS') ? max((int) $expense->installments, 1) : 1;
            $installmentValue = $expense->total_value / $installments;

            for ($i = 0; $i < $installments; $i++) {
                $date = $startDate->copy()->addMonths($i);
                $yearKey = $date->format('Y');

                if ($yearKey != $year) continue;

                $month = $date->format('m');
                $payerName = $expense->payer->name ?? 'Desconhecido';
                $participantNames = $expense->payers->pluck('name')->toArray();
                $participantsCount = count($participantNames);
                $valuePerPerson = $installmentValue / max($participantsCount, 1);

                // 1. Lista de despesas
                $monthlyReport[$month]['expenses'][] = [
                    'description' => $expense->description . ($installments > 1 ? " (Parcela " . ($i + 1) . "/$installments)" : ''),
                    'value' => round($installmentValue, 2),
                    'payer' => $payerName,
                    'type' => $expense->expense_type,
                    'installments' => $installments,
                    'participants' => $participantNames,
                    'participants_count' => $participantsCount
                ];

                // 2. receivedSummary
                foreach ($participantNames as $participant) {
                    if ($participant === $payerName) continue;

                    $monthlyReport[$month]['receivedSummary'][$payerName][$participant] =
                        ($monthlyReport[$month]['receivedSummary'][$payerName][$participant] ?? 0) + $valuePerPerson;
                }
            }
        }

        // 3. finalSettlement MENSAL
        foreach ($monthlyReport as $month => $data) {
            $settlement = [];
            $receivedSummary = $data['receivedSummary'] ?? [];

            foreach ($receivedSummary as $receiver => $payerList) {
                foreach ($payerList as $payer => $amount) {
                    if ($payer === $receiver) continue;

                    $reverse = $receivedSummary[$payer][$receiver] ?? 0;
                    $netAmount = round($amount - $reverse, 2);

                    if ($netAmount > 0) {
                        $settlement[$receiver][$payer] = $netAmount;
                    }
                }
            }

            $monthlyReport[$month]['finalSettlement'] = $settlement;
        }

        return response()->json([
            $year => $monthlyReport
        ]);
    }

}
