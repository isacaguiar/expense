<?php

namespace App\Http\Controllers;

use App\Models\GroupInvitation;

/**
 * Convites que o usuário autenticado recebeu para entrar em grupos (feature
 * 20261103-convite-pendente-no-grupo). O escopo de autorização é sempre o próprio
 * convidado (`user_id`), não um grupo: quem tem convite ainda não é membro, então esta
 * é a única porta por onde ele enxerga o grupo, e só o nome dele e de quem convidou.
 */
class MyInvitationController extends Controller
{
    /**
     * Convites vivos (`pending` e dentro da validade) de grupos não excluídos, do mais
     * recente para o mais antigo. Convidar de novo reaproveita a linha do par (grupo,
     * pessoa), então "mais recente" é `updated_at` e não `id`: o convite renovado ou
     * reaberto sobe para o topo.
     *
     * Formato fixo, montado à mão (não é um dump do model): nome do grupo e de quem
     * convidou, e mais nada. Nem e-mail, nem membros, nem despesas ou saldos (R10).
     */
    public function index()
    {
        $invitations = GroupInvitation::open()
            ->where('user_id', auth()->id())
            ->whereHas('group', fn ($query) => $query->where('deleted', 0))
            ->with('group:id,name', 'inviter:id,name')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        return response()->json($invitations->map(fn (GroupInvitation $invitation) => [
            'id' => $invitation->id,
            'group' => [
                'id' => $invitation->group->id,
                'name' => $invitation->group->name,
            ],
            'invited_by' => $invitation->inviter === null ? null : [
                'id' => $invitation->inviter->id,
                'name' => $invitation->inviter->name,
            ],
            'expires_at' => $invitation->expires_at,
        ])->values());
    }
}
