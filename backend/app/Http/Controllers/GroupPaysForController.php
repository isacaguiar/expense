<?php

namespace App\Http\Controllers;

use App\Exceptions\MemberResponsibleRefused;
use App\Models\Group;
use App\Services\MemberResponsibleService;
use App\Support\Notifier;
use Illuminate\Http\Request;

/**
 * O responsável se declara (feature 20261110-quem-paga-se-declara-e-credor-recusa, plan §7): o membro
 * define a lista inteira de quem **ele** paga no grupo, sem o aceite do devedor (o caso de quem não abre
 * o app), e a lista vazia larga todos os papéis. A regra mora em `MemberResponsibleService::declare`; aqui
 * ficam a autorização, a validação do corpo e os avisos. O devedor continua mandando na própria lista
 * (`GroupMemberResponsibleController`) e a remove quando quiser.
 */
class GroupPaysForController extends Controller
{
    /** Teto de pessoas numa lista (o mesmo do `PUT .../responsibles`: uma lista enorme prendia o lock do grupo). */
    public const MAX_DEBTORS = 50;

    /**
     * `PUT /groups/{groupId}/me/pays-for`.
     *
     * Sempre sobre quem chama: não há `userId` na rota, então ninguém mexe na lista de outra pessoa (R1).
     * Quem não é membro, ou o grupo excluído, ou um id que não é só dígitos recebe 404, como o resto das
     * rotas de membros. Corpo: `debtor_user_ids`, a lista **inteira** (`present`, e não `required`, para
     * aceitar a vazia) e com no máximo `MAX_DEBTORS` pessoas. Repetidos, a si mesmo e quem não é membro
     * viram 422 do service, sem alterar nada.
     */
    public function update(Request $request, $groupId)
    {
        abort_unless(ctype_digit((string) $groupId), 404);

        $group = Group::find((int) $groupId);
        abort_if($group === null || $group->deleted, 404);
        $this->authorizeGroupMembership($group);

        $data = $request->validate([
            'debtor_user_ids' => 'present|array|max:'.self::MAX_DEBTORS,
            'debtor_user_ids.*' => 'integer',
        ], [
            'debtor_user_ids.max' => 'Escolha no máximo '.self::MAX_DEBTORS.' pessoas.',
        ]);

        $service = app(MemberResponsibleService::class);
        $responsibleId = (int) auth()->id();

        try {
            $result = $service->declare($group, $responsibleId, $data['debtor_user_ids']);
        } catch (MemberResponsibleRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Só quem entrou na lista é avisado, e não de novo em 24 h para o mesmo par; fora do service,
        // depois do commit, e best effort: uma falha do `Notifier` vira log e não desfaz o vínculo.
        foreach ($result['added'] as $debtorId) {
            Notifier::paymentResponsibleSelfDeclared($group, $debtorId, $responsibleId, auth()->user()->name);
        }

        return response()->json([
            'message' => 'Escolha salva.',
            'pays_for' => $service->paysFor($group->id, $responsibleId),
        ]);
    }
}
