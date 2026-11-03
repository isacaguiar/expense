<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Services\GroupInvitationService;
use App\Services\MemberExitBlockers;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupMemberController extends Controller
{
    // lista membros de um grupo — só para quem é membro dele
    public function index($groupId)
    {
        // Grupo inexistente e grupo alheio saem do MESMO `abort(404)` (não de um
        // `findOrFail`, que passa pelo handler de ModelNotFoundException e pode
        // devolver outra mensagem): a resposta não deve confirmar a existência do
        // grupo a quem não é membro. Item 085 do backlog.
        $group = Group::find($groupId);
        abort_if($group === null, 404);
        $this->authorizeMembership($group);

        return response()->json($group->members);
    }

    /**
     * Convida uma pessoa para o grupo (feature 20261103-convite-pendente-no-grupo): cria
     * (ou renova, ou reabre) um **convite pendente**; ninguém vira membro sem aceitar. A
     * rota e o 201 são os de antes, mas o 201 deixa de significar "virou membro".
     *
     * A resposta é a mesma para e-mail com e sem conta (R4): não revela quem já usa o
     * sistema. O `email` devolvido é o que quem chama digitou.
     */
    public function store(Request $request, $groupId)
    {
        // 1) Valida só o formato do e‑mail
        $data = $request->validate([
            'email' => 'required|email',
        ]);

        // 2) Busca o grupo e confirma que quem chama é membro dele
        $group = Group::findOrFail($groupId);
        $this->authorizeMembership($group);

        // Grupo excluído (logicamente) não aceita novos participantes — 404
        // pelo mesmo motivo do authorizeMembership: não confirmar a um membro
        // antigo que o grupo ainda "existe" para escrita.
        abort_if($group->deleted, 404);

        // 3) Cria, renova ou reabre o convite; `null` = a pessoa já é membro.
        $invitation = app(GroupInvitationService::class)->invite($group, $data['email'], auth()->user());

        if ($invitation === null) {
            return response()->json([
                'message' => 'Usuário já é membro deste grupo.',
            ], 409);
        }

        return response()->json([
            'message' => 'Convite enviado.',
            'pending' => true,
            'invitation' => [
                'id' => $invitation->id,
                'email' => $data['email'],
                'expires_at' => $invitation->expires_at,
            ],
        ], 201);
    }

    /**
     * O próprio usuário sai do grupo, ou o criador do grupo remove outro membro
     * (item 086, docs/feature/concluidas/202610/20261030-saida-e-remocao-de-membro/).
     *
     * O efeito é só tirar a linha de `ex_groups_members` (R3): despesas, quotas,
     * participantes e fechamentos ficam. Quem ainda é credor de parcela aberta de
     * outra pessoa, ou participa de despesa fixa ativa, não sai (D3-B,
     * `MemberExitBlockers`). O último a sair deixa o grupo `deleted = true`
     * (exclusão lógica, D5-A).
     */
    public function destroy($groupId, $userId)
    {
        // 1) Mesmo 404 para grupo inexistente, alheio e excluído (R1): tudo sai do
        // mesmo `abort(404)`, como no `index`, para não confirmar o grupo a quem
        // não é membro.
        $group = Group::find($groupId);
        abort_if($group === null || $group->deleted, 404);
        $this->authorizeMembership($group);

        // 2) Alvo que não é membro (R2): antes de falar em permissão.
        abort_unless($this->isMember($group, $userId), 404);

        // 3) Quem pode (D2-A): o próprio usuário, ou o criador. Grupo antigo sem
        // `created_by` só permite a saída própria.
        $isSelf = (int) $userId === (int) auth()->id();
        $isCreator = $group->created_by !== null && (int) $group->created_by === (int) auth()->id();
        abort_unless($isSelf || $isCreator, 403);

        // 4) O lock serializa as saídas do mesmo grupo (F6: os dois últimos membros
        // saindo juntos). Tudo o que foi checado acima é conferido de novo depois
        // dele, porque outra requisição pode ter mudado o grupo enquanto esta
        // esperava; o bloqueio só vale se for calculado já com o lock.
        $blocking = DB::transaction(function () use ($group, $userId) {
            $locked = Group::lockForUpdate()->find($group->id);
            abort_if($locked === null || $locked->deleted, 404);
            $this->authorizeMembership($locked);
            abort_unless($this->isMember($locked, $userId), 404);

            $blocking = app(MemberExitBlockers::class)->for($locked, (int) $userId);
            if ($blocking !== []) {
                return $blocking;
            }

            $locked->members()->detach($userId);

            if ($locked->members()->count() === 0) {
                $locked->update(['deleted' => true]);
            }

            return [];
        });

        if ($blocking !== []) {
            return response()->json([
                'message' => $isSelf
                    ? 'Você ainda tem despesas neste grupo que impedem a sua saída: parcelas que você recebe e ainda não foram pagas, ou despesas fixas ainda ativas. Resolva as despesas listadas e tente de novo.'
                    : 'Este membro ainda tem despesas neste grupo que impedem a remoção: parcelas que ele recebe e ainda não foram pagas, ou despesas fixas ainda ativas. Resolva as despesas listadas e tente de novo.',
                'blocking' => $blocking,
            ], 422);
        }

        // 5) Só quem foi removido por outra pessoa é avisado (R8); sair por conta
        // própria não notifica ninguém. Fora da transação e best effort: uma falha
        // do `Notifier` vira log e não desfaz a remoção (F5).
        if (! $isSelf) {
            Notifier::groupMemberRemoved($group, (int) $userId, auth()->user()->name);
        }

        return response()->json([
            'message' => $isSelf ? 'Você saiu do grupo.' : 'Membro removido do grupo.',
        ]);
    }

    /**
     * 404 (não 403) para não confirmar a existência do grupo a quem não é membro.
     */
    private function authorizeMembership(Group $group): void
    {
        $isMember = $group->members()->where('user_id', auth()->id())->exists();

        abort_unless($isMember, 404);
    }

    /**
     * `$userId` vem da URL: só dígitos conta como id, qualquer outra coisa
     * ("abc", "5abc") não é membro de grupo nenhum.
     */
    private function isMember(Group $group, $userId): bool
    {
        return ctype_digit((string) $userId)
            && $group->members()->where('user_id', $userId)->exists();
    }
}
