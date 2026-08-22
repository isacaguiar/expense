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
        'closed_manually_at',
        'reopened_at',
    ];

    protected $casts = [
        'cycle_start' => 'date',
        'cycle_end' => 'date',
        'totals' => 'array',
        'expenses' => 'array',
        'balances' => 'array',
        'closed_manually_at' => 'datetime',
        'reopened_at' => 'datetime',
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
}
