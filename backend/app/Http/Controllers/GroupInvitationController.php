<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupInvitation;

/**
 * Convites de um grupo, vistos por quem é membro dele (feature
 * 20261103-convite-pendente-no-grupo, plan §4). Quem tem convite ainda não é membro: para
 * estas rotas ele é um estranho e leva o mesmo 404 de um grupo que não existe.
 */
class GroupInvitationController extends Controller
{
    /**
     * Convites vivos (`pending` e dentro da validade) do grupo, em ordem de `id`. Qualquer
     * membro vê a lista, não só quem convidou (R9, RK7: o e-mail é o que um membro digitou).
     *
     * Grupo inexistente, excluído e não membro saem do **mesmo** `abort(404)`, para a
     * resposta não confirmar o grupo a quem não é membro. O id vem da URL: só dígitos conta
     * como id (`Group::find('5abc')` casaria o grupo 5 no MySQL).
     *
     * Formato fixo, montado à mão: e-mail do convidado, nome de quem convidou e a validade.
     */
    public function index($groupId)
    {
        abort_unless(ctype_digit((string) $groupId), 404);

        $group = Group::find((int) $groupId);
        abort_if($group === null || $group->deleted, 404);
        $this->authorizeMembership($group);

        $invitations = GroupInvitation::open()
            ->where('group_id', $group->id)
            ->with('invitee:id,email', 'inviter:id,name')
            ->orderBy('id')
            ->get();

        return response()->json($invitations->map(fn (GroupInvitation $invitation) => [
            'id' => $invitation->id,
            'email' => $invitation->invitee->email,
            'invited_by' => $invitation->inviter === null ? null : [
                'id' => $invitation->inviter->id,
                'name' => $invitation->inviter->name,
            ],
            'expires_at' => $invitation->expires_at,
        ])->values());
    }

    /**
     * 404 (não 403) para não confirmar a existência do grupo a quem não é membro.
     */
    private function authorizeMembership(Group $group): void
    {
        $isMember = $group->members()->where('user_id', auth()->id())->exists();

        abort_unless($isMember, 404);
    }
}
