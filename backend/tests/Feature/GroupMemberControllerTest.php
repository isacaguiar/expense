<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GroupMemberControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    public function test_user_at_creation_limit_can_still_be_added_as_member_of_another_group(): void
    {
        $limitedUser = User::factory()->create();
        $limitedUserToken = $this->tokenFor($limitedUser);

        foreach (range(1, 3) as $i) {
            $this->withToken($limitedUserToken)
                ->postJson('/api/groups', ['name' => "Grupo criado {$i}"])
                ->assertStatus(201);
        }

        $otherCreator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de outra pessoa', 'created_by' => $otherCreator->id, 'deleted' => false]);

        $response = $this->withToken($this->tokenFor($otherCreator))
            ->postJson("/api/groups/{$group->id}/members", ['email' => $limitedUser->email]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('ex_groups_members', ['group_id' => $group->id, 'user_id' => $limitedUser->id]);
    }
}
