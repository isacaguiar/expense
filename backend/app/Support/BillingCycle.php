<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Calcula o ciclo de fechamento de um grupo (estilo fatura de cartão de
 * crédito): um ciclo fecha numa fronteira fixa do mês (`closingDay`) e só é
 * considerado `closed` a partir de `GRACE_DAYS` dias depois dela — antes
 * disso fica numa janela de carência em que ainda está `open` (editável e
 * pagável). `closingDay` nulo reproduz o mês calendário (fronteira no
 * último dia do mês).
 */
class BillingCycle
{
    /**
     * Dias de carência após a fronteira do ciclo antes de ele ser
     * considerado `closed`. Um ciclo cuja fronteira já passou continua
     * `open` por `GRACE_DAYS` dias; no `GRACE_DAYS`-ésimo dia depois da
     * fronteira ele vira `closed` (é a "data de corte", `closesAt()`).
     */
    public const GRACE_DAYS = 5;

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

    /**
     * Data de corte definitivo de um ciclo cuja fronteira é `$boundary`:
     * `$boundary + GRACE_DAYS` dias. É o primeiro dia em que o ciclo está
     * `closed` — antes dele, mesmo passada a fronteira, o ciclo está na
     * janela de carência e ainda conta como `open`.
     */
    public static function closesAt(Carbon $boundary): Carbon
    {
        return $boundary->copy()->startOfDay()->addDays(self::GRACE_DAYS);
    }

    private static function statusOf(Carbon $start, Carbon $end, Carbon $referenceStart): string
    {
        return match (true) {
            $referenceStart->gte(self::closesAt($end)) => 'closed',
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
