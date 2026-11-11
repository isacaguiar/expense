<?php

namespace App\Services;

use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Support\Notifier;
use Carbon\Carbon;

/**
 * Lado de **escrita** do selo do ciclo: congela a competência num `GroupCycleSnapshot` quando ela está
 * totalmente quitada e avisa o grupo na transição. Saiu de `ExpenseController::sealCycleIfSettled`
 * **sem mudar regra** (feature 20261110-quem-paga-se-declara-e-credor-recusa, plan §2), porque a
 * rota de aceite do comprovante também sela, e o controller, de 2000 linhas, não é lugar para isso.
 *
 * Fica fora de `CycleSummaryService` de propósito: aquele é o lado de **leitura** do cálculo de ciclo
 * ("nada aqui grava"), usado também pelo Painel Financeiro Pessoal; este grava e notifica.
 */
class CycleSealingService
{
    public function __construct(private CycleSummaryService $cycles) {}

    /**
     * Se o ciclo [start, end] está totalmente quitado, congela a foto num
     * `GroupCycleSnapshot` com `settled_at` — a partir daí `summary()` serve
     * essa cópia e o ciclo vira histórico. `updateOrCreate` não passa
     * `closed_manually_at`/`reopened_at`, então preserva o registro de
     * fechamento manual, se houver. Devolve `true` se selou, `false` se ainda
     * há pendência.
     */
    public function sealIfSettled(Group $group, Carbon $start, Carbon $end): bool
    {
        if (! $this->cycles->isFullySettled($group, $start, $end)) {
            return false;
        }

        $summary = $this->cycles->compute($group, $start, $end);

        // Ciclo sem despesa REAL nem acerto não vira "histórico selado": não há
        // foto a congelar e um `settled_at` aqui só travaria o `reopen()` à toa.
        // "Real" exclui entry `bornPaid` (parcela retroativa nascida quitada,
        // specify.md §2.3 de 20260904-parcela-retroativa-contabilizacao) — ela
        // fica em `expenses` como linha "Paga" (histórico), mas sozinha não é
        // motivo pra selar o mês nem disparar Notifier::cycleSettled.
        // `isFullySettled` já devolve `true` pra esse caso (nada pendente),
        // então o `focus-cycle` pula o ciclo mesmo sem selar.
        $hasRealExpense = collect($summary['expenses'])->contains(fn ($expense) => empty($expense['bornPaid']));

        if (! $hasRealExpense && empty($summary['settlements'])) {
            return false;
        }

        $wasSealed = GroupCycleSnapshot::where('group_id', $group->id)
            ->where('cycle_start', $start->toDateString())
            ->first()?->isSealed() ?? false;

        GroupCycleSnapshot::updateOrCreate(
            ['group_id' => $group->id, 'cycle_start' => $start->toDateString()],
            [
                'cycle_end' => $end->toDateString(),
                'totals' => $summary['totals'],
                'expenses' => $summary['expenses'],
                'balances' => $summary['balances'],
                'settlements' => $summary['settlements'],
                'settled_at' => Carbon::now(),
            ]
        );

        // Só na transição não-selado → selado: chamadas repetidas num ciclo já
        // selado (inclusive as preguiçosas de `summary()`) não geram notificação nova.
        if (! $wasSealed) {
            Notifier::cycleSettled($group, $start);
        }

        return true;
    }
}
