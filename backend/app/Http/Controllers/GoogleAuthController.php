<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    /**
     * Devolve a URL de consentimento do Google para o usuário autenticado vincular a própria conta.
     */
    public function redirectUrl(Request $request)
    {
        $user = $request->user();

        $state = Crypt::encryptString(json_encode([
            'intent' => 'link',
            'user_id' => $user->id,
            'exp' => now()->addMinutes(5)->timestamp,
        ]));

        $url = Socialite::driver('google')
            ->stateless()
            ->with(['state' => $state])
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

        $state = $this->decodeState($request->query('state'));

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

    private function decodeState(?string $rawState): ?array
    {
        if (! $rawState) {
            return null;
        }

        try {
            $state = json_decode(Crypt::decryptString($rawState), true);
        } catch (Throwable $e) {
            return null;
        }

        if (! is_array($state) || ($state['exp'] ?? 0) < now()->timestamp) {
            return null;
        }

        return $state;
    }
}
