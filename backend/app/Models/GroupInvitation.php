<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Convite de uma pessoa para entrar num grupo: só quem aceita vira membro (feature
 * 20261103-convite-pendente-no-grupo). Uma linha por par (grupo, convidado); convidar de
 * novo reaproveita a linha, então só o último estado fica guardado.
 *
 * A validade (`expires_at`) é lida na consulta, sem job: veja o escopo `open()`.
 */
class GroupInvitation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    /** Dias de validade de um convite novo ou renovado. */
    public const VALIDITY_DAYS = 7;

    /** Só reenvia o aviso in-app de um convite pendente se o último tiver mais de tantas horas. */
    public const RENOTIFY_AFTER_HOURS = 24;

    protected $table = 'ex_group_invitations';

    protected $fillable = [
        'group_id',
        'user_id',
        'invited_by',
        'status',
        'expires_at',
        'notified_at',
        'responded_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'notified_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** O convidado. */
    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Quem convidou (nulo se a conta deixou de existir). */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Convite vivo: `pending` e dentro da validade. Quem vence neste exato segundo já não
     * é aceitável (`>`, não `>=`). É o único lugar que define "vivo".
     *
     * @param  Builder<GroupInvitation>  $query
     * @return Builder<GroupInvitation>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('expires_at', '>', now());
    }
}
