<?php

namespace App\Support;

use App\Models\Quota;
use App\Models\SettlementConfirmation;
use App\Models\SettlementProofEntry;
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

    /**
     * Regra de validação do campo `comprovante` em TODO ponto que grava comprovante (feature
     * 20261116-endurecer-upload-de-comprovante, TASK-643, D2-A). `mimes` olha o conteúdo e deixa de fora o SVG,
     * que a regra `image` do Laravel aceita e que, servido pela API, executaria script. Vem depois de
     * `nullable|` ou `required|`.
     */
    public const UPLOAD_RULE = 'mimes:jpg,jpeg,png,gif,webp|max:5120';

    /** Mensagem em português da recusa de formato (a regra `mimes` não tem tradução no projeto). */
    public const UPLOAD_MESSAGES = [
        'comprovante.mimes' => 'O comprovante deve ser uma imagem JPG, PNG, GIF ou WEBP.',
    ];

    /** Disco onde vivem os comprovantes anteriores a esta convenção. */
    private const LEGACY_DISK = 'public';

    /**
     * Grava o arquivo em `comprovantes/<groupId>/<uuid>.<ext>` no disco
     * privado e devolve o path relativo (para persistir na coluna).
     *
     * O disco `local` tem `'throw' => false` (`config/filesystems.php`): numa
     * falha de gravação (disco cheio, permissão) `storeAs` devolve `false` em
     * vez de lançar. Sem a guarda abaixo, quem chama seguiria em frente com um
     * `false` no lugar do path (`pay()` o gravava na coluna e a edição de
     * comprovante respondia 200 sem a foto). Aborta com 500 antes de qualquer
     * escrita no banco.
     */
    public static function store(UploadedFile $file, int $groupId): string
    {
        // A extensão sai do CONTEÚDO (`guessExtension()`), nunca do nome que o cliente mandou (feature
        // 20261116-endurecer-upload-de-comprovante, TASK-642, D4-A): o cliente podia gravar `uuid.svg` ou uma
        // extensão de 200+ caracteres que estourava a coluna `string(255)`.
        $extension = $file->guessExtension() ?: 'dat';

        $name = Str::uuid()->toString().'.'.$extension;

        $path = $file->storeAs("comprovantes/{$groupId}", $name, self::DISK);

        if ($path === false) {
            abort(500, 'Não foi possível gravar o comprovante.');
        }

        return $path;
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
     * O arquivo existe no disco certo? Falso para path vazio. Usado só para decidir se o resumo oferece o link
     * de um comprovante do histórico (feature 20261114-historico-de-comprovantes-e-justificativa, F9): sem o
     * arquivo, a entrada aparece sem link em vez de um link que daria 404.
     */
    public static function exists(?string $path): bool
    {
        if (! $path) {
            return false;
        }

        return Storage::disk(self::resolveDisk($path))->exists($path);
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

    /**
     * Remove o arquivo SÓ se nenhuma linha o referencia mais. Uma compra paga no
     * cadastro aponta várias quotas para o mesmo arquivo (feature
     * 20261027-pagamento-no-cadastro-despesa, R18): apagar pelo caminho, como
     * `delete` faz, deixaria as outras quotas sem o comprovante. Olha também o
     * comprovante de acerto do devedor e o histórico dos comprovantes do acerto (um
     * arquivo que só uma entrada de `ex_settlement_proof_entries` referencia não é
     * órfão, R2; feature 20261114-historico-de-comprovantes-e-justificativa), para
     * nunca apagar arquivo de outro fluxo.
     *
     * Chamar DEPOIS de o banco deixar de apontar para o arquivo, senão a própria
     * linha que está sendo atualizada ainda o referencia.
     */
    public static function deleteIfUnreferenced(?string $path): void
    {
        if (! $path) {
            return;
        }

        if (Quota::where('payment_proof_path', $path)->exists()
            || SettlementConfirmation::where('proof_path', $path)->exists()
            || SettlementProofEntry::where('proof_path', $path)->exists()) {
            return;
        }

        self::delete($path);
    }
}
