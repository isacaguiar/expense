<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupCycleSnapshot extends Model
{
    protected $table = 'ex_group_cycle_snapshots';

    protected $fillable = [
        'group_id',
        'cycle_start',
        'cycle_end',
        'totals',
        'expenses',
        'balances',
        'settlements',
        'closed_manually_at',
        'reopened_at',
        'settled_at',
    ];

    protected $casts = [
        'cycle_start' => 'date',
        'cycle_end' => 'date',
        'totals' => 'array',
        'expenses' => 'array',
        'balances' => 'array',
        'settlements' => 'array',
        'closed_manually_at' => 'datetime',
        'reopened_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    /**
     * Fechamento manual ainda vigente: existe `closed_manually_at` e nenhuma
     * reabertura mais recente. Não diz nada sobre o fechamento automático por
     * data (`BillingCycle`) — os dois estados são independentes.
     */
    public function isManuallyClosedAndActive(): bool
    {
        if ($this->closed_manually_at === null) {
            return false;
        }

        return $this->reopened_at === null || $this->reopened_at->lt($this->closed_manually_at);
    }

    /**
     * Ciclo "selado": totalmente quitado — toda conta paga e todo acerto
     * confirmado (`ExpenseController::cycleIsFullySettled`) — e por isso
     * fotografado de forma imutável. A partir daí `summary()` serve esta
     * cópia e o ciclo vira histórico. Um `unpay` que quebre a quitação
     * limpa `settled_at` e o ciclo volta a ser recalculado ao vivo.
     */
    public function isSealed(): bool
    {
        return $this->settled_at !== null;
    }
}
