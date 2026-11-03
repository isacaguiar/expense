<?php

namespace App\Http\Controllers;

use App\Models\GroupInvitation;
use App\Services\GroupInvitationService;

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

    /**
     * O convidado aceita o convite e vira membro do grupo (R7). Qualquer coisa que impeça
     * (convite alheio, inexistente, vencido, já respondido, grupo excluído, já é membro) é o
     * mesmo 404: ver `GroupInvitationService::accept`.
     *
     * O id vem da URL: só dígitos conta como id. Sem isso o MySQL compararia "5abc" como 5
     * e aceitaria o convite 5 (a mesma lição de `GroupMemberController::isMember`).
     */
    public function accept($invitationId)
    {
        abort_unless(ctype_digit((string) $invitationId), 404);

        $group = app(GroupInvitationService::class)->accept((int) $invitationId, auth()->user());

        return response()->json([
            'message' => 'Você entrou no grupo.',
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
            ],
        ]);
    }

    /**
     * O convidado recusa o convite (R8): não vira membro e **ninguém é avisado** (D6-A:
     * avisar a recusa expõe a escolha de quem recusou sem benefício a quem convidou).
     *
     * Um único `UPDATE` condicional (dono, `pending` e dentro da validade, que é o escopo
     * `open()`): zero linhas afetadas é o mesmo 404 de convite alheio, inexistente, já
     * respondido, cancelado ou vencido, e repetir a recusa também cai aí. Se um aceite
     * concorrente estiver com o convite travado, o `UPDATE` espera o commit e então já não
     * encontra `pending`. Não consulta o grupo: a linha é do próprio convidado e não revela
     * nada dele (o convite de grupo excluído some da lista, mas ainda pode ser recusado).
     *
     * Sem aviso e sem grupo, não há o que travar nem transação a abrir.
     */
    public function decline($invitationId)
    {
        abort_unless(ctype_digit((string) $invitationId), 404);

        $declined = GroupInvitation::open()
            ->where('id', (int) $invitationId)
            ->where('user_id', auth()->id())
            ->update([
                'status' => GroupInvitation::STATUS_DECLINED,
                'responded_at' => now(),
            ]);

        abort_if($declined === 0, 404);

        return response()->json(['message' => 'Convite recusado.']);
    }
}
