<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Formato aceito no upload de comprovante (feature 20261116-endurecer-upload-de-comprovante, plan §3,
 * TASK-643, D2-A): jpg, jpeg, png, gif e webp pelo CONTEÚDO; SVG (que a regra `image` do Laravel aceita) e
 * qualquer outro tipo dão 422 em português, nos cinco pontos que gravam comprovante e sem gravar arquivo (R3).
 */
class ProofUploadFormatTest extends TestCase
{
    use DatabaseTransactions;

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const MESSAGE = 'O comprovante deve ser uma imagem JPG, PNG, GIF ou WEBP.';

    private User $user;

    private User $other;

    private Group $group;

    private Expense $expense;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        [$this->user, $this->other] = User::factory()->count(2)->create()->all();
        $this->group = Group::create(['name' => 'Grupo']);
        $this->group->members()->attach([$this->user->id, $this->other->id]);
        $this->expense = Expense::create([
            'create_date' => now(), 'date_payment' => now()->startOfMonth()->addDays(5)->toDateString(),
            'description' => 'Despesa', 'expense_type' => 'IN_CASH', 'installments' => 1, 'total_value' => 100,
            'group_id' => $this->group->id, 'user_creator_id' => $this->user->id, 'user_payer_id' => $this->user->id,
            'deleted' => false,
        ]);
        $this->expense->payers()->sync([$this->user->id, $this->other->id]);
        $this->expense->quotas()->create(['date_expected' => now()->startOfMonth()->addDays(5)->toDateString(), 'number' => 1, 'paid' => false, 'value_quota' => 100]);
    }

    private function file(string $kind): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        match ($kind) {
            'svg' => file_put_contents($tmp, self::SVG),
            'svg-as-png' => file_put_contents($tmp, self::SVG),
            default => file_put_contents($tmp, base64_decode(self::PNG)),
        };
        $name = match ($kind) {
            'svg' => 'comprovante.svg',
            'svg-as-png' => 'comprovante.png',
            default => 'comprovante.png',
        };
        $mime = $kind === 'png' ? 'image/png' : 'image/svg+xml';

        return new UploadedFile($tmp, $name, $mime, null, true);
    }

    /** @return array<string, array{0: string, 1: string}> nome => [método, caminho] */
    private function endpoints(): array
    {
        $g = $this->group->id;
        $e = $this->expense->id;

        return [
            'store' => ['/api/expenses', 'post'],
            'pay' => ["/api/expenses/{$e}/pay", 'post'],
            'payment-proof' => ["/api/expenses/{$e}/payment-proof", 'post'],
            'confirm' => ["/api/groups/{$g}/settlements/confirm", 'post'],
            'complement' => ["/api/groups/{$g}/settlements/complement", 'post'],
        ];
    }

    private function send(string $url, UploadedFile $file, array $extra = [])
    {
        return $this->withToken(auth('api')->login($this->user))
            ->withHeader('Accept', 'application/json')
            ->post($url, ['comprovante' => $file, 'group_id' => $this->group->id, 'to_user_id' => $this->other->id, 'justificativa' => 'x'] + $extra);
    }

    public function test_every_upload_point_refuses_an_svg_with_a_portuguese_message_and_stores_nothing(): void
    {
        foreach ($this->endpoints() as $name => [$url]) {
            $response = $this->send($url, $this->file('svg'));

            $this->assertSame(422, $response->getStatusCode(), $name);
            $this->assertArrayHasKey('comprovante', $response->json('errors') ?? [], $name.' '.$response->getContent());
            $this->assertSame(self::MESSAGE, $response->json('errors.comprovante.0'), $name);
        }

        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_every_upload_point_refuses_an_svg_renamed_to_png(): void
    {
        foreach ($this->endpoints() as $name => [$url]) {
            $response = $this->send($url, $this->file('svg-as-png'));

            $this->assertSame(422, $response->getStatusCode(), $name);
            $this->assertArrayHasKey('comprovante', $response->json('errors') ?? [], $name.' '.$response->getContent());
        }

        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_the_photo_formats_in_use_today_are_still_accepted_by_the_validation(): void
    {
        foreach (['jpg', 'png', 'gif', 'webp'] as $ext) {
            foreach ($this->endpoints() as $name => [$url]) {
                $file = UploadedFile::fake()->image("foto.{$ext}");

                $response = $this->send($url, $file);

                $errors = $response->status() === 422 ? ($response->json('errors') ?? []) : [];
                $this->assertArrayNotHasKey('comprovante', $errors, "{$name} {$ext}");
            }
        }
    }
}
