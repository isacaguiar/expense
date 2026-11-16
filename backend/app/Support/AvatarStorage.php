<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Armazena e apaga a foto de perfil enviada pelo usuário, sempre em disco
 * privado, com um diretório por usuário: `avatares/<userId>/<uuid>.<ext>`.
 * Espelha `App\Support\ProofStorage`. A foto é servida pela rota assinada
 * `user.photo` (fora de `jwt.auth`, ver ADR-005), não por URL estática.
 */
class AvatarStorage
{
    /** Disco privado onde toda foto de perfil é gravada. */
    public const DISK = 'local';

    /**
     * Grava o arquivo em `avatares/<userId>/<uuid>.<ext>` no disco privado e
     * devolve o path relativo (para persistir em `ex_users.photo_path`).
     */
    public static function store(UploadedFile $file, int $userId): string
    {
        // A extensão sai do CONTEÚDO (`guessExtension()`), nunca do nome que o cliente mandou (feature
        // 20261116-endurecer-upload-de-foto-de-perfil, TASK-648, D3-A): o cliente podia gravar `uuid.svg`,
        // `uuid.html` ou uma extensão de 200+ caracteres que estourava `ex_users.photo_path`.
        $extension = $file->guessExtension() ?: 'dat';

        $name = Str::uuid()->toString().'.'.$extension;

        return $file->storeAs("avatares/{$userId}", $name, self::DISK);
    }

    /**
     * Remove o arquivo do disco. Silencioso se o path é vazio ou o arquivo
     * já não existe.
     */
    public static function delete(?string $path): void
    {
        if (! $path) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }
}
