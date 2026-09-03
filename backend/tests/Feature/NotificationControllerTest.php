<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    private function makeNotification(User $user, array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'user_id' => $user->id,
            'type' => 'expense_paid',
            'data' => ['actorName' => 'Fulano'],
        ], $overrides));
    }

    public function test_index_returns_only_callers_notifications_most_recent_first(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $older = $this->makeNotification($me);
        $older->forceFill(['created_at' => '2026-09-01 09:00:00'])->save();
        $newer = $this->makeNotification($me);
        $newer->forceFill(['created_at' => '2026-09-03 09:00:00'])->save();
        $this->makeNotification($other);

        $response = $this->withToken($this->tokenFor($me))->getJson('/api/notifications');

        $response->assertStatus(200)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('per_page', 15);

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_index_is_paginated_at_15(): void
    {
        $me = User::factory()->create();

        for ($i = 0; $i < 16; $i++) {
            $this->makeNotification($me);
        }

        $response = $this->withToken($this->tokenFor($me))->getJson('/api/notifications');

        $response->assertStatus(200)
            ->assertJsonPath('total', 16)
            ->assertJsonPath('per_page', 15);
        $this->assertCount(15, $response->json('data'));
    }

    public function test_unread_count_counts_only_callers_unread(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $this->makeNotification($me);
        $this->makeNotification($me);
        $this->makeNotification($me, ['read_at' => now()]);
        $this->makeNotification($other);

        $response = $this->withToken($this->tokenFor($me))->getJson('/api/notifications/unread-count');

        $response->assertStatus(200)->assertExactJson(['count' => 2]);
    }

    public function test_mark_read_without_id_marks_all_callers_unread(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $mine = [$this->makeNotification($me), $this->makeNotification($me)];
        $theirs = $this->makeNotification($other);

        $response = $this->withToken($this->tokenFor($me))->postJson('/api/notifications/read');

        $response->assertStatus(200)->assertJsonPath('message', 'ok');

        foreach ($mine as $n) {
            $this->assertNotNull($n->fresh()->read_at);
        }
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_mark_read_with_id_marks_only_that_one(): void
    {
        $me = User::factory()->create();

        $target = $this->makeNotification($me);
        $untouched = $this->makeNotification($me);

        $response = $this->withToken($this->tokenFor($me))
            ->postJson('/api/notifications/read', ['id' => $target->id]);

        $response->assertStatus(200);
        $this->assertNotNull($target->fresh()->read_at);
        $this->assertNull($untouched->fresh()->read_at);
    }

    public function test_mark_read_with_another_users_id_returns_404(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $theirs = $this->makeNotification($other);

        $response = $this->withToken($this->tokenFor($me))
            ->postJson('/api/notifications/read', ['id' => $theirs->id]);

        $response->assertStatus(404);
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
        $this->getJson('/api/notifications/unread-count')->assertStatus(401);
        $this->postJson('/api/notifications/read')->assertStatus(401);
    }
}
