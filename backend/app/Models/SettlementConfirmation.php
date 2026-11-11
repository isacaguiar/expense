<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * Confirmação de pagamento de um settlement (liquidação par-a-par de um
 * ciclo) pelo DEVEDOR — conceito distinto de `Quota.paid` (confirmado pelo
 * credor de uma despesa específica). Ver
 * docs/feature/concluidas/202608/20260825-pagamentos-grid-pix/specify.md §2.7.
 *
 * O comprovante pode ter sido enviado por um responsável (`confirmed_by`), e então passa pelo
 * aceite do credor (feature 20261110-quem-paga-se-declara-e-credor-recusa). O estado é derivado de
 * `confirmed_by`, `accepted_at` e `refused_at`: veja `status()`.
 */
class SettlementConfirmation extends Model
{
    /** Enviado pelo próprio devedor: vale para o selo do ciclo na hora. */
    public const STATUS_SENT = 'sent';

    /** Enviado por um responsável e ainda sem decisão do credor: não vale para o selo. */
    public const STATUS_AWAITING_ACCEPTANCE = 'awaiting_acceptance';

    /** Recusado pelo credor: o acerto volta a pendente. */
    public const STATUS_REFUSED = 'refused';

    /** Aceito pelo credor: definitivo e vale para o selo. */
    public const STATUS_ACCEPTED = 'accepted';

    protected $table = 'ex_settlement_confirmations';

    protected $fillable = [
        'group_id',
        'cycle_start',
        'cycle_end',
        'from_user_id',
        'to_user_id',
        'confirmed_by',
        'amount',
        'proof_path',
        'note',
        'confirmed_at',
        'accepted_at',
        'refused_at',
    ];

    protected $appends = ['proof_url'];

    protected $casts = [
        'cycle_start' => 'date',
        'cycle_end' => 'date',
        'amount' => 'float',
        'confirmed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'refused_at' => 'datetime',
    ];

    /**
     * Enviado por outra pessoa que não o devedor (um responsável)? `confirmed_by` nulo ou igual ao
     * devedor é o próprio devedor.
     */
    public function isThirdParty(): bool
    {
        return $this->confirmed_by !== null && (int) $this->confirmed_by !== (int) $this->from_user_id;
    }

    /**
     * Estado do comprovante, derivado (sem coluna `status`): `refused_at` preenchido é recusado; senão
     * `accepted_at`, aceito; senão, de um responsável, aguardando aceite; senão, enviado pelo devedor.
     */
    public function status(): string
    {
        if ($this->refused_at !== null) {
            return self::STATUS_REFUSED;
        }

        if ($this->accepted_at !== null) {
            return self::STATUS_ACCEPTED;
        }

        return $this->isThirdParty() ? self::STATUS_AWAITING_ACCEPTANCE : self::STATUS_SENT;
    }

    /**
     * Comprovantes que valem para o selo do ciclo: o do devedor (`confirmed_by` nulo ou o próprio
     * devedor) e o de responsável **aceito**; nunca um recusado. É o único lugar que define "vale"
     * (a mesma regra de `status()`: enviado ou aceito).
     *
     * @param  Builder<SettlementConfirmation>  $query
     * @return Builder<SettlementConfirmation>
     */
    public function scopeCountsForSettlement(Builder $query): Builder
    {
        return $query->whereNull('refused_at')
            ->where(fn (Builder $inner) => $inner->whereNull('confirmed_by')
                ->orWhereColumn('confirmed_by', 'from_user_id')
                ->orWhereNotNull('accepted_at'));
    }

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
     * Quem enviou o comprovante: o responsável do devedor, ou nulo quando foi o próprio devedor
     * (feature 20261109-responsavel-do-devedor). O acerto segue sendo de `fromUser`.
     */
    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
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
