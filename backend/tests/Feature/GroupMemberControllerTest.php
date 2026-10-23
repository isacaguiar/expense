<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GroupMemberControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    public function test_member_can_add_member_to_group(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $existingUser = User::factory()->create();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups/'.$group->id.'/members', ['email' => $existingUser->email]);

        $response->assertStatus(201);
        $this->assertTrue($group->members()->where('user_id', $existingUser->id)->exists());
    }

    public function test_non_member_cannot_add_member_to_group(): void
    {
        Mail::fake();

        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $newMemberEmail = 'novo.membro.'.uniqid().'@example.com';

        $response = $this->withToken($this->tokenFor($outsider))
            ->postJson('/api/groups/'.$group->id.'/members', ['email' => $newMemberEmail]);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('ex_users', ['email' => $newMemberEmail]);
        $this->assertSame(0, $group->members()->count());
        Mail::assertNothingSent();
    }

    public function test_cannot_add_member_to_deleted_group(): void
    {
        Mail::fake();

        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'deleted' => true]);
        $group->members()->attach($member->id);
        $newMemberEmail = 'novo.membro.'.uniqid().'@example.com';

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups/'.$group->id.'/members', ['email' => $newMemberEmail]);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('ex_users', ['email' => $newMemberEmail]);
        $this->assertSame(1, $group->members()->count());
        Mail::assertNothingSent();
    }

    // --- GET /api/groups/{groupId}/members (item 085: só quem é membro lê a lista) ---

    public function test_non_member_cannot_list_members(): void
    {
        $member = User::factory()->create([
            'pix' => 'pix-do-membro@example.com',
            'whatsapp' => '5511999990001',
        ]);
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($outsider))
            ->getJson('/api/groups/'.$group->id.'/members');

        $response->assertStatus(404);

        // O corpo da resposta não pode carregar nenhum dado do membro.
        $body = $response->getContent();
        $this->assertStringNotContainsString($member->email, $body);
        $this->assertStringNotContainsString('pix-do-membro@example.com', $body);
        $this->assertStringNotContainsString('5511999990001', $body);
    }

    public function test_member_can_list_members(): void
    {
        $member = User::factory()->create();
        $colleague = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$member->id, $colleague->id]);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups/'.$group->id.'/members');

        $response->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonFragment(['id' => $member->id, 'email' => $member->email])
            ->assertJsonFragment(['id' => $colleague->id, 'email' => $colleague->email]);
    }

    public function test_unauthenticated_cannot_list_members(): void
    {
        $group = Group::create(['name' => 'Grupo de teste']);

        $this->getJson('/api/groups/'.$group->id.'/members')->assertStatus(401);
    }
}
