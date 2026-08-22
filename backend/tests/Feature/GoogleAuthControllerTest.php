<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class GoogleAuthControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    private function signedLinkState(int $userId, int $expiresInMinutes = 5): string
    {
        return Crypt::encryptString(json_encode([
            'intent' => 'link',
            'user_id' => $userId,
            'exp' => now()->addMinutes($expiresInMinutes)->timestamp,
        ]));
    }

    private function fakeGoogleUser(string $googleId = 'google-123'): SocialiteUser
    {
        $googleUser = new SocialiteUser;
        $googleUser->id = $googleId;
        $googleUser->email = 'ana@example.com';
        $googleUser->name = 'Ana Google';
        $googleUser->avatar = 'https://google.example/pic.jpg';

        return $googleUser;
    }

    public function test_redirect_url_requires_authentication(): void
    {
        $response = $this->getJson('/api/user/google/redirect-url');

        $response->assertStatus(401);
    }

    public function test_redirect_url_returns_a_google_authorize_url_with_signed_state(): void
    {
        config(['services.google.client_id' => 'test-client-id']);
        config(['services.google.client_secret' => 'test-client-secret']);
        config(['services.google.redirect' => 'http://localhost/api/auth/google/callback']);

        $user = User::factory()->create();

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/user/google/redirect-url');

        $response->assertStatus(200);

        $url = $response->json('url');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('test-client-id', $query['client_id']);

        $state = json_decode(Crypt::decryptString($query['state']), true);
        $this->assertSame('link', $state['intent']);
        $this->assertSame($user->id, $state['user_id']);
    }

    public function test_callback_links_google_account_to_user_from_valid_state(): void
    {
        config(['services.frontend_url' => 'http://localhost:3000']);
        Socialite::fake('google', $this->fakeGoogleUser('google-123'));

        $user = User::factory()->create();
        $state = $this->signedLinkState($user->id);

        $response = $this->get('/api/auth/google/callback?state='.urlencode($state));

        $response->assertRedirect('http://localhost:3000/profile?linked=success');
        $this->assertDatabaseHas('ex_users', [
            'id' => $user->id,
            'google_id' => 'google-123',
            'avatar_url' => 'https://google.example/pic.jpg',
        ]);
    }

    public function test_callback_redirects_with_error_on_expired_state(): void
    {
        config(['services.frontend_url' => 'http://localhost:3000']);
        Socialite::fake('google', $this->fakeGoogleUser());

        $user = User::factory()->create();
        $state = $this->signedLinkState($user->id, expiresInMinutes: -1);

        $response = $this->get('/api/auth/google/callback?state='.urlencode($state));

        $response->assertRedirect('http://localhost:3000/profile?linked=error');
        $this->assertDatabaseHas('ex_users', ['id' => $user->id, 'google_id' => null]);
    }

    public function test_callback_redirects_with_error_on_tampered_state(): void
    {
        config(['services.frontend_url' => 'http://localhost:3000']);

        $response = $this->get('/api/auth/google/callback?state=not-a-valid-encrypted-payload');

        $response->assertRedirect('http://localhost:3000/profile?linked=error');
    }

    public function test_callback_redirects_with_error_when_google_id_already_linked_to_another_user(): void
    {
        config(['services.frontend_url' => 'http://localhost:3000']);
        User::factory()->create(['google_id' => 'google-123']);
        Socialite::fake('google', $this->fakeGoogleUser('google-123'));

        $user = User::factory()->create();
        $state = $this->signedLinkState($user->id);

        $response = $this->get('/api/auth/google/callback?state='.urlencode($state));

        $response->assertRedirect('http://localhost:3000/profile?linked=error');
        $this->assertDatabaseHas('ex_users', ['id' => $user->id, 'google_id' => null]);
    }

    public function test_callback_returns_501_when_intent_is_not_link(): void
    {
        $state = Crypt::encryptString(json_encode([
            'intent' => 'login',
            'exp' => now()->addMinutes(5)->timestamp,
        ]));

        $response = $this->get('/api/auth/google/callback?state='.urlencode($state));

        $response->assertStatus(501);
    }
}
