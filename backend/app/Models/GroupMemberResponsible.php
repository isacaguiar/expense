<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vínculo "quem paga por quem" (feature 20261109-responsavel-do-devedor): `responsible_user_id`
 * pode enviar o comprovante dos acertos de `user_id` (o devedor) dentro de `group_id`. Uma linha
 * por (grupo, devedor, responsável); as regras ficam em `MemberResponsibleService`.
 *
 * `created_by` guarda quem criou o vínculo: o devedor (ao escolher a lista) ou o próprio responsável
 * (ao se declarar, feature 20261110-quem-paga-se-declara-e-credor-recusa). Nulo nos vínculos
 * anteriores à coluna, que são todos do devedor.
 */
class GroupMemberResponsible extends Model
{
    protected $table = 'ex_group_member_responsibles';

    protected $fillable = [
        'group_id',
        'user_id',
        'responsible_user_id',
        'created_by',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    /** O devedor. */
    public function debtor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Quem paga por ele. */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
