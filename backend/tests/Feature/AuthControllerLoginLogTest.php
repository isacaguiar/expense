<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuthControllerLoginLogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_failed_login_does_not_log_plaintext_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('senha-correta')]);

        Log::spy();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'senha-errada',
        ])->assertStatus(401);

        Log::shouldNotHaveReceived('debug');
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context = []) => ! array_key_exists('password', $context)
        );
    }

    public function test_successful_login_does_not_log_plaintext_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('senha-correta')]);

        Log::spy();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'senha-correta',
        ])->assertStatus(200);

        Log::shouldNotHaveReceived('debug');
    }
}
