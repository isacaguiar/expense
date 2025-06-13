<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request) {
        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:ex_users',
            'password' => 'required|min:6',
            'role' => 'nullable|string'
        ]);

        $user = User::create([
            'name' => $request->name,
            'email'=> $request->email,
            'password' => Hash::make($request->password)
        ]);

        return response()->json(['message' => 'Usuário criado com sucesso'], 201);
    }

    public function login(Request $request) {
        Log::info('Tentativa de login recebida', [
            'email' => $request->email
        ]);

        $credentials = $request->only('email', 'password');
        Log::debug('Credenciais extraídas', $credentials);

        /*if (!$token = auth()->attempt($credentials)) {
            Log::warning('Falha no login: credenciais inválidas', $credentials);
            return response()->json(['error' => 'Não autorizado'], 401);
        }*/
        if (!$token = Auth::guard('api')->attempt($credentials)) {
            Log::warning('Falha no login: credenciais inválidas', $credentials);
            return response()->json(['error' => 'Não autorizado'], 401);
        }

        Log::info('Login bem-sucedido para o usuário', [
            'user_id' => Auth::guard('api')->user()->id,
            'email' => Auth::guard('api')->user()->email,
        ]);

        return $this->respondWithToken($token);
    }


    public function me() {
        return response()->json(auth('api')->user());
    }

    protected function respondWithToken($token) {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60
        ]);
    }

    public function logout()
    {
        Log::info('Logout do usuário', [
            'user_id' => auth()->user()->id,
            'email' => auth()->user()->email,
        ]);
        auth()->logout();

        return response()->json(['message' => 'Logout realizado com sucesso.']);
    }

    public function dashboard()
    {
        $user = auth()->user();

        return response()->json([
            'message' => 'Dashboard carregado com sucesso',
            'user' => $user
        ]);
    }

}
