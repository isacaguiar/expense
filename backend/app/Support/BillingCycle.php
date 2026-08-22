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
     * Ciclo que contém `$reference` (`cyclesAgo=0`), deslocado `cyclesAgo`
     * ciclos para trás (positivo) ou para a frente (negativo, ciclo futuro).
     *
     * @return array{start: Carbon, end: Carbon, status: string}
     */
    public static function cycleFor(?int $closingDay, Carbon $reference, int $cyclesAgo = 0): array
    {
        $referenceStart = $reference->copy()->startOfDay();
        [$start, $end] = self::boundariesFor($closingDay, $referenceStart, $cyclesAgo);

        return [
            'start' => $start,
            'end' => $end,
            'status' => self::statusOf($start, $end, $referenceStart),
        ];
    }

    /**
     * Status (`closed`/`open`/`future`) do ciclo que contém `$date`, em
     * relação a `$reference` (normalmente "agora") — usado para saber se a
     * data de uma despesa cai num ciclo já fechado, sem precisar calcular
     * `cyclesAgo`.
     */
    public static function statusFor(?int $closingDay, Carbon $date, Carbon $reference): string
    {
        [$start, $end] = self::boundariesFor($closingDay, $date->copy()->startOfDay(), 0);

        return self::statusOf($start, $end, $reference->copy()->startOfDay());
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function boundariesFor(?int $closingDay, Carbon $referenceStart, int $cyclesAgo): array
    {
        $anchorMonth = $referenceStart->copy()->startOfMonth();
        $currentBoundary = self::boundaryFor($anchorMonth, $closingDay);

        $containingMonth = $referenceStart->gt($currentBoundary)
            ? $anchorMonth->copy()->addMonth()
            : $anchorMonth;

        $targetMonth = $containingMonth->copy()->subMonths($cyclesAgo);

        $end = self::boundaryFor($targetMonth, $closingDay);
        $previousBoundary = self::boundaryFor($targetMonth->copy()->subMonth(), $closingDay);
        $start = $previousBoundary->copy()->addDay();

        return [$start, $end];
    }

    private static function statusOf(Carbon $start, Carbon $end, Carbon $referenceStart): string
    {
        return match (true) {
            $end->lt($referenceStart) => 'closed',
            $start->gt($referenceStart) => 'future',
            default => 'open',
        };
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
