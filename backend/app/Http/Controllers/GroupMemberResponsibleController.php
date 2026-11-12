<?php

namespace App\Http\Controllers;

use App\Exceptions\MemberResponsibleRefused;
use App\Models\Group;
use App\Services\MemberResponsibleService;
use App\Support\Notifier;
use Illuminate\Http\Request;

/**
 * Quem paga por quem (feature 20261109-responsavel-do-devedor, plan §3): o membro define a lista
 * inteira de responsáveis dele no grupo, e a lista vazia desfaz tudo. A regra mora em
 * `MemberResponsibleService`; aqui só ficam a autorização, a validação do corpo e os avisos.
 */
class GroupMemberResponsibleController extends Controller
{
    /** Teto de pessoas numa lista (BAIXA-3 da revisão de segurança: uma lista enorme prendia o lock do grupo). */
    public const MAX_RESPONSIBLES = 50;

    /**
     * `PUT /groups/{groupId}/members/{userId}/responsibles`.
     *
     * Só o próprio devedor define a lista dele (R2, D1-A): qualquer outro membro, inclusive o criador
     * do grupo, recebe 403. Quem não é membro, ou o grupo excluído, recebe 404, como o resto das rotas
     * de membros. Corpo: `responsible_user_ids`, a lista **inteira** (`present`, e não `required`, para
     * aceitar a vazia) e com no máximo `MAX_RESPONSIBLES` pessoas. Repetidos e quem não é membro viram
     * 422 do service, sem alterar nada.
     */
    public function update(Request $request, $groupId, $userId)
    {
        $group = Group::find($groupId);
        abort_if($group === null || $group->deleted, 404);
        $this->authorizeGroupMembership($group);

        abort_unless(ctype_digit((string) $userId) && (int) $userId === (int) auth()->id(), 403);

        $data = $request->validate([
            'responsible_user_ids' => 'present|array|max:'.self::MAX_RESPONSIBLES,
            'responsible_user_ids.*' => 'integer',
        ], [
            'responsible_user_ids.max' => 'Escolha no máximo '.self::MAX_RESPONSIBLES.' pessoas.',
        ]);

        $service = app(MemberResponsibleService::class);
        $debtorId = (int) $userId;

        try {
            $result = $service->replace($group, $debtorId, $data['responsible_user_ids']);
        } catch (MemberResponsibleRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Só quem entrou na lista é avisado (R11), e não de novo em 24 h para o mesmo par (item 141);
        // fora do service, depois do commit, e best effort: uma falha do `Notifier` vira log e não
        // desfaz o vínculo (R15).
        foreach ($result['added'] as $responsibleId) {
            Notifier::paymentResponsibleAssigned($group, $debtorId, $responsibleId, auth()->user()->name);
        }

        return response()->json([
            'message' => 'Responsáveis atualizados.',
            'responsibles' => $service->responsiblesByDebtor($group->id)->get($debtorId, []),
        ]);
    }
}
