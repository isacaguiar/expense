<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        return response()->json([
            'message' => 'Este endpoint foi descontinuado. Use /api/pre-register.',
        ], 410);
    }

    public function login(Request $request)
    {
        Log::info('Tentativa de login recebida', [
            'email' => $request->email,
        ]);

        $credentials = $request->only('email', 'password');

        /*if (!$token = auth()->attempt($credentials)) {
            Log::warning('Falha no login: credenciais inválidas', ['email' => $credentials['email'] ?? null]);
            return response()->json(['error' => 'Não autorizado'], 401);
        }*/
        if (! $token = Auth::guard('api')->attempt($credentials)) {
            Log::warning('Falha no login: credenciais inválidas', ['email' => $credentials['email'] ?? null]);

            return response()->json(['error' => 'Não autorizado'], 401);
        }

        if (Auth::guard('api')->user()->email_verified_at === null) {
            Log::warning('Falha no login: e-mail não verificado', ['email' => $credentials['email'] ?? null]);
            Auth::guard('api')->logout();

            return response()->json([
                'error' => 'E-mail não verificado. Use "Esqueci minha senha" para confirmar seu e-mail e definir uma nova senha.',
            ], 403);
        }

        Log::info('Login bem-sucedido para o usuário', [
            'user_id' => Auth::guard('api')->user()->id,
            'email' => Auth::guard('api')->user()->email,
        ]);

        return $this->respondWithToken($token);
    }

    public function me()
    {
        return response()->json(auth('api')->user());
    }

    protected function respondWithToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
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
            'user' => $user,
        ]);
    }
}
