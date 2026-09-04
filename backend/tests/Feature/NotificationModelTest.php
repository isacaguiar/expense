<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NotificationModelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_persists_and_reads_data_back_as_array(): void
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'expense_paid',
            'group_id' => $group->id,
            'data' => ['actorName' => 'Fulano', 'expenseDescription' => 'Luz', 'amount' => '45.00'],
        ]);

        $fresh = Notification::findOrFail($notification->id);

        $this->assertIsArray($fresh->data);
        $this->assertSame('Fulano', $fresh->data['actorName']);
        $this->assertSame('45.00', $fresh->data['amount']);
        $this->assertNull($fresh->read_at);
    }

    public function test_read_at_is_cast_to_datetime(): void
    {
        $user = User::factory()->create();

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'group_member_added',
            'data' => ['groupId' => 1],
            'read_at' => '2026-09-03 10:00:00',
        ]);

        $this->assertInstanceOf(Carbon::class, $notification->fresh()->read_at);
    }

    public function test_group_id_is_optional(): void
    {
        $user = User::factory()->create();

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'expense_created',
            'data' => [],
        ]);

        $this->assertNull($notification->fresh()->group_id);
    }

    public function test_belongs_to_user_and_group(): void
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'cycle_settled',
            'group_id' => $group->id,
            'data' => ['groupName' => 'Grupo de teste'],
        ]);

        $this->assertTrue($notification->user->is($user));
        $this->assertTrue($notification->group->is($group));
    }
}
