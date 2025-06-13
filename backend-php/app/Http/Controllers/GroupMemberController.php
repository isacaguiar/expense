<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupMemberController extends Controller
{
    public function index($groupId)
    {
        $group = Group::with('members')->findOrFail($groupId);
        return response()->json($group->members);
    }

    public function store(Request $request, $groupId)
    {
        $group = Group::findOrFail($groupId);

        if ($request->has('user_ids')) {
            // Validação para múltiplos IDs
            $request->validate([
                'user_ids' => 'required|array',
                'user_ids.*' => 'exists:ex_users,id',
            ]);

            $group->members()->syncWithoutDetaching($request->user_ids);

            return response()->json(['message' => 'Usuários adicionados com sucesso']);
        }

        if ($request->has('user_id')) {
            // Validação para um único ID
            $request->validate([
                'user_id' => 'required|exists:ex_users,id',
            ]);

            if ($group->members()->where('user_id', $request->user_id)->exists()) {
                return response()->json(['message' => 'Usuário já é membro do grupo'], 422);
            }

            $group->members()->attach($request->user_id);

            return response()->json(['message' => 'Usuário adicionado com sucesso']);
        }

        return response()->json(['message' => 'Nenhum usuário informado'], 400);
    }


    public function destroy($groupId, $userId)
    {
        $group = Group::findOrFail($groupId);
        $group->members()->detach($userId);

        return response()->json(['message' => 'Membro removido com sucesso']);
    }
}
