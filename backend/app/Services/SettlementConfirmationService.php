<?php

namespace App\Services;

use App\Exceptions\SettlementDecisionRefused;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\SettlementConfirmation;
use App\Support\BillingCycle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Regras da decisão do credor sobre o comprovante que um responsável enviou: aceitar (e, se isso quita o
 * ciclo, selar), e, nas tasks seguintes, recusar e excluir (feature 20261110-quem-paga-se-declara-e-
 * credor-recusa, plan §4). Não conhece HTTP; o controller orquestra a autorização, o corpo e os avisos.
 */
class SettlementConfirmationService
{
    public function __construct(private CycleSealingService $sealing) {}

    /**
     * O ciclo `cyclesAgo` ciclos atrás, se ainda dá para decidir sobre os comprovantes dele: precisa estar
     * **fechado** (por data ou manualmente, como em `ExpenseController::confirmSettlement`) e **não
     * encerrado**; ciclo selado é histórico imutável e esta feature nunca o reabre (D5).
     *
     * @return array{start: Carbon, end: Carbon, status: string}
     *
     * @throws SettlementDecisionRefused
     */
    public function decidableCycle(Group $group, int $cyclesAgo): array
    {
        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $cyclesAgo);

        $snapshot = GroupCycleSnapshot::where('group_id', $group->id)
            ->where('cycle_start', $cycle['start']->toDateString())
            ->first();

        $closed = $cycle['status'] === 'closed'
            || ($cycle['status'] === 'open' && $snapshot && $snapshot->isManuallyClosedAndActive());

        if (! $closed) {
            throw new SettlementDecisionRefused('O acerto só pode ser confirmado depois que a competência é fechada.');
        }

        if ($snapshot && $snapshot->isSealed()) {
            throw new SettlementDecisionRefused('Esta competência já foi encerrada.');
        }

        return $cycle;
    }

    /**
     * O credor `$creditorId` aceita o comprovante que um responsável enviou do acerto de `$fromUserId`.
     * Só de `awaiting_acceptance`; grava `accepted_at` com a linha travada e relida (dois aceites ao mesmo
     * tempo dão um sucesso e uma recusa). Depois da transação, sela o ciclo se o aceite o quitou.
     *
     * @throws SettlementDecisionRefused
     */
    public function accept(Group $group, int $fromUserId, int $creditorId, int $cyclesAgo): SettlementConfirmation
    {
        $cycle = $this->decidableCycle($group, $cyclesAgo);

        $confirmation = DB::transaction(function () use ($group, $cycle, $fromUserId, $creditorId) {
            $confirmation = $this->lockedAwaitingConfirmation(
                $group,
                $cycle['start'],
                $fromUserId,
                $creditorId,
                'Este comprovante foi recusado: quem enviou precisa reenviar.'
            );

            $confirmation->update(['accepted_at' => Carbon::now()]);

            return $confirmation;
        });

        $this->sealing->sealIfSettled($group, $cycle['start'], $cycle['end']);

        return $confirmation;
    }

    /**
     * O credor `$creditorId` recusa o comprovante que um responsável enviou do acerto de `$fromUserId`.
     * Só de `awaiting_acceptance`; grava `refused_at` com a linha travada e relida. **Não apaga a linha nem
     * o arquivo** (D4-B): o acerto volta a pendente na API e quem enviou reenvia (ou exclui, TASK-600).
     *
     * @throws SettlementDecisionRefused
     */
    public function refuse(Group $group, int $fromUserId, int $creditorId, int $cyclesAgo): SettlementConfirmation
    {
        $cycle = $this->decidableCycle($group, $cyclesAgo);

        return DB::transaction(function () use ($group, $cycle, $fromUserId, $creditorId) {
            $confirmation = $this->lockedAwaitingConfirmation(
                $group,
                $cycle['start'],
                $fromUserId,
                $creditorId,
                'Este comprovante já foi recusado.'
            );

            $confirmation->update(['refused_at' => Carbon::now()]);

            return $confirmation;
        });
    }

    /**
     * O comprovante do par, travado, e só se ainda aguarda a decisão do credor: de responsável (R6), nem
     * aceito (definitivo, R8) nem recusado. `$whenRefused` é a mensagem do caso "recusado", que muda com a
     * ação (aceitar ou recusar de novo).
     *
     * @throws SettlementDecisionRefused
     */
    private function lockedAwaitingConfirmation(Group $group, Carbon $cycleStart, int $fromUserId, int $creditorId, string $whenRefused): SettlementConfirmation
    {
        $confirmation = $this->lockedConfirmation($group, $cycleStart, $fromUserId, $creditorId);

        if (! $confirmation->isThirdParty()) {
            throw new SettlementDecisionRefused('Só o comprovante enviado por quem paga por outra pessoa passa pelo aceite.');
        }

        if ($confirmation->status() === SettlementConfirmation::STATUS_ACCEPTED) {
            throw new SettlementDecisionRefused('Este comprovante já foi aceito.');
        }

        if ($confirmation->status() === SettlementConfirmation::STATUS_REFUSED) {
            throw new SettlementDecisionRefused($whenRefused);
        }

        return $confirmation;
    }

    /**
     * O comprovante do par no ciclo, lido com `lockForUpdate` (dentro de uma transação). 404 se não existe.
     */
    private function lockedConfirmation(Group $group, Carbon $cycleStart, int $fromUserId, int $toUserId): SettlementConfirmation
    {
        $confirmation = SettlementConfirmation::where('group_id', $group->id)
            ->where('cycle_start', $cycleStart->toDateString())
            ->where('from_user_id', $fromUserId)
            ->where('to_user_id', $toUserId)
            ->lockForUpdate()
            ->first();

        abort_if($confirmation === null, 404);

        return $confirmation;
    }
}
