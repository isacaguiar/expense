<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GroupMemberInvitationTokenTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    public function test_new_member_invite_stores_a_dedicated_cache_token_valid_for_two_days(): void
    {
        Mail::fake();

        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $newMemberEmail = 'novo.membro.'.uniqid().'@example.com';
        $start = now();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups/'.$group->id.'/members', ['email' => $newMemberEmail]);

        $response->assertStatus(201);

        // Token dedicado no cache — não mais via Password::getRepository().
        $this->assertTrue(Cache::has('invitation-token:'.$newMemberEmail));
        $this->assertDatabaseMissing('ex_password_reset_tokens', ['email' => $newMemberEmail]);

        $this->travelTo($start->copy()->addDays(2)->subMinute());
        $this->assertTrue(
            Cache::has('invitation-token:'.$newMemberEmail),
            'token deveria continuar válido pouco antes de completar 2 dias'
        );

        $this->travelTo($start->copy()->addDays(2)->addMinutes(2));
        $this->assertFalse(
            Cache::has('invitation-token:'.$newMemberEmail),
            'token deveria expirar pouco depois de completar 2 dias'
        );
    }
}
