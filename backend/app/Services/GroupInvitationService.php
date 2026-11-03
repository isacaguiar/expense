<?php

namespace App\Services;

use App\Mail\UserInvitedMail;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Convite pendente para entrar num grupo (feature 20261103-convite-pendente-no-grupo,
 * plan §2): quem convida só cria o convite, quem é convidado é que vira membro, ao aceitar.
 */
class GroupInvitationService
{
    /**
     * Cria, renova ou reabre o convite de `$email` para `$group`, feito por `$inviter`.
     * Devolve `null` quando a pessoa já é membro (o controller responde 409).
     *
     * O grupo é travado (`lockForUpdate`) e relido, como no `destroy` de membro: serializa
     * convites, aceites e saídas do mesmo grupo, sem depender de capturar violação do
     * índice único. O aviso in-app e o e-mail saem **depois** do commit e são best effort
     * (R15): o convite existe e aparece em Meus Grupos com ou sem eles.
     *
     * Duas coisas vêm da revisão de segurança (TASK-576), e as duas existem para o caminho de
     * e-mail novo não ficar mais lento que o de e-mail com conta, o que denunciaria quem já usa o
     * sistema (A1): a senha provisória da conta nova é um hash barato (o `bcrypt` de custo 12 leva
     * ~370 ms; ver `placeholderPassword`) e o e-mail de ativação sai **depois da resposta**, não
     * durante a requisição.
     *
     * A transação tem até 3 tentativas (A4): dois convites novos em grupos diferentes podem cair
     * na mesma lacuna do índice `unique(group_id, user_id)` e dar deadlock (erro 1213), e o
     * Laravel refaz a transação inteira, no nível mais externo.
     */
    public function invite(Group $group, string $email, User $inviter): ?GroupInvitation
    {
        $created = null;

        $invitation = DB::transaction(function () use ($group, $email, $inviter, &$created) {
            // Zerado a cada tentativa: a conta que uma tentativa desfeita criou não existe mais, e
            // se a repetição encontrar a conta que outra requisição criou no intervalo, ela não é
            // nossa e não recebe o e-mail de ativação.
            $created = null;

            $locked = Group::lockForUpdate()->find($group->id);
            abort_if($locked === null || $locked->deleted, 404);

            $invitee = User::where('email', $email)->first();

            if ($invitee === null) {
                $invitee = $created = User::create([
                    'name' => explode('@', $email)[0],
                    'email' => $email,
                    'password' => $this->placeholderPassword(),
                ]);
            }

            if ($locked->members()->where('user_id', $invitee->id)->exists()) {
                return null;
            }

            return $this->openInvitation($locked, $invitee, $inviter);
        }, 3);

        if ($invitation === null) {
            return null;
        }

        $this->notifyInvitee($group, $invitation, $inviter);

        if ($created !== null) {
            $sent = false;

            // No término da requisição, depois de a resposta ser enviada. Não é `dispatch(...)->afterResponse()`:
            // ele passa pela fila `sync`, que serializa o closure, e a trava abaixo se perderia.
            app()->terminating(function () use (&$sent, $group, $created, $inviter) {
                // Uma vez só: os callbacks de término não são descartados depois de executados, e
                // o término pode rodar mais de uma vez no mesmo processo (nos testes, a cada requisição).
                if ($sent) {
                    return;
                }
                $sent = true;

                $this->sendActivationMail($group, $created, $inviter);
            });
        }

        return $invitation;
    }

    /**
     * Senha provisória da conta criada por convite: o hash bcrypt de uma senha aleatória de 40
     * caracteres que ninguém conhece, no custo **mínimo** (4, uns 2 ms). Ela é trocada na ativação
     * (`InvitationController::verify`) e, enquanto isso, o login não aceita nenhuma senha (401).
     *
     * Nem `null` nem o custo padrão (A1 da revisão de segurança):
     * - `null` faria `Hash::check` lançar `RuntimeException` ("does not use the Bcrypt algorithm") e o
     *   login dessa conta daria 500, o que denunciaria a conta pelo status. É o que já acontece com as
     *   contas criadas pelo Google, que nascem sem senha (`GoogleAuthController`);
     * - o custo padrão (12, uns 370 ms) só rodava no caminho de e-mail novo e denunciava, pelo tempo,
     *   quem não tem conta. A força não se perde: 40 caracteres aleatórios a custo 4 são muito mais
     *   difíceis de quebrar que 10 a custo 12, e o `cast hashed` aceita custo menor que o configurado.
     */
    private function placeholderPassword(): string
    {
        return Hash::make(Str::random(40), ['rounds' => 4]);
    }

