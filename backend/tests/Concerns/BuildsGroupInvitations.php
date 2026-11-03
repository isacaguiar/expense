<?php

namespace Tests\Concerns;

use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;

/**
 * Monta convites de grupo em qualquer estado, sem passar por `POST /groups/{id}/members`.
 * É a "forma equivalente por model" da feature 20261103-convite-pendente-no-grupo: os
 * testes das rotas de resposta, listagem e cancelamento partem de um convite pronto, e só
 * os testes do `store()` exercitam a criação pela API.
 */
trait BuildsGroupInvitations
{
    /**
     * Cria o convite de `$invitee` para `$group` no estado `$status`. `$overrides` troca
     * qualquer coluna (ex.: `invited_by`, `expires_at`, `notified_at`). Convite que já foi
     * respondido nasce com `responded_at`; o `pending` nasce válido por 7 dias.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeInvitation(
        Group $group,
        User $invitee,
        string $status = GroupInvitation::STATUS_PENDING,
        array $overrides = []
    ): GroupInvitation {
        return GroupInvitation::create(array_merge([
            'group_id' => $group->id,
            'user_id' => $invitee->id,
            'invited_by' => null,
            'status' => $status,
            'expires_at' => now()->addDays(GroupInvitation::VALIDITY_DAYS),
            'responded_at' => $status === GroupInvitation::STATUS_PENDING ? null : now(),
        ], $overrides));
    }

    /** Convite `pending` cuja validade já passou (um minuto atrás). */
    protected function makeExpiredInvitation(Group $group, User $invitee, ?User $inviter = null): GroupInvitation
    {
        return $this->makeInvitation($group, $invitee, GroupInvitation::STATUS_PENDING, [
            'invited_by' => $inviter?->id,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
