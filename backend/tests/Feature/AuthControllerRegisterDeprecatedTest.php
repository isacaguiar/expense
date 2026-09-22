<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuthControllerRegisterDeprecatedTest extends TestCase
{
    use DatabaseTransactions;

    public function test_register_responds_410_and_creates_no_user(): void
    {
        $countBefore = User::count();

        $this->postJson('/api/register', [
            'name' => 'Fulano',
            'email' => 'fulano@example.com',
            'password' => 'senha123',
        ])->assertStatus(410);

        $this->assertSame($countBefore, User::count());
        $this->assertNull(User::where('email', 'fulano@example.com')->first());
    }
}
