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
            'pix' => $user->pix
        ]);
    }
}
