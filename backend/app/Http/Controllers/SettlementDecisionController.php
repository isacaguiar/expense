<?php

namespace App\Http\Controllers;

use App\Exceptions\SettlementDecisionRefused;
use App\Models\Group;
use App\Models\SettlementConfirmation;
use App\Services\SettlementConfirmationService;
use App\Support\Notifier;
use Illuminate\Http\Request;

/**
 * A decisão do credor sobre o comprovante que um responsável enviou do acerto de outra pessoa (feature
 * 20261110-quem-paga-se-declara-e-credor-recusa, plan §4): aceitar e, nas tasks seguintes, recusar e
 * excluir. A regra mora em `SettlementConfirmationService`; aqui ficam a autorização, o corpo e os avisos.
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
        $group = $this->findGroup($groupId);
        $this->authorizeGroupMembership($group);

        $data = $this->validatedPair($request);
        abort_unless((int) $data['to_user_id'] === (int) auth()->id(), 403, 'Só o credor deste acerto pode aceitar o comprovante.');

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
    private function validatedPair(Request $request): array
    {
        return $request->validate([
            'from_user_id' => 'required|integer|exists:ex_users,id',
            'to_user_id' => 'required|integer|exists:ex_users,id',
            'cycles_ago' => 'nullable|integer|min:0',
        ]);
    }
}