    /**
     * O convidado aceita o convite: vira membro (`attach`) e o convite fica `accepted`
     * (R7). É o único caminho, além de `GroupController::store`, que insere em
     * `ex_groups_members` (R1).
     *
     * Tudo o que não dá para aceitar é o **mesmo** `abort(404)`: convite de outra pessoa,
     * inexistente, vencido, já respondido, de grupo excluído, ou de quem já é membro (F1,
     * F3). Quem pergunta não descobre qual foi o motivo.
     *
     * A busca inicial já é escopada ao usuário, e é ela que impede um pedido alheio de
     * travar o grupo de outra pessoa. Depois trava-se o **grupo** e então o **convite**
     * (a mesma ordem de `invite()`, sem deadlock) e relê-se sob o lock só o que pode ter
     * mudado: grupo excluído, convite respondido, cancelado ou vencido, e já ser membro.
     * Quem convidou só é avisado se ainda for membro (checado sob o lock, onde ninguém
     * sai do grupo no meio): o aviso leva o `groupId`, e para um ex-membro seria um 404.
     *
     * A busca inicial roda **fora** da transação, de propósito (A2 da revisão de segurança): em
     * REPEATABLE READ a primeira leitura consistente de uma transação abre o snapshot, e ele tem
     * de abrir depois de esperar o lock do grupo. Dentro dela, as leituras de membro (que são
     * `SELECT` simples) veriam o estado de antes da espera, e um ex-membro seria avisado.
     */
    public function accept(int $invitationId, User $user): Group
    {
        $groupId = GroupInvitation::where('id', $invitationId)
            ->where('user_id', $user->id)
            ->value('group_id');
        abort_if($groupId === null, 404);

        [$group, $inviterToNotify] = DB::transaction(function () use ($invitationId, $user, $groupId) {
            $group = Group::lockForUpdate()->find($groupId);
            abort_if($group === null || $group->deleted, 404);

            $invitation = GroupInvitation::lockForUpdate()->find($invitationId);
            abort_if(
                $invitation === null
                    || $invitation->status !== GroupInvitation::STATUS_PENDING
                    || ! $invitation->expires_at->isFuture(),
                404
            );
            abort_if($group->members()->where('user_id', $user->id)->exists(), 404);

            $group->members()->attach($user->id);
            $invitation->update([
                'status' => GroupInvitation::STATUS_ACCEPTED,
                'responded_at' => now(),
            ]);

            $inviterId = $invitation->invited_by === null ? null : (int) $invitation->invited_by;
            $notify = $inviterId !== null
                && $inviterId !== $user->id
                && $group->members()->where('user_id', $inviterId)->exists();

            return [$group, $notify ? $inviterId : null];
        });

        if ($inviterToNotify !== null) {
            Notifier::groupInvitationAccepted($group, $inviterToNotify, $user->name);
        }

        return $group;
    }

    /**
     * Uma linha por (grupo, pessoa) — R3. Convite `pending` e dentro da validade só é
     * renovado (e mantém quem convidou primeiro, F6); respondido, cancelado ou vencido é
     * reaberto como novo (R13, D9-A).
     */
    private function openInvitation(Group $group, User $invitee, User $inviter): GroupInvitation
    {
        $expiresAt = now()->addDays(GroupInvitation::VALIDITY_DAYS);

        // O lock do convite serializa com um aceite, recusa ou cancelamento concorrente.
        $invitation = GroupInvitation::where('group_id', $group->id)
            ->where('user_id', $invitee->id)
            ->lockForUpdate()
            ->first();

        if ($invitation === null) {
            return GroupInvitation::create([
                'group_id' => $group->id,
                'user_id' => $invitee->id,
                'invited_by' => $inviter->id,
                'status' => GroupInvitation::STATUS_PENDING,
                'expires_at' => $expiresAt,
            ]);
        }

        if ($invitation->status === GroupInvitation::STATUS_PENDING && $invitation->expires_at->isFuture()) {
            $invitation->update(['expires_at' => $expiresAt]);

            return $invitation;
        }

        $invitation->update([
            'status' => GroupInvitation::STATUS_PENDING,
            'invited_by' => $inviter->id,
            'expires_at' => $expiresAt,
            'responded_at' => null,
            'notified_at' => null,
        ]);

        return $invitation;
    }

    /**
     * Avisa o convidado se ainda não foi avisado, ou se o último aviso tem mais de 24 h (D5).
     * `notified_at` só é gravado quando o aviso de fato saiu: uma falha do `Notifier` não
     * pode suprimir o próximo.
     */
    private function notifyInvitee(Group $group, GroupInvitation $invitation, User $inviter): void
    {
        $due = $invitation->notified_at === null
            || $invitation->notified_at->lt(now()->subHours(GroupInvitation::RENOTIFY_AFTER_HOURS));

        if ($due && Notifier::groupInvitation($group, $invitation->user_id, $inviter->name)) {
            $invitation->update(['notified_at' => now()]);
        }
    }

    /**
     * E-mail de ativação da conta recém-criada (D2-A): token de 2 dias, dedicado, não o de
     * "esqueci a senha" (60 min). Renovar um convite não reenvia o e-mail. Se falhar, só
     * vira log: o convidado ainda pode usar "Esqueci minha senha" (`AuthController::login`).
     *
     * Roda **depois da resposta** (`invite()` a registra em `app()->terminating`): o SMTP síncrono
     * alongava só o caminho de e-mail novo (A1). O token também sai aqui, junto do e-mail.
     */
    private function sendActivationMail(Group $group, User $user, User $inviter): void
    {
        try {
            $token = bin2hex(random_bytes(32));
            Cache::put('invitation-token:'.$user->email, $token, now()->addDays(2));

            Mail::to($user->email)->send(new UserInvitedMail($user, $group, $token, $inviter));
        } catch (\Throwable $e) {
            Log::warning('GroupInvitationService: falha ao enviar o e-mail de convite', [
                'group_id' => $group->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
