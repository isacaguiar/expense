<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvitationControllerForgotPasswordTest extends TestCase
{
    use DatabaseTransactions;

    public function test_mail_failure_does_not_change_password_logs_error_and_does_not_block_retry(): void
    {
        $user = User::factory()->create(['password' => 'senha-original']);

        Log::spy();
        Mail::shouldReceive('send')->once()->andThrow(new \Exception('smtp indisponível'));

        $response = $this->postJson('/api/forgot-password', ['email' => $user->email]);

        $response->assertStatus(500);
        $this->assertTrue(Hash::check('senha-original', $user->refresh()->password));
        Log::shouldHaveReceived('error')->once();
        $this->assertFalse(Cache::has('password-reset-rate:'.$user->email));
    }

    public function test_successful_send_keeps_password_unchanged_and_sets_rate_limit(): void
    {
        $user = User::factory()->create(['password' => 'senha-original']);

        Mail::shouldReceive('send')->once();

        $response = $this->postJson('/api/forgot-password', ['email' => $user->email]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('senha-original', $user->refresh()->password));
        $this->assertTrue(Cache::has('password-reset-rate:'.$user->email));
    }

    public function test_second_request_right_after_success_is_rate_limited(): void
    {
        $user = User::factory()->create(['password' => 'senha-original']);

        Mail::shouldReceive('send')->once();

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertStatus(200);

        $response = $this->postJson('/api/forgot-password', ['email' => $user->email]);

        $response->assertStatus(429);
    }

    public function test_full_reset_flow_updates_password_via_verify(): void
    {
        $user = User::factory()->create(['password' => 'senha-original']);

        Mail::shouldReceive('send')->once();
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertStatus(200);

        $token = Cache::get('password-reset-token:'.$user->email);
        $this->assertNotNull($token);

        Mail::shouldReceive('send')->once(); // e-mail de confirmação enviado por verify()

        $response = $this->postJson('/api/invitations/verify', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('senha-nova-123', $user->refresh()->password));
        $this->assertFalse(Hash::check('senha-original', $user->password));
    }
}
