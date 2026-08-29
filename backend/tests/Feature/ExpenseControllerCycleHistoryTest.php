<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseControllerCycleHistoryTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    private function createSnapshot(Group $group, string $cycleStart, string $cycleEnd, array $overrides = []): GroupCycleSnapshot
    {
        return GroupCycleSnapshot::create(array_merge([
            'group_id' => $group->id,
            'cycle_start' => $cycleStart,
            'cycle_end' => $cycleEnd,
            'totals' => ['total' => 100, 'paid' => 100, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'settlements' => [],
        ], $overrides));
    }

    public function test_group_with_no_past_cycles_returns_an_empty_list(): void
    {
        Carbon::setTestNow('2026-08-19');

        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson("/api/groups/{$group->id}/expenses/cycles");

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_past_cycles_are_listed_most_recent_first(): void
    {
        Carbon::setTestNow('2026-08-19');

        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $this->createSnapshot($group, '2026-06-01', '2026-06-30', ['totals' => ['total' => 50, 'paid' => 50, 'pending' => 0]]);
        $this->createSnapshot($group, '2026-07-01', '2026-07-31', ['totals' => ['total' => 75, 'paid' => 0, 'pending' => 75]]);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson("/api/groups/{$group->id}/expenses/cycles");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame('2026-07-01', $data[0]['cycle']['start']);
        $this->assertSame('closed', $data[0]['cycle']['status']);
        $this->assertSame(75, $data[0]['totals']['total']);
        $this->assertSame('2026-06-01', $data[1]['cycle']['start']);
    }

    public function test_current_cycle_never_appears_even_when_closed_manually(): void
    {
        Carbon::setTestNow('2026-08-19');

        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        // Competência vigente (agosto), fechada manualmente — não é histórico
        // imutável (pode ser reaberta), então não deve aparecer na lista.
        $this->createSnapshot($group, '2026-08-01', '2026-08-31', [
            'closed_manually_at' => Carbon::now(),
        ]);
        $this->createSnapshot($group, '2026-07-01', '2026-07-31');

        $response = $this->withToken($this->tokenFor($member))
            ->getJson("/api/groups/{$group->id}/expenses/cycles");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('2026-07-01', $data[0]['cycle']['start']);
    }

    public function test_non_member_cannot_view_cycle_history(): void
    {
        $outsider = User::factory()->create();
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($outsider))
            ->getJson("/api/groups/{$group->id}/expenses/cycles");

        $response->assertStatus(404);
    }

    public function test_history_is_paginated(): void
    {
        Carbon::setTestNow('2027-01-19');

        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        for ($month = 1; $month <= 12; $month++) {
            $start = sprintf('2026-%02d-01', $month);
            $end = Carbon::parse($start)->endOfMonth()->toDateString();
            $this->createSnapshot($group, $start, $end);
        }

        $response = $this->withToken($this->tokenFor($member))
            ->getJson("/api/groups/{$group->id}/expenses/cycles");

        $response->assertStatus(200)
            ->assertJsonPath('total', 12)
            ->assertJsonPath('per_page', 10);
        $this->assertCount(10, $response->json('data'));
    }
}
