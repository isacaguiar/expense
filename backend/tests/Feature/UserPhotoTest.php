<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserPhotoTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    public function test_upload_stores_the_photo_and_me_returns_a_signed_url(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->withToken($this->tokenFor($user))
            ->post('/api/user/photo', ['foto' => UploadedFile::fake()->image('eu.jpg')]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertStringStartsWith("avatares/{$user->id}/", $user->photo_path);
        Storage::disk('local')->assertExists($user->photo_path);

        $avatarUrl = $response->json('avatar_url');
        $this->assertStringContainsString("/api/user/{$user->id}/photo", $avatarUrl);
        $this->assertStringContainsString('signature=', $avatarUrl);

        $me = $this->withToken($this->tokenFor($user))->getJson('/api/me');
        $this->assertStringContainsString("/api/user/{$user->id}/photo", $me->json('avatar_url'));
        $this->assertArrayNotHasKey('photo_path', $me->json());
    }

    public function test_uploading_a_second_photo_removes_the_first_from_disk(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->withToken($token)
            ->post('/api/user/photo', ['foto' => UploadedFile::fake()->image('a.jpg')])
            ->assertStatus(200);
        $first = $user->fresh()->photo_path;

        $this->withToken($token)
            ->post('/api/user/photo', ['foto' => UploadedFile::fake()->image('b.jpg')])
            ->assertStatus(200);
        $second = $user->fresh()->photo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
    }

    public function test_delete_clears_the_photo_and_falls_back_to_the_google_avatar(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $user->avatar_url = 'https://lh3.googleusercontent.com/foto-google';
        $user->save();
        $token = $this->tokenFor($user);

        $this->withToken($token)
            ->post('/api/user/photo', ['foto' => UploadedFile::fake()->image('a.jpg')])
            ->assertStatus(200);
        $path = $user->fresh()->photo_path;

        $this->withToken($token)
            ->deleteJson('/api/user/photo')
            ->assertStatus(200)
            ->assertJsonPath('avatar_url', 'https://lh3.googleusercontent.com/foto-google');

        $this->assertNull($user->fresh()->photo_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_delete_returns_null_avatar_url_when_there_is_no_google_photo(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->withToken($token)
            ->post('/api/user/photo', ['foto' => UploadedFile::fake()->image('a.jpg')])
            ->assertStatus(200);

        $this->withToken($token)
            ->deleteJson('/api/user/photo')
            ->assertStatus(200)
            ->assertJsonPath('avatar_url', null);
    }

    public function test_rejects_a_non_image_file(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->withToken($this->tokenFor($user))
            ->post('/api/user/photo', [
                'foto' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    public function test_rejects_a_file_larger_than_5mb(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->withToken($this->tokenFor($user))
            ->post('/api/user/photo', [
                'foto' => UploadedFile::fake()->image('big.jpg')->size(5121),
            ])
            ->assertStatus(422);
    }

    public function test_serving_route_requires_a_valid_signature(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $signedUrl = $this->withToken($this->tokenFor($user))
            ->post('/api/user/photo', ['foto' => UploadedFile::fake()->image('a.jpg')])
            ->json('avatar_url');

        $this->get($signedUrl)->assertOk();
        $this->get("/api/user/{$user->id}/photo")->assertStatus(403);
    }

    public function test_upload_requires_authentication(): void
    {
        $this->post('/api/user/photo', ['foto' => UploadedFile::fake()->image('a.jpg')])
            ->assertStatus(401);
    }
}
