<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * Confirmação de pagamento de um settlement (liquidação par-a-par de um
 * ciclo) pelo DEVEDOR — conceito distinto de `Quota.paid` (confirmado pelo
 * credor de uma despesa específica). Ver
 * docs/feature/concluidas/202608/20260825-pagamentos-grid-pix/specify.md §2.7.
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

    /** Devedor que confirmou o pagamento (origem do settlement). */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /** Credor que recebe o pagamento (destino do settlement). */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /**
     * URL assinada de curta duração para baixar o comprovante pela rota
     * `proofs.show` (fora do `jwt.auth` — a aba do browser não manda Bearer).
     * Ver `docs/sdd/decisions/ADR-005-download-arquivo-signed-url.md`.
     */
    public function getProofUrlAttribute(): ?string
    {
        if (! $this->proof_path) {
            return null;
        }

        return URL::temporarySignedRoute('proofs.show', now()->addMinutes(30), [
            'groupId' => $this->group_id,
            'type' => 'settlement',
            'id' => $this->id,
        ]);
    }
}
