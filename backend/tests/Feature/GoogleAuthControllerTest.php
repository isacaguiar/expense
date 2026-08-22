<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class GoogleAuthControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
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
}
