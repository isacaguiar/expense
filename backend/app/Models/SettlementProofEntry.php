<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;
use LogicException;

/**
 * Uma entrada do histórico dos comprovantes de um acerto (feature
 * 20261114-historico-de-comprovantes-e-justificativa, plan §1): o comprovante que saiu de cena
 * (`replaced`, substituído por um reenvio, ou `withdrawn`, retirado) ou um `complement` com justificativa,
 * sempre com o arquivo guardado. A linha de `SettlementConfirmation` segue sendo o comprovante atual do
 * par; o histórico nunca muda o estado dele nem o selo do ciclo (R3).
 *
 * **Só de acréscimo (R1):** não há `updated_at`, e editar ou apagar uma entrada por este model lança
 * `LogicException`. A guarda vale para o model (`update`, `save` e `delete`); o apagamento em cascata do
 * banco, quando o grupo ou os usuários do par somem, não passa por aqui, e operações em massa pelo query
 * builder também não (nenhum código da feature as usa).
 */
class SettlementProofEntry extends Model
{
    /** Comprovante substituído por um reenvio. */
    public const KIND_REPLACED = 'replaced';

    /** Comprovante retirado de cena por quem enviou ou pelo devedor. */
    public const KIND_WITHDRAWN = 'withdrawn';

    /** Justificativa com foto de quem paga pelo devedor, sobre o comprovante do próprio devedor. */
    public const KIND_COMPLEMENT = 'complement';

    /** Sem `updated_at`: a entrada nunca é atualizada. */
    public const UPDATED_AT = null;

    protected $table = 'ex_settlement_proof_entries';

    protected $fillable = [
        'group_id',
        'cycle_start',
        'from_user_id',
        'to_user_id',
        'kind',
        'sent_by',
        'status_at_exit',
        'amount',
        'proof_path',
        'note',
        'refusal_reason',
        'sent_at',
        'refused_at',
    ];

    /**
     * O caminho interno do arquivo nunca sai na serialização do model (revisão de segurança da TASK-628): quem
     * apresenta a entrada (`SettlementProofHistoryService::present`) monta o array à mão, e o arquivo só se
     * alcança pela URL assinada. O atributo segue legível no código.
     */
    protected $hidden = ['proof_path'];

    protected $casts = [
        'cycle_start' => 'date',
        'amount' => 'float',
        'sent_at' => 'datetime',
        'refused_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Uma entrada do histórico de comprovantes não pode ser editada.');
        });

        static::deleting(function () {
            throw new LogicException('Uma entrada do histórico de comprovantes não pode ser apagada.');
        });
    }

    /** Quem enviou o comprovante desta entrada (ou o complemento); nulo se a conta foi apagada. */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** O devedor do acerto. */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /** O credor do acerto. */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /**
     * URL assinada de curta duração para baixar o arquivo pela rota `proofs.show` (fora do `jwt.auth`, a
     * aba do navegador não manda `Bearer`; ADR-005). **Sem `$appends`, de propósito:** a URL só sai por
     * montagem explícita, para quem é parte do acerto (plan §6), nunca na serialização do model.
     */
    public function proofUrl(): ?string
    {
        if (! $this->proof_path) {
            return null;
        }

        return URL::temporarySignedRoute('proofs.show', now()->addMinutes(30), [
            'groupId' => $this->group_id,
            'type' => 'settlement_history',
            'id' => $this->id,
        ]);
    }
}
