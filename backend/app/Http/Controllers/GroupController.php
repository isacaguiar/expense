<?php

namespace App\Http\Controllers;

use App\Models\Group;
use Illuminate\Http\Request;
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
            ->get();

        /*$user = auth()->user();

        // retorna apenas os grupos ativos do user
        return $user
            ->groups()               // relacionamento belongsToMany
            ->where('deleted', 0)    // aplica filtro extra
            ->get();*/

        return $grupos;
    }

    public function store(Request $request)
    {
        Log::info('User authenticated:', ['user' => auth()->user()]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'closing_day' => 'nullable|integer|between:1,31',
        ]);

        $data['create_date'] = now();
        $data['deleted'] = false;

        $group = Group::create($data);
        Log::info('Grupo criado!');

        // Associa o usuário autenticado como membro do grupo
        $group->members()->attach(auth()->id());
        Log::info('Grupo criado e usuário associado como membro!');

        return response()->json($group, 201);
    }

    public function show($id)
    {
        $group = Group::findOrFail($id);
        $this->authorizeMembership($group);

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

    public function destroy($id)
    {
        $group = Group::findOrFail($id);
        $this->authorizeMembership($group);

        $group->update(['deleted' => true]);

        return response()->json(['message' => 'Grupo marcado como deletado.']);
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
