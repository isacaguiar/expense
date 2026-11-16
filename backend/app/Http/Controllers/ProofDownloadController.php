<?php

namespace App\Http\Controllers;

use App\Models\Quota;
use App\Models\SettlementConfirmation;
use App\Models\SettlementProofEntry;
use App\Support\ProofStorage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve o arquivo de comprovante de pagamento. Fica FORA do grupo `jwt.auth`:
 * a aba do browser aberta pelo link `<a target="_blank">` não manda
 * `Authorization: Bearer`. A autorização é a URL assinada de curta duração
 * (middleware `signed`), que só é emitida dentro de contexto autenticado e com
 * checagem de membership (accessors `Quota::payment_proof_url` /
 * `SettlementConfirmation::proof_url`), somada à revalidação recurso×grupo
 * aqui. Ver `docs/sdd/decisions/ADR-005-download-arquivo-signed-url.md`.
 */
class ProofDownloadController extends Controller
{
    public function show(string $groupId, string $type, string $id): StreamedResponse
    {
        $path = $this->resolvePath((int) $groupId, $type, $id);

        $disk = ProofStorage::resolveDisk($path);

        abort_unless(Storage::disk($disk)->exists($path), 404);

        $response = Storage::disk($disk)->response($path);

        // Feature 20261116-endurecer-upload-de-comprovante (TASK-641, D3-A): o arquivo é conteúdo de usuário
        // servido na origem da API. `nosniff` impede o navegador de reinterpretar o tipo e o CSP com `sandbox`
        // (sem `allow-scripts`) neutraliza script e acesso à origem, inclusive num SVG gravado antes da feature.
        // Só aqui (R4): não é cabeçalho global. `inline` continua, para "Ver comprovante" abrir na aba.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }

    /**
     * Path do comprovante, ou 404 se o `type` é inválido (`quota`, `settlement` e
     * `settlement_history` são os válidos), o recurso não existe, não pertence a
     * `$groupId`, ou não tem comprovante — os quatro casos respondem igual de
     * propósito, para não permitir enumerar id de outro grupo.
     */
    private function resolvePath(int $groupId, string $type, string $id): string
    {
        $path = null;

        if ($type === 'quota') {
            $quota = Quota::with('expense')->find($id);
            if ($quota && (int) $quota->expense?->group_id === $groupId) {
                $path = $quota->payment_proof_path;
            }
        } elseif ($type === 'settlement') {
            $confirmation = SettlementConfirmation::find($id);
            if ($confirmation && (int) $confirmation->group_id === $groupId) {
                $path = $confirmation->proof_path;
            }
        } elseif ($type === 'settlement_history') {
            // Entrada do histórico dos comprovantes do acerto (feature 20261114-historico-de-comprovantes-e-
            // justificativa, R7): a assinatura é a autorização e a URL só é emitida a quem é parte do acerto
            // (`SettlementProofEntry::proofUrl`, TASK-624); aqui só se revalida o par entrada × grupo.
            $entry = SettlementProofEntry::find($id);
            if ($entry && (int) $entry->group_id === $groupId) {
                $path = $entry->proof_path;
            }
        } else {
            abort(404);
        }

        abort_if(blank($path), 404);

        return $path;
    }
}
