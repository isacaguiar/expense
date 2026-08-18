<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use App\Mail\UserInvitedMail;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class GroupMemberController extends Controller
{
    // lista membros de um grupo
    public function index($groupId)
    {
        //$group = Group::with('members')->findOrFail($groupId);
        /*return response()->json([
            //'members' => $group->members
        ], 200);*/
		$group = Group::with('members')->findOrFail($groupId);
        return response()->json($group->members);
    }


    public function store(Request $request, $groupId)
    {
        // 1) Valida só o formato do e‑mail
        $data = $request->validate([
            'email' => 'required|email',
        ]);

        // 2) Busca o grupo
        $group = Group::findOrFail($groupId);

        // 3) Tenta carregar o usuário
        $user = User::where('email', $data['email'])->first();
        $isNewUser = false;

        if (!$user) {
            $isNewUser = true;

            // 3.1) Cria senha aleatória e preenche name (pode ajustar)
            $password = Str::random(10);
            $user = User::create([
                'name'     => explode('@', $data['email'])[0],
                'email'    => $data['email'],
                'password' => bcrypt($password),
            ]);

            // 3.2) Gera token de reset para montar link de convite
            //$token = Password::broker()->createToken($user);
            $token = Password::getRepository()->create($user);

            // 3.3) Dispara e‑mail de convite
            Mail::to($user->email)
                ->send(new UserInvitedMail($user, $group, $token));
        }

        // 4) Evita duplicata no pivot
        if ($group->members()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'Usuário já é membro deste grupo.'
            ], 409);
        }

        // 5) Associa ao grupo
        $group->members()->attach($user->id);

        return response()->json([
            'message' => $isNewUser
                ? 'Usuário criado e convidado com sucesso.'
                : 'Membro adicionado com sucesso.'
        ], 201);
    }
}
