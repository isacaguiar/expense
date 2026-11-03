<?php

namespace App\Services;

use App\Mail\UserInvitedMail;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     */
    public function invite(Group $group, string $email, User $inviter): ?GroupInvitation
    {
        $created = null;

        $invitation = DB::transaction(function () use ($group, $email, $inviter, &$created) {
            $locked = Group::lockForUpdate()->find($group->id);
            abort_if($locked === null || $locked->deleted, 404);

            $invitee = User::where('email', $email)->first();

            if ($invitee === null) {
                $invitee = $created = User::create([
                    'name' => explode('@', $email)[0],
                    'email' => $email,
                    'password' => bcrypt(Str::random(10)),
                ]);
            }

            if ($locked->members()->where('user_id', $invitee->id)->exists()) {
                return null;
            }

            return $this->openInvitation($locked, $invitee, $inviter);
        });

        if ($invitation === null) {
            return null;
        }

        $this->notifyInvitee($group, $invitation, $inviter);

        if ($created !== null) {
            $this->sendActivationMail($group, $created, $inviter);
        }

        return $invitation;
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
     */
    public function accept(int $invitationId, User $user): Group
    {
        [$group, $inviterToNotify] = DB::transaction(function () use ($invitationId, $user) {
            $groupId = GroupInvitation::where('id', $invitationId)
                ->where('user_id', $user->id)
                ->value('group_id');
            abort_if($groupId === null, 404);

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
