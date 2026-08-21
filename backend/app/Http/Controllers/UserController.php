<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    /**
     * Atualiza a chave Pix do usuário autenticado
     */
    public function atualizarPix(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pix' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user(); // Recupera o usuário autenticado

        $user->pix = $request->pix;
        $user->save();

        return response()->json([
            'message' => 'Chave Pix atualizada com sucesso.',
            'pix' => $user->pix,
        ]);
    }

    /**
     * Atualiza os dados de perfil (nome, e-mail, pix) do usuário autenticado
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:ex_users,email,'.$user->id,
            'pix' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->name = $request->name;
        $user->email = $request->email;
        $user->pix = $request->pix;
        $user->save();

        return response()->json([
            'message' => 'Perfil atualizado com sucesso.',
            'name' => $user->name,
            'email' => $user->email,
            'pix' => $user->pix,
        ]);
    }
}
