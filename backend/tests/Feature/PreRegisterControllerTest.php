<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPreCreate;
use App\Services\PreRegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PreRegisterControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // O throttle por IP das rotas usa o cache, que com CACHE_DRIVER=array
        // acumula entre os métodos deste mesmo arquivo -- sem isso, os últimos
        // testes falhariam com 429 por causa dos primeiros. O limite que importa
        // para a regra (cooldown por e-mail) é exercitado abaixo e vem do
        // Service, não deste middleware.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function payload(array $overrides = []): array
    {
        $email = 'cadastro-'.uniqid().'@example.com';

        return array_merge([
            'name' => 'Maria Souza',
            'email' => $email,
            'email_confirmation' => $email,
            'whatsapp' => '(11) 91234-5678',
            'password' => 'senha-forte-123',
            'password_confirmation' => 'senha-forte-123',
        ], $overrides);
    }

    private function codeFromLastMail(): string
    {
        $mailable = Mail::mailer()->getSymfonyTransport()->messages()->last();
        preg_match('/class="code">\s*(\d{6})\s*</', $mailable->getOriginalMessage()->getHtmlBody(), $matches);

        return $matches[1];
    }

    public function test_e_mails_divergentes_sao_recusados(): void
    {
        $data = $this->payload(['email_confirmation' => 'outro@example.com']);

        $this->postJson('/api/pre-register', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('ex_user_pre_create', ['email' => $data['email']]);
    }

    public function test_senhas_divergentes_sao_recusadas(): void
    {
        $data = $this->payload(['password_confirmation' => 'outra-senha-456']);

        $this->postJson('/api/pre-register', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_e_mail_ja_cadastrado_e_recusado(): void
    {
        $existente = User::factory()->create();
        $data = $this->payload([
            'email' => $existente->email,
            'email_confirmation' => $existente->email,
        ]);

        $this->postJson('/api/pre-register', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_telefone_em_formato_invalido_e_recusado(): void
    {
        $this->postJson('/api/pre-register', $this->payload(['whatsapp' => '11912345678']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('whatsapp');
    }

    public function test_telefone_e_opcional(): void
    {
        $data = $this->payload(['whatsapp' => null]);

        $this->postJson('/api/pre-register', $data)->assertStatus(200);

        $this->assertDatabaseHas('ex_user_pre_create', [
            'email' => $data['email'],
            'whatsapp' => null,
        ]);
    }

    public function test_cadastro_valido_grava_pre_cadastro_sem_vazar_dado_pessoal(): void
    {
        $data = $this->payload();

        $response = $this->postJson('/api/pre-register', $data)
            ->assertStatus(200)
            ->assertJsonStructure(['message', 'handle', 'expires_in_seconds', 'resend_available_in']);

        $this->assertDatabaseHas('ex_user_pre_create', ['email' => $data['email']]);
        $this->assertDatabaseMissing('ex_users', ['email' => $data['email']]);

        // A resposta não pode servir de leitor de pré-cadastro alheio.
        $body = $response->getContent();
        $this->assertStringNotContainsString('Maria Souza', $body);
        $this->assertStringNotContainsString('91234-5678', $body);
        $this->assertStringNotContainsString('senha-forte-123', $body);
        $this->assertStringNotContainsString($this->codeFromLastMail(), $body);
        // O handle é a única coisa que a resposta devolve de propósito: ele é
        // para quem submeteu, e é o que impede sequestro do pré-cadastro.
        $this->assertNotEmpty(json_decode($body, true)['handle']);
    }

    public function test_segundo_pedido_dentro_do_cooldown_responde_429_com_retry_after(): void
    {
        $data = $this->payload();
        $this->postJson('/api/pre-register', $data)->assertStatus(200);

        $this->postJson('/api/pre-register', $data)
            ->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after']);
    }

    public function test_codigo_certo_cria_conta_e_devolve_token_utilizavel(): void
    {
        $data = $this->payload();
        $handle = $this->postJson('/api/pre-register', $data)->assertStatus(200)->json('handle');

        $response = $this->postJson('/api/pre-register/verify', [
            'email' => $data['email'],
            'handle' => $handle,
            'code' => $this->codeFromLastMail(),
        ])->assertStatus(201)->assertJsonStructure(['access_token', 'token_type', 'expires_in']);

        $this->assertDatabaseHas('ex_users', ['email' => $data['email']]);

        // O token tem de funcionar de verdade numa rota autenticada.
        $this->withToken($response->json('access_token'))
            ->getJson('/api/me')
            ->assertStatus(200)
            ->assertJsonPath('email', $data['email']);
    }

    public function test_codigo_errado_responde_422_no_campo_code(): void
    {
        $data = $this->payload();
        $handle = $this->postJson('/api/pre-register', $data)->assertStatus(200)->json('handle');

        $this->postJson('/api/pre-register/verify', [
            'email' => $data['email'],
            'handle' => $handle,
            'code' => '000000',
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertSame(1, UserPreCreate::where('email', $data['email'])->first()->attempts);
        $this->assertDatabaseMissing('ex_users', ['email' => $data['email']]);
    }

    public function test_verify_de_e_mail_sem_pre_cadastro_nao_revela_a_diferenca(): void
    {
        $this->postJson('/api/pre-register/verify', [
            'email' => 'ninguem-'.uniqid().'@example.com',
            'handle' => bin2hex(random_bytes(32)),
            'code' => '000000',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_reenvio_respeita_o_cooldown_e_depois_manda_codigo_novo(): void
    {
        $data = $this->payload();
        $handle = $this->postJson('/api/pre-register', $data)->assertStatus(200)->json('handle');
        $codigoAntigo = $this->codeFromLastMail();

        $this->postJson('/api/pre-register/resend', ['email' => $data['email'], 'handle' => $handle])
            ->assertStatus(429);

        $this->travel(PreRegistrationService::RESEND_COOLDOWN_SECONDS + 1)->seconds();

        $this->postJson('/api/pre-register/resend', ['email' => $data['email'], 'handle' => $handle])
            ->assertStatus(200);

        $this->assertNotSame($codigoAntigo, $this->codeFromLastMail());
    }

    public function test_verify_sem_handle_e_recusado_na_validacao(): void
    {
        $data = $this->payload();
        $this->postJson('/api/pre-register', $data)->assertStatus(200);

        $this->postJson('/api/pre-register/verify', [
            'email' => $data['email'],
            'code' => $this->codeFromLastMail(),
        ])->assertStatus(422)->assertJsonValidationErrors('handle');

        $this->assertDatabaseMissing('ex_users', ['email' => $data['email']]);
    }
}
