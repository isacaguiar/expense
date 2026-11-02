<?php

namespace App\Http\Controllers;

use App\Mail\UserInvitedMail;
use App\Models\Group;
use App\Models\User;
use App\Services\MemberExitBlockers;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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

        // 3) Tenta carregar o usuário
        $user = User::where('email', $data['email'])->first();
        $isNewUser = false;

        if (! $user) {
            $isNewUser = true;

            // 3.1) Cria senha aleatória e preenche name (pode ajustar)
            $password = Str::random(10);
            $user = User::create([
                'name' => explode('@', $data['email'])[0],
                'email' => $data['email'],
                'password' => bcrypt($password),
            ]);

            // 3.2) Gera token de convite dedicado (2 dias) — não usa
            // Password::getRepository()/config('auth.passwords.users.expire'),
            // que é infraestrutura genérica de "esqueci senha" (60 min).
            $token = bin2hex(random_bytes(32));
            Cache::put('invitation-token:'.$user->email, $token, now()->addDays(2));

            // 3.3) Dispara e‑mail de convite
            Mail::to($user->email)
                ->send(new UserInvitedMail($user, $group, $token, auth()->user()));
        }

        // 4) Evita duplicata no pivot
        if ($group->members()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'Usuário já é membro deste grupo.',
            ], 409);
        }

        // 5) Associa ao grupo
        $group->members()->attach($user->id);

        // Chegar aqui é attach real — o passo 4 já devolveu 409 se o usuário
        // já era membro.
        Notifier::groupMemberAdded($group, $user->id, auth()->user()->name);

        return response()->json([
            'message' => $isNewUser
                ? 'Usuário criado e convidado com sucesso.'
                : 'Membro adicionado com sucesso.',
        ], 201);
    }

    /**
     * O próprio usuário sai do grupo, ou o criador do grupo remove outro membro
     * (item 086, docs/feature/20261030-saida-e-remocao-de-membro/).
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
