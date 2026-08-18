<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PixControllerTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * O middleware jwt.auth faz o parse do token direto da requisição, então
     * actingAs() não basta aqui — precisa de um Bearer token de verdade.
     */
    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    public function test_generate_pix_requires_authentication(): void
    {
        $response = $this->getJson('/api/pix/generate');

        $response->assertStatus(401);
    }

    public function test_user_can_generate_own_pix(): void
    {
        $user = User::factory()->create();
        $user->pix = 'chave-pix@example.com';
        $user->save();

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/pix/generate?email='.$user->email.'&valor=10.00');

        $response->assertStatus(200)
            ->assertJsonStructure(['qrcode', 'copiacola']);
    }

    public function test_user_can_generate_pix_of_group_member(): void
    {
        $author = User::factory()->create();
        $target = User::factory()->create();
        $target->pix = 'chave-pix@example.com';
        $target->save();

        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$author->id, $target->id]);

        $response = $this->withToken($this->tokenFor($author))
            ->getJson('/api/pix/generate?email='.$target->email.'&valor=10.00');

        $response->assertStatus(200)
            ->assertJsonStructure(['qrcode', 'copiacola']);
    }

    public function test_user_cannot_generate_pix_of_stranger(): void
    {
        $author = User::factory()->create();
        $stranger = User::factory()->create();
        $stranger->pix = 'chave-pix@example.com';
        $stranger->save();

        $response = $this->withToken($this->tokenFor($author))
            ->getJson('/api/pix/generate?email='.$stranger->email.'&valor=10.00');

        $response->assertStatus(403);
    }
}
