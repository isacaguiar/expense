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
     * Regra de validação do campo `foto` no upload (feature 20261116-endurecer-upload-de-foto-de-perfil,
     * TASK-649, D1-A e D2-A). `mimes` olha o conteúdo e deixa de fora o SVG, que a regra `image` do Laravel
     * aceita e que, servido pela API, executaria script. Vem depois de `required|`. Própria do avatar, separada
     * da do comprovante (`ProofStorage::UPLOAD_RULE`).
     */
    public const UPLOAD_RULE = 'mimes:jpg,jpeg,png,gif,webp|max:5120';

    /** Mensagem em português da recusa de formato (o projeto não tem `lang/`; a `mimes` padrão sairia em inglês). */
    public const UPLOAD_MESSAGES = [
        'foto.mimes' => 'A foto deve ser uma imagem JPG, PNG, GIF ou WEBP.',
    ];

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

        $path = $file->storeAs("avatares/{$userId}", $name, self::DISK);

        // O disco `local` tem `'throw' => false` (`config/filesystems.php`): numa falha de gravação (disco cheio,
        // permissão) `storeAs` devolve `false`, e o tipo de retorno `string` sem `strict_types` o converteria em
        // `''`, que `uploadPhoto` gravaria em `photo_path` (feature 20261123-avatar-delete-confinado-e-troca-de-foto-segura,
        // TASK-674, D4-A). Aborta como `ProofStorage::store`.
        if ($path === false) {
            abort(500, 'Não foi possível gravar a foto.');
        }

        return $path;
    }

    /**
     * Remove o arquivo do disco, só dentro de `avatares/` (feature
     * 20261123-avatar-delete-confinado-e-troca-de-foto-segura, TASK-673, D1-A).
     * Silencioso (no-op) se o path é vazio, se o arquivo já não existe, se o path
     * não começa por `avatares/` ou se tem um segmento `..`: um `photo_path` que
     * não venha do `store` não pode remover arquivo de outra área.
     */
    public static function delete(?string $path): void
    {
        if (! $path || ! self::isInsideAvatars($path)) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    /** `avatares/...` sem segmento `..` (separado por `/` ou `\`); `foto..png` é nome válido. */
    private static function isInsideAvatars(string $path): bool
    {
        return str_starts_with($path, 'avatares/')
            && ! in_array('..', preg_split('#[/\\\\]#', $path), true);
    }
}
