<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class InvitationControllerMailViewsTest extends TestCase
{
    use DatabaseTransactions;

    // forgotPassword/verify: sem Mail::fake()/Mail::shouldReceive de propósito -- essas
    // duas formas de mock não chegam a resolver a view Blade, então não pegariam um nome
    // de view errado. MAIL_MAILER=array (phpunit.xml) ainda renderiza a view de verdade
    // antes de guardar a mensagem em memória -- é o jeito de garantir que a view existe.

    public function test_forgot_password_resolves_the_real_view(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/forgot-password', ['email' => $user->email]);

        $response->assertStatus(200);
    }

    public function test_verify_resolves_the_real_view(): void
    {
        $user = User::factory()->create();
        $token = bin2hex(random_bytes(16));
        Cache::put('password-reset-token:'.$user->email, $token, now()->addMinutes(60));

        $response = $this->postJson('/api/invitations/verify', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ]);

        $response->assertStatus(200);
    }

    // invite() tem um bug não relacionado (colisão da chave 'message' com a variável
    // $message que o Mail::send() legado injeta automaticamente na view) que impede
    // testar de ponta a ponta via HTTP sem consertar algo fora do escopo desta feature
    // (specify.md §3). Verifica só o que é escopo da TASK-125: o nome da view existe.
    public function test_invite_view_name_exists(): void
    {
        $this->assertTrue(View::exists('email.invitation'));
    }
}
