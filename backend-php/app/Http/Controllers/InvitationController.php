<?php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Cache;


class InvitationController extends Controller
{
    // Envia convite
    public function invite(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'message' => 'nullable|string|max:1000',
        ]);

        $token = bin2hex(random_bytes(16)); // senha temporária aleatória
        $hashedPassword = Hash::make($token);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $hashedPassword,
            'email_verified_at' => null,
            'invited_by' => auth()->id(), 
        ]);

        Mail::send('emails.invitation', [
            'inviterName' => auth()->user()->name,
            'message' => $request->message,
            'activationLink' => url("/aceitar-convite?email={$user->email}&token={$token}")
        ], function ($mail) use ($user) {
            $mail->to($user->email)->subject('Convite para a plataforma Despesa Compartilhada da Novemax');
        });

        return response()->json(['message' => 'Convite enviado com sucesso.']);
    }

    // Verifica e ativa
    public function verify(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'token' => 'required|string',
            'password' => 'required|string|min:6|confirmed', // campo "password_confirmation" também obrigatório
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'Usuário não encontrado.'], 404);
        }

        $tokenFromCache = Cache::get('password-reset-token:' . $user->email);
        $isInvitationToken = Hash::check($request->token, $user->password);
        $isResetTokenValid = $tokenFromCache && $tokenFromCache === $request->token;

        if (!$isInvitationToken && !$isResetTokenValid) {
            return response()->json(['message' => 'Token inválido ou expirado.'], 401);
        }

        // Atualiza senha final e confirma e-mail
        $user->password = Hash::make($request->password);
        $user->email_verified_at = $user->email_verified_at ?? now();
        $user->save();

        // Limpa token temporário se era recuperação de senha
        if ($isResetTokenValid) {
            Cache::forget('password-reset-token:' . $user->email);
        }

        // Envia e-mail de confirmação
        Mail::send('emails.activation-confirmation', [
            'user' => $user
        ], function ($mail) use ($user) {
            $mail->to($user->email)->subject('Conta ativada com sucesso');
        });

        return response()->json(['message' => 'Senha definida com sucesso.']);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->email)->first();

        // Limitar múltiplas requisições (ex: 1 por 15 min)
        $cacheKeyRateLimit = 'password-reset-rate:' . $user->email;
        if (Cache::has($cacheKeyRateLimit)) {
            return response()->json([
                'message' => 'Você já solicitou recuperação recentemente. Aguarde alguns minutos.'
            ], 429); // Too Many Requests
        }

        // Gerar token
        $token = bin2hex(random_bytes(16));
        $user->password = Hash::make($token);
        $user->save();

        // Definir tempo de validade (ex: 60 minutos)
        $cacheKeyToken = 'password-reset-token:' . $user->email;
        Cache::put($cacheKeyToken, $token, now()->addMinutes(60));
        Cache::put($cacheKeyRateLimit, true, now()->addMinutes(15)); // bloqueia por 15 min

        $resetLink = url("/recuperar-senha?email={$user->email}&token={$token}");

        Mail::send('emails.password-reset', [
            'user' => $user,
            'resetLink' => $resetLink
        ], function ($mail) use ($user) {
            $mail->to($user->email)
                ->subject('Recuperação de senha - Novemax');
        });

        return response()->json(['message' => 'Link de recuperação enviado para seu e-mail.']);
    }

}
