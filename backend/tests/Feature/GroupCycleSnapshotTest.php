<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GroupCycleSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    private function createSnapshot(Group $group, array $overrides = []): GroupCycleSnapshot
    {
        return GroupCycleSnapshot::create(array_merge([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'totals' => ['total' => 100, 'paid' => 0, 'pending' => 100],
            'expenses' => [],
            'balances' => [],
        ], $overrides));
    }

    public function test_never_closed_manually_is_not_active(): void
    {
        $group = Group::create(['name' => 'Grupo de teste']);
        $snapshot = $this->createSnapshot($group);

        $this->assertFalse($snapshot->isManuallyClosedAndActive());
    }

    public function test_closed_manually_without_reopening_is_active(): void
    {
        $group = Group::create(['name' => 'Grupo de teste']);
        $snapshot = $this->createSnapshot($group, ['closed_manually_at' => '2026-08-19 10:00:00']);

        $this->assertTrue($snapshot->isManuallyClosedAndActive());
    }

    public function test_reopened_after_closing_is_not_active(): void
    {
        $group = Group::create(['name' => 'Grupo de teste']);
        $snapshot = $this->createSnapshot($group, [
            'closed_manually_at' => '2026-08-19 10:00:00',
            'reopened_at' => '2026-08-19 11:00:00',
        ]);

        $this->assertFalse($snapshot->isManuallyClosedAndActive());
    }

    public function test_closed_again_after_a_previous_reopening_is_active(): void
    {
        $group = Group::create(['name' => 'Grupo de teste']);
        $snapshot = $this->createSnapshot($group, [
            'closed_manually_at' => '2026-08-19 12:00:00',
            'reopened_at' => '2026-08-19 11:00:00',
        ]);

        $this->assertTrue($snapshot->isManuallyClosedAndActive());
    }

    public function test_unique_group_id_and_cycle_start_rejects_duplicate(): void
    {
        $group = Group::create(['name' => 'Grupo de teste']);
        $this->createSnapshot($group);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->createSnapshot($group);
    }
}
