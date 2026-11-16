<?php

namespace Tests\Unit;

use App\Support\ProofStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\FailsToWriteProofs;
use Tests\TestCase;

class ProofStorageTest extends TestCase
{
    use FailsToWriteProofs;

    public function test_store_aborta_com_500_quando_o_disco_nao_consegue_gravar(): void
    {
        $this->failProofWrites();

        try {
            ProofStorage::store(UploadedFile::fake()->image('comprovante.jpg'), 42);
            $this->fail('store deveria abortar quando o disco devolve false');
        } catch (HttpException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertSame('Não foi possível gravar o comprovante.', $e->getMessage());
        }
    }

    public function test_store_grava_no_diretorio_do_grupo_no_disco_privado(): void
    {
        Storage::fake('local');

        $path = ProofStorage::store(UploadedFile::fake()->image('comprovante.jpg'), 42);

        $this->assertMatchesRegularExpression(
            '#^comprovantes/42/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.jpg$#',
            $path
        );
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    /** PNG 1x1 verdadeiro: o tipo detectado pelo conteúdo é `png` seja qual for o nome que o cliente manda. */
    private function realPng(string $clientName): UploadedFile
    {
        $file = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        return new UploadedFile($file, $clientName, 'image/png', null, true);
    }

    // --- Extensão pelo conteúdo (feature 20261116-endurecer-upload-de-comprovante, TASK-642, D4-A) ---

    public function test_store_ignores_a_huge_client_extension_and_names_the_file_by_its_content(): void
    {
        Storage::fake('local');

        $path = ProofStorage::store($this->realPng('a.'.str_repeat('x', 210)), 42);

        $this->assertMatchesRegularExpression('#^comprovantes/42/[0-9a-f-]{36}\.png$#', $path);
        $this->assertLessThan(255, strlen($path));
        Storage::disk('local')->assertExists($path);
    }

    public function test_store_does_not_trust_an_executable_looking_client_extension(): void
    {
        Storage::fake('local');

        $path = ProofStorage::store($this->realPng('foto.exe'), 42);

        $this->assertStringEndsWith('.png', $path);
        $this->assertStringNotContainsString('exe', $path);
    }

    public function test_store_never_uses_the_client_extension_even_for_content_of_an_unknown_type(): void
    {
        Storage::fake('local');
        $file = tempnam(sys_get_temp_dir(), 'bin');
        file_put_contents($file, random_bytes(64));

        $path = ProofStorage::store(new UploadedFile($file, 'x.zzz', 'application/octet-stream', null, true), 42);

        // Conteúdo binário genérico: o PHP devolve `bin` (ou, sem palpite, a reserva `dat`); nunca `zzz`.
        $this->assertMatchesRegularExpression('#\.(bin|dat)$#', $path);
        $this->assertStringNotContainsString('zzz', $path);
    }

    public function test_resolve_disk_distingue_layout_novo_de_legado(): void
    {
        $this->assertSame('local', ProofStorage::resolveDisk('comprovantes/42/abc.jpg'));
        $this->assertSame('public', ProofStorage::resolveDisk('comprovantes/abc.jpg'));
        $this->assertSame('public', ProofStorage::resolveDisk('comprovantes-settlements/abc.jpg'));
    }

    public function test_delete_remove_do_disco_resolvido_e_ignora_path_vazio(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $nova = ProofStorage::store(UploadedFile::fake()->image('c.jpg'), 7);
        Storage::disk('public')->put('comprovantes/legado.jpg', 'conteudo');

        ProofStorage::delete($nova);
        ProofStorage::delete('comprovantes/legado.jpg');
        ProofStorage::delete(null);

        Storage::disk('local')->assertMissing($nova);
        Storage::disk('public')->assertMissing('comprovantes/legado.jpg');
    }
}
