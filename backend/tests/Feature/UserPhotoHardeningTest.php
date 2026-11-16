<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Endurecimento do upload e da entrega da foto de perfil (feature 20261116-endurecer-upload-de-foto-de-perfil,
 * item 156 do backlog): `nosniff` e CSP `sandbox` na rota assinada (TASK-647), extensão pelo conteúdo (TASK-648)
 * e formato sem SVG com mensagem em português (TASK-649). O `UserPhotoTest` não é editado.
 */
class UserPhotoHardeningTest extends TestCase
{
    use DatabaseTransactions;

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    private function upload(User $user, UploadedFile $file)
    {
        return $this->withToken($this->tokenFor($user))
            ->withHeader('Accept', 'application/json')
            ->post('/api/user/photo', ['foto' => $file]);
    }

    private function realPng(string $clientName): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($tmp, base64_decode(self::PNG));

        return new UploadedFile($tmp, $clientName, 'image/png', null, true);
    }

    // --- Cabeçalhos da foto servida (TASK-647, D4-A) ---

    public function test_the_signed_photo_route_sends_nosniff_and_a_sandboxing_csp_and_stays_inline(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $url = $this->upload($user, UploadedFile::fake()->image('eu.jpg'))->json('avatar_url');

        $response = $this->get($url);

        $response->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_an_old_svg_with_a_script_already_stored_is_served_contained(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $path = "avatares/{$user->id}/antigo.svg";
        Storage::disk('local')->put($path, self::SVG);
        $user->forceFill(['photo_path' => $path])->save();

        $response = $this->get($user->fresh()->avatar_url);

        $response->assertOk();
        $this->assertSame(self::SVG, $response->streamedContent());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_the_photo_route_still_refuses_an_unsigned_request(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->upload($user, UploadedFile::fake()->image('eu.jpg'))->assertOk();

        $this->get("/api/user/{$user->id}/photo")->assertStatus(403);
    }

    // --- Extensão pelo conteúdo (TASK-648, D3-A) ---

    public function test_a_huge_client_extension_is_ignored_and_the_file_is_named_by_its_content(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->upload($user, $this->realPng('a.'.str_repeat('x', 210)))->assertOk();

        $path = $user->fresh()->photo_path;
        $this->assertMatchesRegularExpression("#^avatares/{$user->id}/[0-9a-f-]{36}\.png$#", $path);
        $this->assertLessThan(255, strlen($path));
        Storage::disk('local')->assertExists($path);
    }

    public function test_an_executable_looking_client_extension_is_not_trusted(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->upload($user, $this->realPng('foto.exe'))->assertOk();

        $path = $user->fresh()->photo_path;
        $this->assertStringEndsWith('.png', $path);
        $this->assertStringNotContainsString('exe', $path);
    }

    public function test_a_regular_jpeg_keeps_a_jpg_extension(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->upload($user, UploadedFile::fake()->image('a.jpg'))->assertOk();

        $this->assertStringEndsWith('.jpg', $user->fresh()->photo_path);
    }
}
