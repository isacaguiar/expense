<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class GoogleAuthControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    /**
     * Grava o contexto de vínculo no cache como o redirectUrl faz e devolve o token opaco.
     */
    private function linkState(int $userId): string
    {
        $token = Str::random(40);

        Cache::put("google_oauth_state:{$token}", [
            'intent' => 'link',
            'user_id' => $userId,
        ], now()->addMinutes(5));

        return $token;
    }

    /**
     * Grava o contexto de login no cache como o loginRedirect faz e devolve o token opaco.
     */
    private function loginState(): string
    {
        $token = Str::random(40);

        Cache::put("google_oauth_state:{$token}", [
            'intent' => 'login',
        ], now()->addMinutes(5));

        return $token;
    }

    private function extractGoogleCode($response): string
    {
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['google_code'];
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

    public function test_redirect_url_returns_a_google_authorize_url_with_opaque_state(): void
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

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $query['state']);

        $context = Cache::get("google_oauth_state:{$query['state']}");
        $this->assertSame('link', $context['intent']);
        $this->assertSame($user->id, $context['user_id']);
    }

    public function test_login_redirect_sends_the_user_to_google_with_opaque_login_state(): void
    {
        config(['services.google.client_id' => 'test-client-id']);
        config(['services.google.client_secret' => 'test-client-secret']);
        config(['services.google.redirect' => 'http://localhost/api/auth/google/callback']);

        $response = $this->get('/api/auth/google/login');

        $response->assertStatus(302);

        $url = $response->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('test-client-id', $query['client_id']);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $query['state']);

        $context = Cache::get("google_oauth_state:{$query['state']}");
        $this->assertSame('login', $context['intent']);
        $this->assertArrayNotHasKey('user_id', $context);
    }

    public function test_callback_links_google_account_to_user_from_valid_state(): void
    {
        config(['services.frontend_url' => 'http://localhost:3000']);
        Socialite::fake('google', $this->fakeGoogleUser('google-123'));

        $user = User::factory()->create();
        $state = $this->linkState($user->id);

        $response = $this->get('/api/auth/google/callback?state='.urlencode($state));

        $response->assertRedirect('http://localhost:3000/profile?linked=success');
        $this->assertDatabaseHas('ex_users', [
            'id' => $user->id,
            'google_id' => 'google-123',
            'avatar_url' => 'https://google.example/pic.jpg',
        ]);
    }

    public function test_callback_state_is_single_use(): void
    {
        config(['services.frontend_url' => 'http://localhost:3000']);
        Socialite::fake('google', $this->fakeGoogleUser('google-123'));

        $user = User::factory()->create();
        $state = $this->linkState($user->id);

        $first = $this->get('/api/auth/google/callback?state='.urlencode($state));
        $first->assertRedirect('http://localhost:3000/profile?linked=success');

        $second = $this->get('/api/auth/google/callback?state='.urlencode($state));
        $second->assertRedirect('http://localhost:3000/profile?linked=error');
    }

    public function test_callback_redirects_with_error_on_unknown_state(): void
    {
        config(['services.frontend_url' => 'http://localhost:3000']);
        Socialite::fake('google', $this->fakeGoogleUser());

        $user = User::factory()->create();
        $state = Str::random(40); // token nunca gravado no cache (cobre expirado / ausente também)

        $response = $this->get('/api/auth/google/callback?state='.urlencode($state));

        $response->assertRedirect('http://localhost:3000/profile?linked=error');
        $this->assertDatabaseHas('ex_users', ['id' => $user->id, 'google_id' => null]);
    }

    public function test_callback_redirects_with_error_on_garbage_state(): void
    {
        config(['services.frontend_url' => 'http://localhost:3000']);

        $response = $this->get('/api/auth/google/callback?state=not-a-valid-state-token');

        $response->assertRedirect('http://localhost:3000/profile?linked=error');
    }

    public function test_callback_redirects_with_error_when_google_id_already_linked_to_another_user(): void
    {
        config(['services.frontend_url' => 'http://localhost:3000']);
        User::factory()->create(['google_id' => 'google-123']);
        Socialite::fake('google', $this->fakeGoogleUser('google-123'));

        $user = User::factory()->create();
        $state = $this->linkState($user->id);

        $response = $this->get('/api/auth/google/callback?state='.urlencode($state));

        $response->assertRedirect('http://localhost:3000/profile?linked=error');
        $this->assertDatabaseHas('ex_users', ['id' => $user->id, 'google_id' => null]);
    }

    public function test_callback_returns_501_when_intent_is_not_link(): void
    {
        $token = Str::random(40);
        Cache::put("google_oauth_state:{$token}", ['intent' => 'bogus'], now()->addMinutes(5));

        $response = $this->get('/api/auth/google/callback?state='.urlencode($token));

        $response->assertStatus(501);
    }

    public function test_login_callback_creates_a_new_user_and_redirects_with_an_exchange_code(): void
    {
        config(['services.frontend_url' => 'http://localhost:3000']);
        Socialite::fake('google', $this->fakeGoogleUser('google-new-1'));

        $state = $this->loginState();

        $response = $this->get('/api/auth/google/callback?state='.urlencode($state));

        $response->assertStatus(302);
        $this->assertMatchesRegularExpression(
            '#^http://localhost:3000/login\?google_code=[A-Za-z0-9]{40}$#',
            $response->headers->get('Location')
        );

        $this->assertDatabaseHas('ex_users', [
            'email' => 'ana@example.com',
            'google_id' => 'google-new-1',
            'password' => null,
        ]);

        $user = User::where('email', 'ana@example.com')->first();
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_login_callback_auto_links_existing_user_found_by_email(): void
    {
        config(['services.frontend_url' => 'http://localhost:3000']);
        $existing = User::factory()->create(['email' => 'ana@example.com', 'google_id' => null]);
        Socialite::fake('google', $this->fakeGoogleUser('google-456'));
        $countBefore = User::count();

        $state = $this->loginState();

        $response = $this->get('/api/auth/google/callback?state='.urlencode($state));

        $response->assertStatus(302);
        $this->assertSame($countBefore, User::count(), 'não deveria criar uma segunda conta para o mesmo e-mail');
        $this->assertDatabaseHas('ex_users', [
            'id' => $existing->id,
            'google_id' => 'google-456',
        ]);
    }

    public function test_login_callback_reuses_existing_user_found_by_google_id(): void
    {
        config(['services.frontend_url' => 'http://localhost:3000']);
        $existing = User::factory()->create(['google_id' => 'google-789', 'email' => 'outro@example.com']);
        Socialite::fake('google', $this->fakeGoogleUser('google-789'));
        $countBefore = User::count();

        $state = $this->loginState();

        $response = $this->get('/api/auth/google/callback?state='.urlencode($state));

        $response->assertStatus(302);
        $this->assertSame($countBefore, User::count(), 'login repetido não deveria criar uma conta nova');

        $code = $this->extractGoogleCode($response);
        $token = Cache::get("google_login_code:{$code}");

        $this->withToken($token)->getJson('/api/me')->assertJsonPath('id', $existing->id);
    }

    public function test_login_callback_redirects_with_error_when_socialite_fails(): void
    {
        config(['services.frontend_url' => 'http://localhost:3000']);

        $state = $this->loginState();

        $response = $this->get('/api/auth/google/callback?state='.urlencode($state));

        $response->assertRedirect('http://localhost:3000/login?google_error=1');
    }

    public function test_exchange_returns_the_access_token_for_a_valid_code(): void
    {
        $jwt = $this->tokenFor(User::factory()->create());
        $code = Str::random(40);
        Cache::put("google_login_code:{$code}", $jwt, now()->addMinutes(1));

        $response = $this->getJson('/api/auth/google/exchange?code='.$code);

        $response->assertStatus(200);
        $response->assertJsonPath('access_token', $jwt);
        $response->assertJsonPath('token_type', 'bearer');
        $this->assertIsInt($response->json('expires_in'));
    }

    public function test_exchange_rejects_an_unknown_or_expired_code(): void
    {
        $response = $this->getJson('/api/auth/google/exchange?code='.Str::random(40));

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Código inválido ou expirado.');
    }

    public function test_exchange_code_is_single_use(): void
    {
        $jwt = $this->tokenFor(User::factory()->create());
        $code = Str::random(40);
        Cache::put("google_login_code:{$code}", $jwt, now()->addMinutes(1));

        $first = $this->getJson('/api/auth/google/exchange?code='.$code);
        $first->assertStatus(200);

        $second = $this->getJson('/api/auth/google/exchange?code='.$code);
        $second->assertStatus(401);
    }
}
