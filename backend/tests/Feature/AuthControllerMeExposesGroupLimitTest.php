<?php

namespace Tests\Feature;

use App\Http\Controllers\GroupController;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuthControllerMeExposesGroupLimitTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    public function test_me_exposes_max_groups_per_user_without_dropping_existing_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->withToken($this->tokenFor($user))->getJson('/api/me');

        $response->assertStatus(200);
        $response->assertJsonPath('max_groups_per_user', GroupController::MAX_GROUPS_CREATED_PER_USER);
        $response->assertJsonPath('id', $user->id);
        $response->assertJsonPath('email', $user->email);
    }
}
