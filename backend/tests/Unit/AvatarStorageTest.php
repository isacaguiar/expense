<?php

namespace Tests\Unit;

use App\Support\AvatarStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\FailsToWriteProofs;
use Tests\TestCase;

/**
 * `AvatarStorage::delete` só apaga dentro de `avatares/` (feature
 * 20261123-avatar-delete-confinado-e-troca-de-foto-segura, TASK-673, D1-A): um
 * `photo_path` que não venha do `store` não pode remover arquivo de outra área.
 */
class AvatarStorageTest extends TestCase
{
    use FailsToWriteProofs;

    public function test_store_aborta_com_500_quando_o_disco_nao_consegue_gravar(): void
    {
        $this->failProofWrites();

        try {
            AvatarStorage::store(UploadedFile::fake()->image('eu.jpg'), 7);
            $this->fail('store deveria abortar quando o disco devolve false');
        } catch (HttpException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertSame('Não foi possível gravar a foto.', $e->getMessage());
        }
    }

    public function test_store_grava_em_avatares_do_usuario_quando_o_disco_funciona(): void
    {
        Storage::fake('local');

        $path = AvatarStorage::store(UploadedFile::fake()->image('eu.jpg'), 7);

        $this->assertStringStartsWith('avatares/7/', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_delete_apaga_o_arquivo_dentro_de_avatares(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('avatares/7/foto.png', 'x');

        AvatarStorage::delete('avatares/7/foto.png');

        Storage::disk('local')->assertMissing('avatares/7/foto.png');
    }

    public function test_delete_nao_apaga_arquivo_fora_de_avatares(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('comprovantes/1/recibo.png', 'x');

        AvatarStorage::delete('comprovantes/1/recibo.png');

        Storage::disk('local')->assertExists('comprovantes/1/recibo.png');
    }

    public function test_delete_nao_confunde_um_prefixo_parecido_com_avatares(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('avatares-antigos/7/foto.png', 'x');

        AvatarStorage::delete('avatares-antigos/7/foto.png');

        Storage::disk('local')->assertExists('avatares-antigos/7/foto.png');
    }

    public function test_delete_recusa_caminho_que_volta_de_diretorio_com_barra(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('outro.txt', 'x');

        AvatarStorage::delete('avatares/../outro.txt');

        Storage::disk('local')->assertExists('outro.txt');
    }

    public function test_delete_recusa_caminho_que_volta_de_diretorio_com_contrabarra(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('avatares/7/foto.png', 'x');

        AvatarStorage::delete('avatares/7\\..\\7/foto.png');

        Storage::disk('local')->assertExists('avatares/7/foto.png');
    }

    public function test_delete_aceita_dois_pontos_dentro_do_nome_do_arquivo(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('avatares/7/foto..png', 'x');

        AvatarStorage::delete('avatares/7/foto..png');

        Storage::disk('local')->assertMissing('avatares/7/foto..png');
    }

    public function test_delete_com_caminho_vazio_ou_nulo_nao_faz_nada(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('avatares/7/foto.png', 'x');

        AvatarStorage::delete(null);
        AvatarStorage::delete('');

        Storage::disk('local')->assertExists('avatares/7/foto.png');
    }
}
