<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GroupControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    public function test_member_can_view_group(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups/'.$group->id);

        $response->assertStatus(200)->assertJsonFragment(['id' => $group->id]);
    }

    public function test_non_member_cannot_view_group(): void
    {
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);

        $response = $this->withToken($this->tokenFor($outsider))
            ->getJson('/api/groups/'.$group->id);

        $response->assertStatus(404);
    }

    public function test_member_can_update_group(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Nome antigo']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->putJson('/api/groups/'.$group->id, ['name' => 'Nome novo']);

        $response->assertStatus(200)->assertJsonFragment(['name' => 'Nome novo']);
        $this->assertDatabaseHas('ex_groups', ['id' => $group->id, 'name' => 'Nome novo']);
    }

    public function test_non_member_cannot_update_group(): void
    {
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Nome antigo']);

        $response = $this->withToken($this->tokenFor($outsider))
            ->putJson('/api/groups/'.$group->id, ['name' => 'Nome novo']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('ex_groups', ['id' => $group->id, 'name' => 'Nome antigo']);
    }

    public function test_member_can_delete_group(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->deleteJson('/api/groups/'.$group->id);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_groups', ['id' => $group->id, 'deleted' => true]);
    }

    public function test_non_member_cannot_delete_group(): void
    {
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);

        $response = $this->withToken($this->tokenFor($outsider))
            ->deleteJson('/api/groups/'.$group->id);

        $response->assertStatus(404);
        $this->assertDatabaseHas('ex_groups', ['id' => $group->id, 'deleted' => false]);
    }
}
