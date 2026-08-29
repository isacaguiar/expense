<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Armazena e localiza comprovantes de pagamento (do credor via `pay()` e do
 * devedor via `confirmSettlement()`), sempre em disco privado, com um
 * diretório por grupo: `comprovantes/<groupId>/<uuid>.<ext>`.
 *
 * `resolveDisk()` distingue o layout novo (path com segmento de grupo, disco
 * `local`) dos comprovantes legados gravados antes desta convenção (path plano
 * `comprovantes/xxx` ou `comprovantes-settlements/xxx`, disco `public`), para
 * que a rota de download sirva os dois sem migração de arquivo.
 */
class ProofStorage
{
    /** Disco privado onde todo comprovante novo é gravado. */
    public const DISK = 'local';

    /** Disco onde vivem os comprovantes anteriores a esta convenção. */
    private const LEGACY_DISK = 'public';

    /**
     * Grava o arquivo em `comprovantes/<groupId>/<uuid>.<ext>` no disco
     * privado e devolve o path relativo (para persistir na coluna).
     */
    public static function store(UploadedFile $file, int $groupId): string
    {
        $extension = $file->getClientOriginalExtension()
            ?: ($file->guessExtension() ?: 'dat');

        $name = Str::uuid()->toString().'.'.$extension;

        return $file->storeAs("comprovantes/{$groupId}", $name, self::DISK);
    }

    /**
     * Disco onde o path mora: `local` para o layout novo (por grupo),
     * `public` para comprovante legado.
     */
    public static function resolveDisk(string $path): string
    {
        return preg_match('#^comprovantes/\d+/#', $path) === 1
            ? self::DISK
            : self::LEGACY_DISK;
    }

    /**
     * Remove o arquivo do disco correto. Silencioso se o path é vazio ou o
     * arquivo já não existe.
     */
    public static function delete(?string $path): void
    {
        if (! $path) {
            return;
        }

        Storage::disk(self::resolveDisk($path))->delete($path);
    }
}
