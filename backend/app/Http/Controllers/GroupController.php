<?php

namespace App\Http\Controllers;

use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GroupController extends Controller
{
    public function index()
    {
        // pega o ID do usuário logado
        $userId = auth()->id();
        Log::info("User authenticated with ID: {$userId}");

        // filtra só grupos não deletados e onde há pivot para este user_id
        $grupos = Group::where('deleted', 0)
            ->whereHas('members', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->with('creator:id,email', 'members:id,name,email')
            ->withMax('expenses', 'date_payment')
            ->withExists('cycleSnapshots')
            ->get();

        // Quem saiu do grupo não tem mais o e-mail exposto aos membros; `created_by` fica.
        // Os membros já vieram no `with` acima: nenhuma consulta a mais.
        $grupos->each(function (Group $grupo) {
            if (! $grupo->members->contains('id', $grupo->created_by)) {
                $grupo->setRelation('creator', null);
            }
        });

        /*$user = auth()->user();

        // retorna apenas os grupos ativos do user
        return $user
            ->groups()               // relacionamento belongsToMany
            ->where('deleted', 0)    // aplica filtro extra
            ->get();*/

        return $grupos;
    }

    public const MAX_GROUPS_CREATED_PER_USER = 3;

    public function store(Request $request)
    {
        Log::info('User authenticated:', ['user' => auth()->user()]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'closing_day' => 'nullable|integer|between:1,31',
        ]);

        // Só conta o grupo de que o criador ainda é membro: sair de um grupo libera a vaga,
        // mas `created_by` fica (é ele que autoriza o criador a remover outros membros).
        $createdGroupsCount = Group::where('deleted', 0)
            ->where('created_by', auth()->id())
            ->whereHas('members', fn ($q) => $q->where('user_id', auth()->id()))
            ->count();

        if ($createdGroupsCount >= self::MAX_GROUPS_CREATED_PER_USER) {
            return response()->json([
                'message' => 'Você já atingiu o limite de '.self::MAX_GROUPS_CREATED_PER_USER.' grupos criados.',
            ], 422);
        }

        $data['create_date'] = now();
        $data['deleted'] = false;
        $data['created_by'] = auth()->id();

        $group = Group::create($data);
        Log::info('Grupo criado!');

        // Associa o usuário autenticado como membro do grupo
        $group->members()->attach(auth()->id());
        Log::info('Grupo criado e usuário associado como membro!');

        return response()->json($group, 201);
    }

    public function show($id)
    {
        $group = Group::with('creator:id,email')->findOrFail($id);
        $this->authorizeMembership($group);

        // Idem `index`: `created_by` fica, o e-mail de quem saiu do grupo não.
        if ($group->creator && ! $group->members()->where('user_id', $group->created_by)->exists()) {
            $group->setRelation('creator', null);
        }

        return $group;
    }

    public function update(Request $request, $id)
    {
        $group = Group::findOrFail($id);
        $this->authorizeMembership($group);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:255',
            'closing_day' => 'nullable|integer|between:1,31',
        ]);

        $group->update($data);

        return response()->json($group);
    }

    /**
     * Sem nenhuma competência já fechada (nenhuma linha em
     * ex_group_cycle_snapshots), o grupo nunca teve histórico a preservar —
     * exclusão física, em cascata. Com competência fechada, mantém o
     * comportamento antigo (exclusão lógica) para preservar despesas,
     * participantes, fechamentos e saldos.
     */
    public function destroy($id)
    {
        $group = Group::findOrFail($id);
        $this->authorizeMembership($group);

        if ($group->cycleSnapshots()->exists()) {
            $group->update(['deleted' => true]);

            return response()->json(['message' => 'Grupo marcado como deletado.']);
        }

        DB::transaction(function () use ($group) {
            $group->participations()->delete();

            foreach ($group->expenses()->get() as $expense) {
                $expense->quotas()->delete();
                $expense->payers()->detach();
                $expense->delete();
            }

            $group->members()->detach();
            $group->delete();
        });

        return response()->json(['message' => 'Grupo excluído permanentemente.']);
    }

    /**
     * 404 (não 403) para não confirmar a existência do grupo a quem não é membro.
     */
    private function authorizeMembership(Group $group): void
    {
        $isMember = $group->members()->where('user_id', auth()->id())->exists();

        abort_unless($isMember, 404);
    }
}
