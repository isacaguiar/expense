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
}
