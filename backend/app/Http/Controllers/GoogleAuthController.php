<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;

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
}
