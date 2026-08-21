<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    public function test_user_can_update_own_profile(): void
    {
        $user = User::factory()->create(['name' => 'Nome Antigo', 'email' => 'antigo@example.com']);

        $response = $this->withToken($this->tokenFor($user))
            ->putJson('/api/user/profile', [
                'name' => 'Nome Novo',
                'email' => 'novo@example.com',
                'pix' => 'novo@example.com',
            ]);

        $response->assertStatus(200)->assertJsonFragment(['name' => 'Nome Novo', 'email' => 'novo@example.com']);
        $this->assertDatabaseHas('ex_users', ['id' => $user->id, 'name' => 'Nome Novo', 'email' => 'novo@example.com']);
    }

    public function test_update_profile_rejects_email_already_used_by_another_user(): void
    {
        $user = User::factory()->create(['email' => 'meu@example.com']);
        User::factory()->create(['email' => 'ocupado@example.com']);

        $response = $this->withToken($this->tokenFor($user))
            ->putJson('/api/user/profile', ['name' => 'Nome', 'email' => 'ocupado@example.com']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_users', ['id' => $user->id, 'email' => 'meu@example.com']);
    }

    public function test_update_profile_allows_keeping_the_same_email(): void
    {
        $user = User::factory()->create(['name' => 'Nome', 'email' => 'meu@example.com']);

        $response = $this->withToken($this->tokenFor($user))
            ->putJson('/api/user/profile', ['name' => 'Nome Atualizado', 'email' => 'meu@example.com']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_users', ['id' => $user->id, 'name' => 'Nome Atualizado', 'email' => 'meu@example.com']);
    }
}
