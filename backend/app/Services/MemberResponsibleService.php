<?php

namespace App\Services;

use App\Exceptions\MemberResponsibleRefused;
use App\Models\Group;
use App\Models\GroupMemberResponsible;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Regras do vínculo "quem paga por quem" (feature 20261109-responsavel-do-devedor, plan §2):
 * por grupo, cada devedor tem nenhum, um ou vários responsáveis, e a lista é sempre substituída
 * inteira. A classe não conhece HTTP; o controller orquestra (Constitution §1 item 3).
 */
class MemberResponsibleService
{
    /**
     * Substitui a lista de responsáveis de `$debtorId` em `$group` por `$responsibleIds` (a lista
     * vazia desfaz tudo). Quem continua na lista **não** tem a linha recriada; devolve os ids que
     * entraram e os que saíram, na ordem em que aparecem em `$responsibleIds` e na lista antiga.
     *
     * O grupo é travado (`lockForUpdate`) e relido, como no `destroy` de membro: serializa o `PUT`
     * contra a saída de quem foi escolhido (F1, F2). Tudo é conferido já com o lock e **antes** de
     * qualquer escrita, então uma lista recusada não altera nada.
     *
     * @param  array<int, int>  $responsibleIds
     * @return array{added: list<int>, removed: list<int>}
     *
     * @throws MemberResponsibleRefused o devedor na própria lista, id repetido ou quem não é membro
     */
    public function replace(Group $group, int $debtorId, array $responsibleIds): array
    {
        $ids = array_map('intval', array_values($responsibleIds));

        return DB::transaction(function () use ($group, $debtorId, $ids) {
            $locked = Group::lockForUpdate()->find($group->id);
            abort_if($locked === null || $locked->deleted, 404);

            if (in_array($debtorId, $ids, true)) {
                throw new MemberResponsibleRefused('Você não pode ser quem paga por você mesmo.');
            }

            if (count($ids) !== count(array_unique($ids))) {
                throw new MemberResponsibleRefused('A lista não pode repetir ninguém.');
            }

            $memberIds = $locked->members()
                ->whereIn('ex_users.id', [...$ids, $debtorId])
                ->pluck('ex_users.id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (! in_array($debtorId, $memberIds, true)) {
                throw new MemberResponsibleRefused('Quem escolhe precisa ser membro deste grupo.');
            }

            if (array_diff($ids, $memberIds) !== []) {
                throw new MemberResponsibleRefused('Escolha só membros deste grupo.');
            }

            $current = GroupMemberResponsible::where('group_id', $locked->id)
                ->where('user_id', $debtorId)
                ->pluck('responsible_user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $added = array_values(array_diff($ids, $current));
            $removed = array_values(array_diff($current, $ids));

            if ($removed !== []) {
                GroupMemberResponsible::where('group_id', $locked->id)
                    ->where('user_id', $debtorId)
                    ->whereIn('responsible_user_id', $removed)
                    ->delete();
            }

            foreach ($added as $responsibleId) {
                GroupMemberResponsible::create([
                    'group_id' => $locked->id,
                    'user_id' => $debtorId,
                    'responsible_user_id' => $responsibleId,
                    // Quem escolhe a lista é o devedor; a declaração do responsável grava o próprio id.
                    'created_by' => $debtorId,
                ]);
            }

            return ['added' => $added, 'removed' => $removed];
        });
    }

    /**
     * Apaga os vínculos de `$userId` em `$groupId`, nas duas direções: como devedor e como
     * responsável (R12, usado na saída ou remoção de membro). Devolve quantas linhas apagou.
     */
    public function clearFor(int $groupId, int $userId): int
    {
        return GroupMemberResponsible::where('group_id', $groupId)
            ->where(fn ($query) => $query->where('user_id', $userId)->orWhere('responsible_user_id', $userId))
            ->delete();
    }

    /** `$responsibleId` pode pagar por `$debtorId` em `$groupId`? Só com a linha exata (R6). */
    public function canPayFor(int $groupId, int $responsibleId, int $debtorId): bool
    {
        return GroupMemberResponsible::where('group_id', $groupId)
            ->where('user_id', $debtorId)
            ->where('responsible_user_id', $responsibleId)
            ->exists();
    }

    /**
     * Os responsáveis de cada devedor do grupo, numa consulta. Chaveada pelo id do devedor; cada
     * valor é uma lista de `['id' => ..., 'name' => ...]` ordenada por nome, e só esses dois
     * campos (R13: nunca e-mail, Pix ou contato). Quem não tem responsável não aparece.
     *
     * @return Collection<int, list<array{id: int, name: string}>>
     */
    public function responsiblesByDebtor(int $groupId): Collection
    {
        return GroupMemberResponsible::where('group_id', $groupId)
            ->with('responsible:id,name')
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $rows) => $rows
                ->map(fn (GroupMemberResponsible $row) => [
                    'id' => $row->responsible->id,
                    'name' => $row->responsible->name,
                ])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all());
    }
}
