<?php

namespace App\Services;

use App\Exceptions\SettlementDecisionRefused;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\SettlementConfirmation;
use App\Support\BillingCycle;
use App\Support\ProofStorage;
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
     * Quem enviou o comprovante, ou o devedor, o **exclui** enquanto o credor não o aceitou (D7-A). É a
     * **única exclusão física** desta feature, com o aval de hard delete dele em 2026-11-10 (specify §2.8 e
     * §3.4), limitada ao comprovante que passa pelo aceite (o de responsável) e, por `decidableCycle`, ao
     * ciclo que ainda não está encerrado. Vale em `awaiting_acceptance` e em `refused`; o aceito é definitivo.
     *
     * A linha sai primeiro, com ela travada e relida, e o arquivo depois da transação: só se nenhuma outra
     * linha de acerto nem parcela o usa (`ProofStorage::deleteIfUnreferenced` olha o banco, então precisa
     * rodar **depois** de a linha sumir). Não exige o vínculo "quem paga por quem": quem saiu da lista ainda
     * exclui o próprio comprovante. O credor, o criador do grupo e os demais membros recebem 403.
     *
     * @throws SettlementDecisionRefused
     */
    public function destroy(Group $group, int $fromUserId, int $creditorId, int $callerId, int $cyclesAgo): void
    {
        $cycle = $this->decidableCycle($group, $cyclesAgo);

        $path = DB::transaction(function () use ($group, $cycle, $fromUserId, $creditorId, $callerId) {
            $confirmation = $this->lockedConfirmation($group, $cycle['start'], $fromUserId, $creditorId);

            abort_unless(
                $callerId === (int) $confirmation->from_user_id || $callerId === (int) $confirmation->confirmed_by,
                403,
                'Só quem enviou o comprovante ou o devedor pode excluí-lo.'
            );

            if (! $confirmation->isThirdParty()) {
                throw new SettlementDecisionRefused('Só o comprovante enviado por quem paga por outra pessoa pode ser excluído.');
            }

            if ($confirmation->status() === SettlementConfirmation::STATUS_ACCEPTED) {
                throw new SettlementDecisionRefused('Este comprovante já foi aceito e não pode ser excluído.');
            }

            $path = $confirmation->proof_path;
            $confirmation->delete();

            return $path;
        });

        ProofStorage::deleteIfUnreferenced($path);
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
