<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InvitationControllerValidationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_forgot_password_with_unknown_email_returns_422_not_500(): void
    {
        $response = $this->postJson('/api/forgot-password', ['email' => 'ninguem@example.com']);

        $response->assertStatus(422);
    }

    public function test_verify_with_unknown_email_returns_422_not_500(): void
    {
        $response = $this->postJson('/api/invitations/verify', [
            'email' => 'ninguem@example.com',
            'token' => 'qualquer-token',
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ]);

        $response->assertStatus(422);
    }
}
