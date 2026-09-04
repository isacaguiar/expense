<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve a foto de perfil enviada pelo usuário. Fica FORA do grupo `jwt.auth`:
 * a tag `<img src>` não manda `Authorization: Bearer`. A autorização é a URL
 * assinada de curta duração (middleware `signed`) emitida pelo accessor
 * `User::avatar_url`, só dentro de contexto autenticado. Ver
 * `docs/sdd/decisions/ADR-005-download-arquivo-signed-url.md`.
 */
class UserPhotoController extends Controller
{
    public function show(string $userId): StreamedResponse
    {
        $user = User::findOrFail($userId);

        abort_if(blank($user->photo_path), 404);
        abort_unless(Storage::disk('local')->exists($user->photo_path), 404);

        return Storage::disk('local')->response($user->photo_path);
    }
}
