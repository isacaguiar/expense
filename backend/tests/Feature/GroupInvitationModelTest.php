<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsGroupInvitations;
use Tests\TestCase;

/**
 * Tabela `ex_group_invitations` e o model `GroupInvitation` (feature
 * 20261103-convite-pendente-no-grupo, plan §1): só o dado e o escopo `open()`. Nenhuma
 * rota nem regra de negócio ainda passa por aqui.
 */
class GroupInvitationModelTest extends TestCase
{
    use BuildsGroupInvitations;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('ex_group_invitations'));
        $this->assertTrue(Schema::hasColumns('ex_group_invitations', [
            'id', 'group_id', 'user_id', 'invited_by', 'status',
            'expires_at', 'notified_at', 'responded_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_open_returns_only_pending_invitations_that_have_not_expired(): void
    {
        $group = Group::create(['name' => 'Casa']);
        $pending = $this->makeInvitation($group, User::factory()->create());
        $this->makeExpiredInvitation($group, User::factory()->create());
        $this->makeInvitation($group, User::factory()->create(), GroupInvitation::STATUS_ACCEPTED);
        $this->makeInvitation($group, User::factory()->create(), GroupInvitation::STATUS_DECLINED);
        $this->makeInvitation($group, User::factory()->create(), GroupInvitation::STATUS_CANCELLED);

        $this->assertSame([$pending->id], GroupInvitation::open()->pluck('id')->all());
    }

    public function test_an_invitation_that_expires_at_this_very_second_is_not_open(): void
    {
        Carbon::setTestNow('2026-11-03 12:00:00');
        $group = Group::create(['name' => 'Casa']);
        $expiringNow = $this->makeInvitation($group, User::factory()->create(), GroupInvitation::STATUS_PENDING, [
            'expires_at' => '2026-11-03 12:00:00',
        ]);
        $oneSecondLeft = $this->makeInvitation($group, User::factory()->create(), GroupInvitation::STATUS_PENDING, [
            'expires_at' => '2026-11-03 12:00:01',
        ]);

        $open = GroupInvitation::open()->pluck('id')->all();

        $this->assertNotContains($expiringNow->id, $open);
        $this->assertContains($oneSecondLeft->id, $open);
    }

    public function test_status_defaults_to_pending_and_the_response_dates_are_optional(): void
    {
        $group = Group::create(['name' => 'Casa']);
        $invitation = GroupInvitation::create([
            'group_id' => $group->id,
            'user_id' => User::factory()->create()->id,
            'expires_at' => now()->addDays(GroupInvitation::VALIDITY_DAYS),
        ])->fresh();

        $this->assertSame(GroupInvitation::STATUS_PENDING, $invitation->status);
        $this->assertNull($invitation->invited_by);
        $this->assertNull($invitation->notified_at);
        $this->assertNull($invitation->responded_at);
    }

    public function test_a_second_invitation_for_the_same_group_and_user_violates_uniqueness(): void
    {
        $group = Group::create(['name' => 'Casa']);
        $invitee = User::factory()->create();
        $this->makeInvitation($group, $invitee);

        $this->expectException(QueryException::class);

        $this->makeInvitation($group, $invitee, GroupInvitation::STATUS_DECLINED);
    }

    public function test_the_unique_index_is_on_the_pair_not_on_one_column(): void
    {
        $groupA = Group::create(['name' => 'Casa']);
        $groupB = Group::create(['name' => 'Viagem']);
        $ana = User::factory()->create();
        $bruno = User::factory()->create();

        $this->makeInvitation($groupA, $ana);
        $this->makeInvitation($groupB, $ana);
        $this->makeInvitation($groupA, $bruno);

        $this->assertSame(3, GroupInvitation::count());
    }

    public function test_updating_an_invitation_does_not_change_its_expiry(): void
    {
        $group = Group::create(['name' => 'Casa']);
        $invitation = $this->makeInvitation($group, User::factory()->create(), GroupInvitation::STATUS_PENDING, [
            'expires_at' => '2026-12-01 09:30:00',
        ]);

        $invitation->update(['status' => GroupInvitation::STATUS_DECLINED, 'responded_at' => now()]);

        $this->assertSame('2026-12-01 09:30:00', $invitation->fresh()->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_deleting_the_group_deletes_its_invitations(): void
    {
        $group = Group::create(['name' => 'Casa']);
        $invitation = $this->makeInvitation($group, User::factory()->create());

        $group->delete();

        $this->assertDatabaseMissing('ex_group_invitations', ['id' => $invitation->id]);
    }

    public function test_deleting_the_invitee_deletes_the_invitation(): void
    {
        $group = Group::create(['name' => 'Casa']);
        $invitee = User::factory()->create();
        $invitation = $this->makeInvitation($group, $invitee);

        $invitee->delete();

        $this->assertDatabaseMissing('ex_group_invitations', ['id' => $invitation->id]);
    }

    public function test_deleting_the_inviter_keeps_the_invitation_with_a_null_inviter(): void
    {
        $group = Group::create(['name' => 'Casa']);
        $inviter = User::factory()->create();
        $invitation = $this->makeInvitation($group, User::factory()->create(), GroupInvitation::STATUS_PENDING, [
            'invited_by' => $inviter->id,
        ]);

        $inviter->delete();

        $this->assertDatabaseHas('ex_group_invitations', ['id' => $invitation->id, 'invited_by' => null]);
    }

    public function test_the_relations_point_to_the_group_the_invitee_and_the_inviter(): void
    {
        $group = Group::create(['name' => 'Casa']);
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        $invitation = $this->makeInvitation($group, $invitee, GroupInvitation::STATUS_PENDING, [
            'invited_by' => $inviter->id,
        ]);

        $this->assertTrue($invitation->group->is($group));
        $this->assertTrue($invitation->invitee->is($invitee));
        $this->assertTrue($invitation->inviter->is($inviter));
        $this->assertSame([$invitation->id], $group->invitations()->pluck('id')->all());
    }
}
