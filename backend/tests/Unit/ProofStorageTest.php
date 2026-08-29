<?php

namespace Tests\Unit;

use App\Support\ProofStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProofStorageTest extends TestCase
{
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
