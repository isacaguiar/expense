<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    private const STATE_CACHE_PREFIX = 'google_oauth_state:';

    private const STATE_TTL_MINUTES = 5;

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
     * Callback público do Google. Hoje só atende intent=link (vínculo de conta a partir de Minha
     * Conta); intent de login fica para a feature login-social-google estender este mesmo método.
     */
    public function callback(Request $request)
    {
        $frontendUrl = config('services.frontend_url');

        $state = $this->pullState($request->query('state'));

        if ($state === null) {
            return redirect()->away("{$frontendUrl}/profile?linked=error");
        }

        if (($state['intent'] ?? null) !== 'link') {
            return response()->json(['message' => 'Login via Google ainda não implementado.'], 501);
        }

        $user = User::find($state['user_id'] ?? null);

        if (! $user) {
            return redirect()->away("{$frontendUrl}/profile?linked=error");
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            return redirect()->away("{$frontendUrl}/profile?linked=error");
        }

        $user->google_id = $googleUser->getId();
        $user->avatar_url = $googleUser->getAvatar();

        try {
            $user->save();
        } catch (QueryException $e) {
            return redirect()->away("{$frontendUrl}/profile?linked=error");
        }

        return redirect()->away("{$frontendUrl}/profile?linked=success");
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
