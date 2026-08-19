<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Calcula o ciclo de fechamento de um grupo (estilo fatura de cartão de
 * crédito): um ciclo fecha num dia fixo do mês (`closingDay`) e só é
 * considerado fechado a partir do dia seguinte à sua data de fechamento.
 * `closingDay` nulo reproduz o mês calendário (fecha no último dia do mês).
 */
class BillingCycle
{
    /**
     * @return array{start: Carbon, end: Carbon}
     */
    public static function closedCycle(?int $closingDay, Carbon $reference, int $cyclesAgo = 0): array
    {
        $referenceStart = $reference->copy()->startOfDay();
        $anchorMonth = $referenceStart->copy()->startOfMonth();
        $currentBoundary = self::boundaryFor($anchorMonth, $closingDay);

        $mostRecentClosedMonth = $referenceStart->gt($currentBoundary)
            ? $anchorMonth
            : $anchorMonth->copy()->subMonth();

        $targetMonth = $mostRecentClosedMonth->copy()->subMonths($cyclesAgo);

        $end = self::boundaryFor($targetMonth, $closingDay);
        $previousBoundary = self::boundaryFor($targetMonth->copy()->subMonth(), $closingDay);
        $start = $previousBoundary->copy()->addDay();

        return ['start' => $start, 'end' => $end];
    }

    private static function boundaryFor(Carbon $anchorMonth, ?int $closingDay): Carbon
    {
        $month = $anchorMonth->copy()->startOfMonth();

        if ($closingDay === null) {
            return $month->copy()->endOfMonth()->startOfDay();
        }

        $day = min($closingDay, $month->daysInMonth);

        return $month->copy()->day($day)->startOfDay();
    }
}
