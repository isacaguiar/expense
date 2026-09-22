<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthControllerLoginRequiresVerifiedEmailTest extends TestCase
{
    use DatabaseTransactions;

    public function test_login_is_refused_for_unverified_email(): void
    {
        $user = User::factory()->unverified()->create(['password' => Hash::make('senha-correta')]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'senha-correta',
        ]);

        $response->assertStatus(403);
        $response->assertJsonMissing(['access_token']);
    }

    public function test_login_still_succeeds_for_verified_email(): void
    {
        $user = User::factory()->create(['password' => Hash::make('senha-correta')]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'senha-correta',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['access_token']);
    }
}
