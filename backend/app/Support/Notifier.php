<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\Group;
use App\Models\Notification;
use App\Models\Quota;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Grava notificações in-app (uma linha por destinatário) nos pontos de evento
 * do domínio — pagamento, ciclo, membro, despesa. Chamado inline nos
 * controllers, no mesmo espírito do `WhatsAppNotifier`, mas síncrono (só um
 * INSERT local). Best-effort: qualquer falha vira `Log::warning` e nunca
 * propaga para a ação que disparou o evento.
 *
 * O payload `data` guarda o que o cliente precisa para montar o texto e o
 * link de destino (`groupId`); o texto em si é responsabilidade do frontend.
 *
 * Ver docs/feature/concluidas/202609/20260903-notificacoes-in-app/plan.md §3.
 */
class Notifier
{
    /** Nomes de mês em pt_BR para o rótulo de competência (`data.cycleLabel`). */
    private const MESES = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
        5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
        9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
    ];

    /**
     * Uma despesa foi criada (`ExpenseController@store`) → avisa os pagadores
     * dela, menos quem a criou.
     */
    public static function expenseCreated(Expense $expense): void
    {
        self::guard('expense_created', function () use ($expense) {
            $expense->loadMissing('payers', 'creator', 'group');

            $recipients = $expense->payers
                ->reject(fn ($u) => $u->id === $expense->user_creator_id)
                ->pluck('id');

            if ($recipients->isEmpty()) {
                return;
            }

            self::fanOut($recipients, 'expense_created', $expense->group_id, [
                'actorName' => $expense->creator?->name,
                'groupId' => $expense->group_id,
                'groupName' => $expense->group?->name,
                'expenseId' => $expense->id,
                'expenseDescription' => $expense->description,
                'amount' => self::amount($expense->total_value),
                'cycleLabel' => self::cycleLabel($expense->date_payment),
            ]);
        });
    }

    /**
     * O credor marcou a ocorrência de uma despesa como paga
     * (`ExpenseController@pay`) → avisa os pagadores dela, menos o próprio
     * credor.
     */
    public static function expensePaid(Expense $expense, Quota $quota): void
    {
        self::guard('expense_paid', function () use ($expense, $quota) {
            $expense->loadMissing('payers', 'payer', 'group');

            $recipients = $expense->payers
                ->reject(fn ($u) => $u->id === $expense->user_payer_id)
                ->pluck('id');

            if ($recipients->isEmpty()) {
                return;
            }

            self::fanOut($recipients, 'expense_paid', $expense->group_id, [
                'actorName' => $expense->payer?->name,
                'groupId' => $expense->group_id,
                'groupName' => $expense->group?->name,
                'expenseId' => $expense->id,
                'expenseDescription' => $expense->description,
                'amount' => self::amount($quota->value_quota),
                'cycleLabel' => self::cycleLabel($quota->date_expected),
            ]);
        });
    }

    /**
     * O ciclo transitou para "selado" (todas as pendências pagas e acertos
     * confirmados — `ExpenseController@sealCycleIfSettled`) → avisa todos os
     * membros do grupo.
     */
    public static function cycleSettled(Group $group, mixed $cycleStart): void
    {
        self::guard('cycle_settled', function () use ($group, $cycleStart) {
            $group->loadMissing('members');

            self::fanOut($group->members->pluck('id'), 'cycle_settled', $group->id, [
                'groupId' => $group->id,
                'groupName' => $group->name,
                'cycleLabel' => self::cycleLabel($cycleStart),
            ]);
        });
    }

    /**
     * Fechamento manual da competência (`ExpenseController@close`, quando não
     * selou direto) → avisa todos os membros do grupo, menos quem fechou.
     */
    public static function cycleClosed(Group $group, mixed $cycleStart, int $actorId, ?string $actorName): void
    {
        self::guard('cycle_closed', function () use ($group, $cycleStart, $actorId, $actorName) {
            $group->loadMissing('members');

            $recipients = $group->members
                ->reject(fn ($u) => $u->id === $actorId)
                ->pluck('id');

            if ($recipients->isEmpty()) {
                return;
            }

            self::fanOut($recipients, 'cycle_closed', $group->id, [
                'actorName' => $actorName,
                'groupId' => $group->id,
                'groupName' => $group->name,
                'cycleLabel' => self::cycleLabel($cycleStart),
            ]);
        });
    }

    /**
     * Um usuário foi adicionado a um grupo (`GroupMemberController@store`) →
     * avisa o próprio usuário adicionado.
     */
    public static function groupMemberAdded(Group $group, int $addedUserId, ?string $actorName): void
    {
        self::guard('group_member_added', function () use ($group, $addedUserId, $actorName) {
            self::fanOut([$addedUserId], 'group_member_added', $group->id, [
                'actorName' => $actorName,
                'groupId' => $group->id,
                'groupName' => $group->name,
            ]);
        });
    }

    /**
     * O devedor confirmou o acerto do ciclo via Pix, com comprovante
     * (`ExpenseController@confirmSettlement`) → avisa o credor.
     */
    public static function settlementConfirmed(
        Group $group,
        int $creditorId,
        ?string $debtorName,
        float|string $amount,
        mixed $cycleStart
    ): void {
        self::guard('settlement_confirmed', function () use ($group, $creditorId, $debtorName, $amount, $cycleStart) {
            self::fanOut([$creditorId], 'settlement_confirmed', $group->id, [
                'actorName' => $debtorName,
                'groupId' => $group->id,
                'groupName' => $group->name,
                'amount' => self::amount($amount),
                'cycleLabel' => self::cycleLabel($cycleStart),
            ]);
        });
    }

    /**
     * Grava uma linha por destinatário.
     *
     * @param  iterable<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    private static function fanOut(iterable $userIds, string $type, ?int $groupId, array $data): void
    {
        foreach ($userIds as $userId) {
            Notification::create([
                'user_id' => $userId,
                'type' => $type,
                'group_id' => $groupId,
                'data' => $data,
            ]);
        }
    }

    /**
     * Roda `$fn`; qualquer exceção só vira log e não sobe — a notificação é
     * best-effort e não pode quebrar a ação que a disparou.
     */
    private static function guard(string $type, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('Notifier: falha ao gravar notificação', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Valor numérico cru (`"45.00"`) — o frontend formata para `R$ 45,00`. */
    private static function amount(float|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** Rótulo de competência em pt_BR: `"setembro/2026"`. */
    private static function cycleLabel(mixed $date): string
    {
        $carbon = Carbon::parse($date);

        return self::MESES[$carbon->month].'/'.$carbon->year;
    }
}
