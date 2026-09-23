<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    private const STATE_CACHE_PREFIX = 'google_oauth_state:';

    private const STATE_TTL_MINUTES = 5;

    private const LOGIN_CODE_CACHE_PREFIX = 'google_login_code:';

    private const LOGIN_CODE_TTL_MINUTES = 1;

    /**
     * Redireciona direto para o consentimento do Google, para o botão "Google" da tela de
     * login (usuário ainda não autenticado — sem chamada XHR prévia, ao contrário de redirectUrl()).
     */
    public function loginRedirect()
    {
        $token = Str::random(40);

        Cache::put(self::STATE_CACHE_PREFIX.$token, [
            'intent' => 'login',
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        $url = Socialite::driver('google')
            ->stateless()
            ->with(['state' => $token])
            ->redirect()
            ->getTargetUrl();

        return redirect()->away($url);
    }

    /**
     * Devolve a URL de consentimento do Google para o usuário autenticado vincular a própria conta.
     */
    public function redirectUrl(Request $request)
    {
        $user = $request->user();

        $token = Str::random(40);

        Cache::put(self::STATE_CACHE_PREFIX.$token, [
            'intent' => 'link',
            'user_id' => $user->id,
        ], now()->addMinutes(self::STATE_TTL_MINUTES));

        $url = Socialite::driver('google')
            ->stateless()
            ->with(['state' => $token])
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    /**
     * Callback público do Google. Atende intent=link (vínculo de conta a partir de Minha Conta)
     * e intent=login (autenticação via Google na tela de login).
     */
    public function callback(Request $request)
    {
        $frontendUrl = config('services.frontend_url');

        $state = $this->pullState($request->query('state'));

        if ($state === null) {
            Log::warning('[google-link] state ausente/desconhecido/expirado no cache -> linked=error');

            return redirect()->away("{$frontendUrl}/profile?linked=error");
        }

        $intent = $state['intent'] ?? null;

        if ($intent === 'login') {
            return $this->handleLoginCallback($frontendUrl);
        }

        if ($intent !== 'link') {
            Log::warning('[google-link] intent != link -> 501', ['intent' => $intent]);

            return response()->json(['message' => 'Login via Google ainda não implementado.'], 501);
        }

        $user = User::find($state['user_id'] ?? null);

        if (! $user) {
            Log::warning('[google-link] user_id do state nao existe -> linked=error', ['user_id' => $state['user_id'] ?? null]);

            return redirect()->away("{$frontendUrl}/profile?linked=error");
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            Log::warning('[google-link] Socialite falhou ao obter usuario Google -> linked=error', [
                'user_id' => $user->id,
                'exception' => get_class($e).': '.$e->getMessage(),
            ]);

            return redirect()->away("{$frontendUrl}/profile?linked=error");
        }

        Log::info('[google-link] Google user obtido', ['user_id' => $user->id, 'google_id' => $googleUser->getId()]);

        $user->google_id = $googleUser->getId();
        $user->avatar_url = $googleUser->getAvatar();

        try {
            $user->save();
        } catch (QueryException $e) {
            Log::warning('[google-link] save falhou (QueryException) -> linked=error', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);

            return redirect()->away("{$frontendUrl}/profile?linked=error");
        }

        Log::info('[google-link] vinculo concluido -> linked=success', ['user_id' => $user->id, 'google_id' => $user->google_id]);

        return redirect()->away("{$frontendUrl}/profile?linked=success");
    }

    /**
     * Troca o código de uso único (gerado por handleLoginCallback) pelo JWT de fato. Pública e
     * sem autenticação de propósito: é exatamente o que entrega a sessão a quem ainda não tem uma.
     */
    public function exchangeLoginCode(Request $request)
    {
        $token = Cache::pull(self::LOGIN_CODE_CACHE_PREFIX.$request->query('code'));

        if (! $token) {
            return response()->json(['message' => 'Código inválido ou expirado.'], 401);
        }

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ]);
    }

    /**
     * Resolve intent=login: acha o usuário pelo google_id, senão pelo e-mail (auto-vínculo,
     * assume que o Google só devolve e-mail verificado), senão cria uma conta nova sem senha
     * local. Emite o mesmo JWT que o login por e-mail/senha usa e devolve um código de uso
     * único — nunca o token cru na query string (evita expor em histórico/logs/Referer).
     */
    private function handleLoginCallback(string $frontendUrl)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            Log::warning('[google-login] Socialite falhou ao obter usuario Google -> login error', [
                'exception' => get_class($e).': '.$e->getMessage(),
            ]);

            return redirect()->away("{$frontendUrl}/login?google_error=1");
        }

        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $googleUser->getEmail())->first();

        try {
            if ($user) {
                $user->google_id = $googleUser->getId();
                $user->avatar_url = $user->avatar_url ?: $googleUser->getAvatar();
                $user->email_verified_at = $user->email_verified_at ?? now();
                $user->save();
            } else {
                $user = new User([
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'password' => null,
                ]);
                $user->google_id = $googleUser->getId();
                $user->avatar_url = $googleUser->getAvatar();
                $user->email_verified_at = now();
                $user->save();
            }
        } catch (QueryException $e) {
            Log::warning('[google-login] save falhou (QueryException) -> login error', [
                'exception' => $e->getMessage(),
            ]);

            return redirect()->away("{$frontendUrl}/login?google_error=1");
        }

        Log::info('[google-login] login concluido', ['user_id' => $user->id, 'google_id' => $user->google_id]);

        $token = auth('api')->login($user);

        $code = Str::random(40);
        Cache::put(self::LOGIN_CODE_CACHE_PREFIX.$code, $token, now()->addMinutes(self::LOGIN_CODE_TTL_MINUTES));

        return redirect()->away("{$frontendUrl}/login?google_code={$code}");
    }

    /**
     * Recupera e consome (uso único) o contexto de vínculo guardado pelo redirectUrl.
     */
    private function pullState(?string $token): ?array
    {
        if (! $token) {
            return null;
        }

        $state = Cache::pull(self::STATE_CACHE_PREFIX.$token);

        return is_array($state) ? $state : null;
    }
}
