<?php

namespace App\Services;

use App\Models\SettlementConfirmation;
use App\Models\SettlementProofEntry;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use LogicException;

/**
 * Regras do histórico dos comprovantes do acerto (feature 20261114-historico-de-comprovantes-e-
 * justificativa, plan §2). Não conhece HTTP, não grava nem apaga arquivo e **não abre transação**: quem
 * chama (reenviar e retirar, em `SettlementConfirmationService`) já está numa, com a linha atual travada,
 * e é nela que a entrada nasce junto da troca da linha (F1).
 *
 * O histórico fica fora do caminho do selo (R3): nada aqui lê nem muda `status()` ou
 * `countsForSettlement` da linha atual.
 */
class SettlementProofHistoryService
{
    /**
     * Teto de entradas por par e ciclo (D5): cada arquivo pode ter 5 MB e a produção é um host
     * compartilhado. Vale para toda ação que **grava arquivo novo** (enviar, reenviar e complementar);
     * retirar não grava arquivo e por isso não é barrado (plan, ajuste 1).
     */
    public const MAX_ENTRIES = 10;

    /**
     * O par já guardou o máximo de entradas neste ciclo? Conta pelo índice do par; entradas de outro par,
     * de outro ciclo ou de outro grupo não entram.
     */
    public function isFull(int $groupId, CarbonInterface|string $cycleStart, int $fromUserId, int $toUserId): bool
    {
        $cycle = $cycleStart instanceof CarbonInterface ? $cycleStart->toDateString() : $cycleStart;

        return SettlementProofEntry::where('group_id', $groupId)
            ->where('cycle_start', $cycle)
            ->where('from_user_id', $fromUserId)
            ->where('to_user_id', $toUserId)
            ->count() >= self::MAX_ENTRIES;
    }

    /**
     * Guarda no histórico o comprovante `$row` que está saindo de cena (`replaced`, substituído por um
     * reenvio, ou `withdrawn`, retirado): copia o arquivo (o **caminho**, o arquivo não é tocado, R2), a
     * nota, o valor, o motivo e a data da recusa, a data de envio (`confirmed_at`), o estado em que estava
     * e quem o enviou (`confirmed_by`, ou o próprio devedor quando nulo). A linha atual não é alterada;
     * quem chama a troca ou a remove em seguida, na mesma transação.
     *
     * O comprovante **aceito é definitivo** e nunca sai de cena (R4): lança `LogicException` e não grava.
     * O `complement` não passa por aqui (nasce de outro caminho, com autor e justificativa próprios).
     *
     * @throws LogicException se o comprovante está aceito
     * @throws InvalidArgumentException se `$kind` não é `replaced` nem `withdrawn`
     */
    public function archive(SettlementConfirmation $row, string $kind): SettlementProofEntry
    {
        if (! in_array($kind, [SettlementProofEntry::KIND_REPLACED, SettlementProofEntry::KIND_WITHDRAWN], true)) {
            throw new InvalidArgumentException("O tipo \"{$kind}\" não é de um comprovante que sai de cena.");
        }

        $status = $row->status();

        if ($status === SettlementConfirmation::STATUS_ACCEPTED) {
            throw new LogicException('O comprovante aceito é definitivo e não vai ao histórico.');
        }

        return SettlementProofEntry::create([
            'group_id' => $row->group_id,
            'cycle_start' => $row->cycle_start,
            'from_user_id' => $row->from_user_id,
            'to_user_id' => $row->to_user_id,
            'kind' => $kind,
            'sent_by' => $row->confirmed_by ?? $row->from_user_id,
            'status_at_exit' => $status,
            'amount' => $row->amount,
            'proof_path' => $row->proof_path,
            'note' => $row->note,
            'refusal_reason' => $row->refusal_reason,
            'sent_at' => $row->confirmed_at,
            'refused_at' => $row->refused_at,
        ]);
    }
}
