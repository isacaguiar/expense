<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Confirmação de pagamento de um settlement (liquidação par-a-par de um
 * ciclo) pelo DEVEDOR — conceito distinto de `Quota.paid` (confirmado pelo
 * credor de uma despesa específica). Ver
 * docs/feature/20260825-pagamentos-grid-pix/specify.md §2.7.
 */
class SettlementConfirmation extends Model
{
    protected $table = 'ex_settlement_confirmations';

    protected $fillable = [
        'group_id',
        'cycle_start',
        'cycle_end',
        'from_user_id',
        'to_user_id',
        'amount',
        'proof_path',
        'confirmed_at',
    ];

    protected $appends = ['proof_url'];

    protected $casts = [
        'cycle_start' => 'date',
        'cycle_end' => 'date',
        'amount' => 'float',
        'confirmed_at' => 'datetime',
    ];

    public function getProofUrlAttribute(): ?string
    {
        if (! $this->proof_path) {
            return null;
        }

        return Storage::disk('public')->url($this->proof_path);
    }
}
