<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EmailBrandingTest extends TestCase
{
    use DatabaseTransactions;

    // Stub de Illuminate\Mail\Message só com o método que as views usam.
    // As views recebem a instância real do Mailer em produção (ver
    // vendor/laravel/framework/src/Illuminate/Mail/Mailer.php:298-320,
    // $message é injetado em $data antes de renderizar a view); aqui simulamos
    // isso diretamente via view() para checar o HTML renderizado sem precisar
    // de Mail::fake() (que não chega a renderizar a view de verdade).
    private function fakeMessage(): object
    {
        return new class
        {
            public function embed($file)
            {
                return 'cid:fake-logo-cid';
            }
        };
    }

    public function test_pre_register_code_view_has_no_novemax_branding(): void
    {
        $html = view('email.pre-register-code', [
            'message' => $this->fakeMessage(),
            'name' => 'Teste',
            'code' => '123456',
            'expiresInMinutes' => 10,
        ])->render();

        $this->assertStringNotContainsString('Novemax', $html);
        $this->assertStringContainsString('cid:', $html);
    }

    public function test_password_reset_view_has_no_novemax_branding(): void
    {
        $user = User::factory()->create(['name' => 'Teste']);

        $html = view('email.password-reset', [
            'message' => $this->fakeMessage(),
            'user' => $user,
            'resetLink' => 'http://localhost/recuperar-senha?email=teste@example.com&token=xyz',
        ])->render();

        $this->assertStringNotContainsString('Novemax', $html);
        $this->assertStringContainsString('cid:', $html);
    }

    public function test_invitation_view_has_no_novemax_branding(): void
    {
        $html = view('email.invitation', [
            'message' => $this->fakeMessage(),
            'inviterName' => 'Convidante',
            'groupName' => 'Grupo de teste',
            'inviteMessage' => null,
            'activationLink' => 'http://localhost/aceitar-convite?email=teste@example.com&token=xyz',
        ])->render();

        $this->assertStringNotContainsString('Novemax', $html);
        $this->assertStringContainsString('cid:', $html);
    }

    public function test_activation_confirmation_view_has_no_novemax_branding(): void
    {
        $user = User::factory()->create(['name' => 'Teste']);

        $html = view('email.activation-confirmation', [
            'message' => $this->fakeMessage(),
            'user' => $user,
        ])->render();

        $this->assertStringNotContainsString('Novemax', $html);
        $this->assertStringContainsString('cid:', $html);
    }
}
