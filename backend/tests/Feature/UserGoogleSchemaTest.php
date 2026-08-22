<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UserGoogleSchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_be_created_without_a_password(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->assertDatabaseHas('ex_users', ['id' => $user->id, 'password' => null]);
    }

    public function test_google_id_must_be_unique(): void
    {
        User::factory()->create(['google_id' => 'google-123']);

        $this->expectException(QueryException::class);

        User::factory()->create(['google_id' => 'google-123']);
    }

    public function test_avatar_url_accepts_null(): void
    {
        $user = User::factory()->create(['avatar_url' => null]);

        $this->assertDatabaseHas('ex_users', ['id' => $user->id, 'avatar_url' => null]);
    }
}
