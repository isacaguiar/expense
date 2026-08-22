<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvitationControllerInvitationTokenTest extends TestCase
{
    use DatabaseTransactions;

    public function test_verify_with_valid_invitation_token_sets_password_and_confirms_email(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email_verified_at' => null]);
        $token = bin2hex(random_bytes(32));
        Cache::put('invitation-token:'.$user->email, $token, now()->addDays(2));

        $response = $this->postJson('/api/invitations/verify', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('senha-nova-123', $user->refresh()->password));
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_verify_consumes_the_invitation_token_after_success(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $token = bin2hex(random_bytes(32));
        Cache::put('invitation-token:'.$user->email, $token, now()->addDays(2));

        $this->postJson('/api/invitations/verify', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ])->assertStatus(200);

        $this->assertFalse(Cache::has('invitation-token:'.$user->email));

        // Reusar o mesmo token depois de consumido não deve funcionar.
        $response = $this->postJson('/api/invitations/verify', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'outra-senha-456',
            'password_confirmation' => 'outra-senha-456',
        ]);

        $response->assertStatus(401);
    }

    public function test_verify_with_invalid_invitation_token_returns_401_and_does_not_change_password(): void
    {
        $user = User::factory()->create(['password' => 'senha-original']);
        Cache::put('invitation-token:'.$user->email, bin2hex(random_bytes(32)), now()->addDays(2));

        $response = $this->postJson('/api/invitations/verify', [
            'email' => $user->email,
            'token' => 'token-errado',
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ]);

        $response->assertStatus(401);
        $this->assertTrue(Hash::check('senha-original', $user->refresh()->password));
    }

    public function test_verify_with_expired_invitation_token_returns_401(): void
    {
        $user = User::factory()->create();
        $token = bin2hex(random_bytes(32));
        $start = now();
        Cache::put('invitation-token:'.$user->email, $token, $start->copy()->addDays(2));

        $this->travelTo($start->copy()->addDays(2)->addMinute());

        $response = $this->postJson('/api/invitations/verify', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ]);

        $response->assertStatus(401);
    }
}
