<?php

namespace Tests\Feature;

use App\Exceptions\PreRegistrationThrottled;
use App\Models\User;
use App\Models\UserPreCreate;
use App\Services\PreRegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PreRegistrationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private PreRegistrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PreRegistrationService::class);
    }

    /** @return array{name: string, email: string, password: string, whatsapp: string|null} */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Maria Souza',
            'email' => 'maria-'.uniqid().'@example.com',
            'password' => 'senha-forte-123',
            'whatsapp' => '(11) 91234-5678',
        ], $overrides);
    }

    /** Lê o código em claro do e-mail que acabou de sair (ele não existe na base). */
    private function codeFromLastMail(): string
    {
        $mailable = Mail::mailer()->getSymfonyTransport()->messages()->last();
        $this->assertNotNull($mailable, 'Nenhum e-mail foi enviado.');

        // Ancorado no span .code de propósito: um \d{6} solto casaria antes com
        // as cores hex do CSS (#128468, #999999) do que com o código.
        preg_match('/class="code">\s*(\d{6})\s*</', $mailable->getOriginalMessage()->getHtmlBody(), $matches);
        $this->assertNotEmpty($matches, 'Nenhum código de 6 dígitos no corpo do e-mail.');

        return $matches[1];
    }

    public function test_start_grava_pre_cadastro_sem_criar_usuario(): void
    {
        $data = $this->payload();

        $preCreate = $this->service->start($data);

        $this->assertDatabaseHas('ex_user_pre_create', [
            'email' => $data['email'],
            'name' => 'Maria Souza',
            'whatsapp' => '(11) 91234-5678',
            'attempts' => 0,
            'consumed_at' => null,
        ]);
        $this->assertDatabaseMissing('ex_users', ['email' => $data['email']]);

        // Nem a senha nem o código ficam em claro na tabela.
        $this->assertNotSame($data['password'], $preCreate->password);
        $this->assertTrue(Hash::check($data['password'], $preCreate->password));
        $this->assertSame(6, strlen($this->codeFromLastMail()));
    }

    public function test_start_dentro_do_cooldown_e_recusado(): void
    {
        $data = $this->payload();
        $this->service->start($data);

        $this->expectException(PreRegistrationThrottled::class);
        $this->service->start($data);
    }

    public function test_start_depois_do_cooldown_regrava_a_mesma_linha(): void
    {
        $data = $this->payload();
        $this->service->start($data);
        $primeiroCodigo = $this->codeFromLastMail();

        $this->travel(PreRegistrationService::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $this->service->start(array_merge($data, ['name' => 'Maria Corrigida']));

        $this->assertSame(1, UserPreCreate::where('email', $data['email'])->count());
        $this->assertSame('Maria Corrigida', UserPreCreate::where('email', $data['email'])->first()->name);
        $this->assertNotSame($primeiroCodigo, $this->codeFromLastMail());
    }

    public function test_confirm_com_codigo_certo_cria_usuario_verificado(): void
    {
        $data = $this->payload();
        $this->service->start($data);
        $code = $this->codeFromLastMail();

        $user = $this->service->confirm($data['email'], $code);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Maria Souza', $user->name);
        $this->assertSame('(11) 91234-5678', $user->whatsapp);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('user', $user->role);

        // A senha foi hasheada no pré-cadastro; o cast 'hashed' do User não
        // pode re-hashear o que já é bcrypt, senão o login deixa de funcionar.
        $this->assertTrue(Hash::check($data['password'], $user->fresh()->password));

        $this->assertNotNull(UserPreCreate::where('email', $data['email'])->first()->consumed_at);
    }

    public function test_confirm_sem_telefone_cria_usuario_com_whatsapp_nulo(): void
    {
        $data = $this->payload(['whatsapp' => null]);
        $this->service->start($data);

        $user = $this->service->confirm($data['email'], $this->codeFromLastMail());

        $this->assertNull($user->whatsapp);
    }

    public function test_codigo_errado_incrementa_tentativas_e_nao_cria_usuario(): void
    {
        $data = $this->payload();
        $this->service->start($data);

        try {
            $this->service->confirm($data['email'], '000000');
            $this->fail('Esperava ValidationException para código errado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }

        $this->assertSame(1, UserPreCreate::where('email', $data['email'])->first()->attempts);
        $this->assertDatabaseMissing('ex_users', ['email' => $data['email']]);
    }

    public function test_tentativas_esgotadas_bloqueiam_mesmo_com_codigo_certo(): void
    {
        $data = $this->payload();
        $this->service->start($data);
        $code = $this->codeFromLastMail();

        for ($i = 0; $i < PreRegistrationService::MAX_ATTEMPTS; $i++) {
            try {
                $this->service->confirm($data['email'], '000000');
            } catch (ValidationException $e) {
                // esperado
            }
        }

        $this->assertSame(
            PreRegistrationService::MAX_ATTEMPTS,
            UserPreCreate::where('email', $data['email'])->first()->attempts
        );

        $this->expectException(PreRegistrationThrottled::class);
        $this->service->confirm($data['email'], $code);
    }

    public function test_codigo_expirado_nao_confirma(): void
    {
        $data = $this->payload();
        $this->service->start($data);
        $code = $this->codeFromLastMail();

        $this->travel(PreRegistrationService::CODE_TTL_MINUTES + 1)->minutes();

        $this->expectException(ValidationException::class);
        $this->service->confirm($data['email'], $code);
    }

    public function test_pre_cadastro_consumido_nao_pode_ser_reaproveitado(): void
    {
        $data = $this->payload();
        $this->service->start($data);
        $code = $this->codeFromLastMail();
        $this->service->confirm($data['email'], $code);

        $this->expectException(ValidationException::class);
        $this->service->confirm($data['email'], $code);
    }

    public function test_resend_dentro_do_cooldown_e_recusado(): void
    {
        $data = $this->payload();
        $this->service->start($data);

        $this->expectException(PreRegistrationThrottled::class);
        $this->service->resend($data['email']);
    }

    public function test_resend_depois_do_cooldown_gera_codigo_novo_e_zera_tentativas(): void
    {
        $data = $this->payload();
        $this->service->start($data);
        $codigoAntigo = $this->codeFromLastMail();

        try {
            $this->service->confirm($data['email'], '000000');
        } catch (ValidationException $e) {
            // só para sujar o contador de tentativas
        }

        $this->travel(PreRegistrationService::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $this->service->resend($data['email']);

        $preCreate = UserPreCreate::where('email', $data['email'])->first();
        $this->assertSame(0, $preCreate->attempts);

        $codigoNovo = $this->codeFromLastMail();
        $this->assertNotSame($codigoAntigo, $codigoNovo);
        $this->assertFalse(Hash::check($codigoAntigo, $preCreate->code_hash));
        $this->assertTrue(Hash::check($codigoNovo, $preCreate->code_hash));
    }

    public function test_resend_sem_pre_cadastro_pendente_falha(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->resend('ninguem-'.uniqid().'@example.com');
    }
}
