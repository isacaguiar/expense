<?php

namespace App\Http\Controllers;

use App\Exceptions\SettlementDecisionRefused;
use App\Models\Group;
use App\Models\SettlementConfirmation;
use App\Services\MemberResponsibleService;
use App\Services\SettlementConfirmationService;
use App\Support\Notifier;
use App\Support\ProofStorage;
use Illuminate\Http\Request;

/**
 * A decisão do credor sobre o comprovante que um responsável enviou do acerto de outra pessoa (feature
 * 20261110-quem-paga-se-declara-e-credor-recusa, plan §4): aceitar, recusar e retirar (a "exclusão" virou retirada na 20261114-historico-de-comprovantes-e-justificativa). A regra mora em
 * `SettlementConfirmationService`; aqui ficam a autorização, o corpo e os avisos.
 */
class SettlementDecisionController extends Controller
{
    /**
     * `POST /groups/{groupId}/settlements/accept`.
     *
     * Corpo: `from_user_id` (o devedor), `to_user_id` (o credor, que tem de ser quem chama) e `cycles_ago`
     * opcional, o mesmo formato de `confirm`. Ordem das respostas: 404 para grupo inexistente, excluído ou
     * de quem não é membro; 422 de validação; **403** se `to_user_id` não é quem chama, antes de qualquer
     * consulta do comprovante ou do ciclo; 404 se o comprovante não existe; 422 para as regras do service.
     */
    public function accept(Request $request, $groupId)
    {
        [$group, $data] = $this->creditorsPair($request, $groupId, 'Só o credor deste acerto pode aceitar o comprovante.');

        try {
            $confirmation = app(SettlementConfirmationService::class)->accept(
                $group,
                (int) $data['from_user_id'],
                (int) $data['to_user_id'],
                (int) ($data['cycles_ago'] ?? 0)
            );
        } catch (SettlementDecisionRefused $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Quem enviou é avisado, e ninguém é avisado de uma ação que ele mesmo fez (R12): se foi o próprio
        // credor quem enviou, não há aviso.
        if ((int) $confirmation->confirmed_by !== (int) auth()->id()) {
            Notifier::settlementAccepted(
                $group,
                (int) $confirmation->confirmed_by,
                auth()->user()->name,
                $confirmation->amount,
                $confirmation->cycle_start
            );
        }

        return response()->json([
            'message' => 'Comprovante aceito.',
            'confirmationStatus' => SettlementConfirmation::STATUS_ACCEPTED,
        ]);
    }

    /**
     * `POST /groups/{groupId}/settlements/refuse`: o mesmo corpo, a mesma ordem de respostas e as mesmas
     * regras de `accept`, mais o `reason` opcional (até 500; sem ele a recusa é a de sempre), mas grava
     * `refused_at` (e o motivo). **Não apaga a linha nem o arquivo** (D4-B). Quem enviou e o
     * devedor são avisados, menos quem fez a ação.
     */
    public function refuse(Request $request, $groupId)
    {
        [$group, $data] = $this->creditorsPair($request, $groupId, 'Só o credor deste acerto pode recusar o comprovante.', [
            // Motivo opcional (feature 20261114-historico-de-comprovantes-e-justificativa, D4-A): o trim e o "" → null
            // vêm dos middlewares globais. Fica na linha atual e **não** vai para o sino.
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $confirmation = app(SettlementConfirmationService::class)->refuse(
                $group,
                (int) $data['from_user_id'],
                (int) $data['to_user_id'],
                (int) ($data['cycles_ago'] ?? 0),
                $data['reason'] ?? null
            );
        } catch (SettlementDecisionRefused $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Quem enviou e o devedor (de quem é o acerto) sabem da recusa; o credor, que a fez, não.
        $recipients = collect([$confirmation->confirmed_by, $confirmation->from_user_id])
            ->filter(fn ($id) => $id !== null && (int) $id !== (int) auth()->id())
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($recipients !== []) {
            Notifier::settlementRefused(
                $group,
                $recipients,
                auth()->user()->name,
                $confirmation->fromUser?->name,
                $confirmation->amount,
                $confirmation->cycle_start
            );
        }

        return response()->json([
            'message' => 'Comprovante recusado.',
            'confirmationStatus' => SettlementConfirmation::STATUS_REFUSED,
        ]);
    }

    /**
     * `DELETE /groups/{groupId}/settlements/confirmation?from_user_id=&to_user_id=&cycles_ago=`: quem
     * enviou o comprovante, ou o devedor, o **retira** do acerto (feature
     * 20261114-historico-de-comprovantes-e-justificativa, D6-A): a linha atual sai (a única exclusão física
     * que sobra, dentro do aval de hard delete da D7-A da feature anterior) e vai, com o **arquivo**, ao
     * histórico (`withdrawn`); o arquivo não é apagado. Ordem: 404 para grupo inexistente, excluído ou de quem não é membro; 422 de
     * validação; depois, no service, 422 de ciclo, 404 sem comprovante, **403** para quem não é quem enviou
     * nem o devedor, e 422 para o comprovante que não passa pelo aceite ou já foi aceito. Ninguém é avisado.
     */
    public function destroy(Request $request, $groupId)
    {
        $group = $this->findGroup($groupId);
        $this->authorizeGroupMembership($group);

        $data = $this->validatedPair($request);

        try {
            app(SettlementConfirmationService::class)->destroy(
                $group,
                (int) $data['from_user_id'],
                (int) $data['to_user_id'],
                (int) auth()->id(),
                (int) ($data['cycles_ago'] ?? 0)
            );
        } catch (SettlementDecisionRefused $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Comprovante retirado.']);
    }

    /**
     * `POST /groups/{groupId}/settlements/complement` (feature 20261114-historico-de-comprovantes-e-
     * justificativa, plan §5): quem paga pelo devedor adiciona uma justificativa com foto sobre o comprovante
     * que o **próprio devedor** enviou. A entrada vai para o histórico; a linha atual, o estado do comprovante
     * e o selo **não mudam** (R3), e vale também com o ciclo encerrado (D7-B, a exceção à R9).
     *
     * Corpo multipart: `from_user_id` (o devedor), `to_user_id` (o credor), `cycles_ago` opcional,
     * `comprovante` (imagem até 5 MB) e `justificativa` (obrigatória, até 500). Ordem das respostas: 404 para
     * grupo inexistente, excluído ou de quem não é membro; 422 de validação; **403** se quem chama não paga pelo
     * devedor (ou é o próprio devedor ou o credor: o devedor já substitui o dele e o credor nunca complementa),
     * antes de qualquer arquivo, linha ou consulta de ciclo; 422 de ciclo não fechado, de estado do comprovante e
     * do teto, 404 sem comprovante, **todos antes de gravar o arquivo**; só então o arquivo e a entrada.
     */
    public function complement(Request $request, $groupId)
    {
        $group = $this->findGroup($groupId);
        $this->authorizeGroupMembership($group);

        $data = $this->validatedPair($request, [
            'comprovante' => 'required|image|max:5120',
            // Obrigatória; o trim e "" → null vêm dos middlewares globais, então só espaços também falha.
            'justificativa' => 'required|string|max:500',
        ]);

        $callerId = (int) auth()->id();
        $debtorId = (int) $data['from_user_id'];
        $creditorId = (int) $data['to_user_id'];

        abort_unless(
            $callerId !== $debtorId
                && $callerId !== $creditorId
                && app(MemberResponsibleService::class)->canPayFor((int) $group->id, $callerId, $debtorId),
            403,
            'Só quem paga pelo devedor pode adicionar a justificativa.'
        );

        $service = app(SettlementConfirmationService::class);

        try {
            $cycle = $service->complementTarget($group, (int) ($data['cycles_ago'] ?? 0), $debtorId, $creditorId);
        } catch (SettlementDecisionRefused $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $path = ProofStorage::store($request->file('comprovante'), (int) $group->id);

        try {
            $service->addComplement($group, $cycle, $debtorId, $creditorId, $callerId, $path, (string) $data['justificativa']);
        } catch (SettlementDecisionRefused $e) {
            // A corrida (outro pedido mudou o estado ou encheu o histórico entre a checagem e a trava).
            ProofStorage::delete($path);

            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            // O banco não apontou para o arquivo novo: não deixa órfão.
            ProofStorage::delete($path);

            throw $e;
        }

        return response()->json(['message' => 'Justificativa e comprovante enviados.'], 201);
    }

    /**
     * O preâmbulo de `accept` e `refuse`: o grupo (404), a autorização de membro (404), o corpo (422) e,
     * por último, que `to_user_id` seja quem chama (403, antes de qualquer consulta do comprovante).
     *
     * @return array{0: Group, 1: array{from_user_id: int, to_user_id: int, cycles_ago?: int|null}}
     */
    private function creditorsPair(Request $request, $groupId, string $forbiddenMessage, array $extraRules = []): array
    {
        $group = $this->findGroup($groupId);
        $this->authorizeGroupMembership($group);

        $data = $this->validatedPair($request, $extraRules);
        abort_unless((int) $data['to_user_id'] === (int) auth()->id(), 403, $forbiddenMessage);

        return [$group, $data];
    }

    /** O grupo, ou 404 se o id não é só dígitos, não existe ou foi excluído (`find('5abc')` acharia o 5). */
    private function findGroup($groupId): Group
    {
        abort_unless(ctype_digit((string) $groupId), 404);

        $group = Group::find((int) $groupId);
        abort_if($group === null || $group->deleted, 404);

        return $group;
    }

    /**
     * `from_user_id` e `to_user_id` existentes e `cycles_ago` opcional.
     *
     * @return array{from_user_id: int, to_user_id: int, cycles_ago?: int|null}
     */
    private function validatedPair(Request $request, array $extraRules = []): array
    {
        return $request->validate([
            'from_user_id' => 'required|integer|exists:ex_users,id',
            'to_user_id' => 'required|integer|exists:ex_users,id',
            'cycles_ago' => 'nullable|integer|min:0',
        ] + $extraRules);
    }
}
