<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
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

    public function test_update_profile_persists_whatsapp_and_notify_whatsapp(): void
    {
        $user = User::factory()->create(['name' => 'Nome', 'email' => 'meu@example.com']);

        $response = $this->withToken($this->tokenFor($user))
            ->putJson('/api/user/profile', [
                'name' => 'Nome',
                'email' => 'meu@example.com',
                'whatsapp' => '(71) 99999-9999',
                'notify_whatsapp' => true,
            ]);

        $response->assertStatus(200)->assertJsonFragment(['whatsapp' => '(71) 99999-9999', 'notify_whatsapp' => true]);
        $this->assertDatabaseHas('ex_users', ['id' => $user->id, 'whatsapp' => '(71) 99999-9999', 'notify_whatsapp' => true]);
    }

    public function test_update_profile_rejects_whatsapp_in_invalid_format(): void
    {
        $user = User::factory()->create(['name' => 'Nome', 'email' => 'meu@example.com']);

        $response = $this->withToken($this->tokenFor($user))
            ->putJson('/api/user/profile', [
                'name' => 'Nome',
                'email' => 'meu@example.com',
                'whatsapp' => '71999999999',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('ex_users', ['id' => $user->id, 'whatsapp' => null]);
    }

    public function test_user_can_change_own_password(): void
    {
        $user = User::factory()->create(['password' => 'senha-antiga']);

        $response = $this->withToken($this->tokenFor($user))
            ->putJson('/api/user/password', [
                'current_password' => 'senha-antiga',
                'new_password' => 'senha-nova-123',
                'new_password_confirmation' => 'senha-nova-123',
            ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('senha-nova-123', $user->refresh()->password));
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => 'senha-antiga']);

        $response = $this->withToken($this->tokenFor($user))
            ->putJson('/api/user/password', [
                'current_password' => 'senha-errada',
                'new_password' => 'senha-nova-123',
                'new_password_confirmation' => 'senha-nova-123',
            ]);

        $response->assertStatus(422);
        $this->assertTrue(Hash::check('senha-antiga', $user->refresh()->password));
    }

    public function test_change_password_rejects_mismatched_confirmation(): void
    {
        $user = User::factory()->create(['password' => 'senha-antiga']);

        $response = $this->withToken($this->tokenFor($user))
            ->putJson('/api/user/password', [
                'current_password' => 'senha-antiga',
                'new_password' => 'senha-nova-123',
                'new_password_confirmation' => 'outra-coisa',
            ]);

        $response->assertStatus(422);
    }
}
