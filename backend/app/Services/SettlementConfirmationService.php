<?php

namespace App\Services;

use App\Exceptions\SettlementDecisionRefused;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\SettlementConfirmation;
use App\Models\SettlementProofEntry;
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
    /** Motivos de `resendBlockedBy`: o comprovante atual do par não pode ser substituído por este envio. */
    public const BLOCKED_ACCEPTED = 'accepted';

    public const BLOCKED_DEBTOR_PROOF = 'debtor_proof';

    /** O histórico do par já tem o máximo de entradas no ciclo (D5): nenhum arquivo novo cabe. */
    public const BLOCKED_HISTORY_FULL = 'history_full';

    public function __construct(
        private CycleSealingService $sealing,
        private SettlementProofHistoryService $history
    ) {}

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

            $this->recordDecision($confirmation, ['accepted_at' => Carbon::now()]);

            return $confirmation;
        });

        $this->sealing->sealIfSettled($group, $cycle['start'], $cycle['end']);

        return $confirmation;
    }

    /**
     * O credor `$creditorId` recusa o comprovante que um responsável enviou do acerto de `$fromUserId`.
     * Só de `awaiting_acceptance`; grava `refused_at` com a linha travada e relida. **Não apaga a linha nem
     * o arquivo** (D4-B): o acerto volta a pendente na API e quem enviou reenvia (ou retira).
     *
     * `$reason` é o motivo opcional (feature 20261114-historico-de-comprovantes-e-justificativa, D4-A): fica em
     * `refusal_reason`, na linha atual, até o comprovante sair de cena e ir ao histórico (R6). Só é gravado se
     * a recusa vale: com a linha já recusada ou aceita, nada muda.
     *
     * @throws SettlementDecisionRefused
     */
    public function refuse(Group $group, int $fromUserId, int $creditorId, int $cyclesAgo, ?string $reason = null): SettlementConfirmation
    {
        $cycle = $this->decidableCycle($group, $cyclesAgo);

        return DB::transaction(function () use ($group, $cycle, $fromUserId, $creditorId, $reason) {
            $confirmation = $this->lockedAwaitingConfirmation(
                $group,
                $cycle['start'],
                $fromUserId,
                $creditorId,
                'Este comprovante já foi recusado.'
            );

            $this->recordDecision($confirmation, ['refused_at' => Carbon::now(), 'refusal_reason' => $reason]);

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
     * Cria ou substitui o comprovante do par (`ExpenseController::confirmSettlement`). Reenviar é mandar um
     * comprovante novo: volta sem `accepted_at` e sem `refused_at`, então o de responsável volta a "aguardando
     * aceite" e o do devedor a "enviado".
     *
     * **O aceito é definitivo** (R8): com a linha travada e relida, se o credor aceitou entre a checagem do
     * controller e esta gravação, nada é gravado e `confirmation` volta nulo (F6). Só se trava a linha que
     * já existe (`$hadRow`): travar um par sem linha tomaria um gap lock, e dois primeiros envios ao mesmo
     * tempo se travariam um ao outro.
     *
     * `previousPath` é o arquivo que o reenvio substituiu e `wasRefused` diz se o comprovante anterior estava
     * recusado (o credor é avisado do reenvio).
     *
     * **Só o devedor substitui o comprovante dele** (revisão de segurança, TASK-605): quem envia por outra
     * pessoa (`confirmed_by` preenchido) não sobrescreve um comprovante "enviado", o do próprio devedor, que
     * já vale para o selo; senão qualquer membro que se declarasse responsável o trocaria e apagaria o
     * arquivo dele. Com `confirmation` nulo, `blockedBy` diz o motivo (`resendBlockedBy` e, depois dele, o
     * teto do histórico, `historyBlockedBy`).
     *
     * **O comprovante que sai de cena vai ao histórico e o arquivo dele fica** (feature
     * 20261114-historico-de-comprovantes-e-justificativa, plan §2, R1 e R2): com a linha atual travada,
     * `archive($current, 'replaced')` grava a entrada **antes** da troca, na mesma transação (F1: se a troca
     * falhar, a entrada desfaz junto). Por isso o retorno **não traz mais o caminho anterior**: nada apaga o
     * arquivo substituído. O primeiro envio do par (sem linha atual) não cria entrada.
     *
     * @param  array<string, mixed>  $key  `group_id`, `cycle_start`, `from_user_id` e `to_user_id`
     * @param  array<string, mixed>  $values
     * @return array{confirmation: SettlementConfirmation|null, wasRefused: bool, blockedBy: string|null}
     */
    public function submitProof(array $key, array $values, bool $hadRow): array
    {
        return DB::transaction(function () use ($key, $values, $hadRow) {
            $current = $hadRow ? SettlementConfirmation::where($key)->lockForUpdate()->first() : null;

            $blockedBy = self::resendBlockedBy($current, $values['confirmed_by'] ?? null) ?? self::historyBlockedBy($key);
            if ($blockedBy !== null) {
                return ['confirmation' => null, 'wasRefused' => false, 'blockedBy' => $blockedBy];
            }

            $wasRefused = $current?->status() === SettlementConfirmation::STATUS_REFUSED;

            if ($current !== null) {
                $this->history->archive($current, SettlementProofEntry::KIND_REPLACED);
            }

            $confirmation = SettlementConfirmation::updateOrCreate($key, $values + [
                'accepted_at' => null,
                'refused_at' => null,
                'refusal_reason' => null,
            ]);

            return ['confirmation' => $confirmation, 'wasRefused' => $wasRefused, 'blockedBy' => null];
        });
    }

    /**
     * O histórico do par já está cheio neste ciclo (`SettlementProofHistoryService::MAX_ENTRIES`)? O teto vale
     * para **toda ação que grava arquivo novo** (enviar, reenviar e complementar; retirar não grava e fica de
     * fora), então vale também para o primeiro envio. O controller usa o veredito antes de gravar o arquivo e
     * `submitProof`, de novo, com a linha travada.
     *
     * @param  array<string, mixed>  $key  `group_id`, `cycle_start`, `from_user_id` e `to_user_id`
     */
    public static function historyBlockedBy(array $key): ?string
    {
        return app(SettlementProofHistoryService::class)->isFull(
            (int) $key['group_id'],
            (string) $key['cycle_start'],
            (int) $key['from_user_id'],
            (int) $key['to_user_id']
        ) ? self::BLOCKED_HISTORY_FULL : null;
    }

    /**
     * Por que um reenvio não pode substituir o comprovante atual do par, ou nulo se pode: o aceito é
     * definitivo (R8), e o comprovante do próprio devedor só o devedor substitui. `$sentBy` é o `confirmed_by`
     * do envio novo (nulo quando é o próprio devedor). O controller usa o mesmo veredito antes de gravar o
     * arquivo e `submitProof`, de novo, com a linha travada.
     */
    public static function resendBlockedBy(?SettlementConfirmation $current, ?int $sentBy): ?string
    {
        return match (true) {
            $current?->status() === SettlementConfirmation::STATUS_ACCEPTED => self::BLOCKED_ACCEPTED,
            $sentBy !== null && $current?->status() === SettlementConfirmation::STATUS_SENT => self::BLOCKED_DEBTOR_PROOF,
            default => null,
        };
    }

    /** A mensagem de 422 de cada motivo de `resendBlockedBy`. */
    public static function blockedMessage(string $blockedBy): string
    {
        return match ($blockedBy) {
            self::BLOCKED_DEBTOR_PROOF => 'O devedor já enviou o comprovante deste acerto.',
            self::BLOCKED_HISTORY_FULL => 'O limite de comprovantes guardados deste acerto foi atingido.',
            default => 'Este comprovante já foi aceito.',
        };
    }

    /**
     * Grava a decisão do credor (`accepted_at` ou `refused_at`) na linha travada **sem reescrever
     * `confirmed_at`**, a data de **envio** do comprovante (item 147 do backlog, feature
     * 20261114-historico-de-comprovantes-e-justificativa, plan §8).
     *
     * `confirmed_at` é a primeira coluna `timestamp NOT NULL` da tabela: num MySQL com
     * `explicit_defaults_for_timestamp` desligado ela ganha `ON UPDATE CURRENT_TIMESTAMP`, e um `UPDATE` que
     * não a liste a reescreve. Pôr `'confirmed_at' => $confirmation->confirmed_at` num `$confirmation->update()`
     * **não** resolve: o Eloquent só grava atributo sujo, então o mesmo valor não entra no `SET`. Por isso o
     * `UPDATE` sai pelo query builder com `confirmed_at = confirmed_at`, a forma que o MySQL documenta para
     * impedir a atualização automática, e vale com a opção ligada ou desligada.
     *
     * Depois, o model da memória recebe os valores novos (e fica sem atributo pendente), para quem chama
     * seguir lendo `accepted_at` e `refused_at` dele.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function recordDecision(SettlementConfirmation $confirmation, array $attributes): void
    {
        SettlementConfirmation::whereKey($confirmation->getKey())
            ->update($attributes + ['confirmed_at' => DB::raw('`confirmed_at`')]);

        $confirmation->forceFill($attributes)->syncOriginal();
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
