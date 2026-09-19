<?php

namespace App\Services;

use App\Exceptions\PreRegistrationThrottled;
use App\Mail\PreRegisterCodeMail;
use App\Models\User;
use App\Models\UserPreCreate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Regra do auto-cadastro com confirmação de e-mail por código.
 *
 * O formulário de `/cadastro` não cria um `User`: grava uma linha em
 * `ex_user_pre_create` e dispara um código de 6 dígitos por e-mail. O `User`
 * só nasce quando o código volta certo, já com `email_verified_at` preenchido.
 *
 * Nem a senha nem o código existem em claro na base — a senha chega aqui e é
 * hasheada antes de persistir, e do código guarda-se só o hash. O código em
 * claro só trafega dentro do e-mail.
 *
 * **Handle**: `start()` devolve um segredo opaco a quem submeteu o formulário,
 * exigido de volta em `confirm()`/`resend()`. Sem ele, como `updateOrCreate` é
 * chaveado só por e-mail, um terceiro poderia sobrescrever o pré-cadastro
 * pendente de um e-mail alheio com a senha dele; a vítima, ao digitar o código
 * que acabou de chegar na caixa dela, criaria a conta com a senha do atacante
 * (account pre-hijacking). O handle amarra a confirmação a quem submeteu.
 *
 * Ver docs/feature/20260919-cadastro-de-usuarios/plan.md §2 e §10.
 */
class PreRegistrationService
{
    public const CODE_LENGTH = 6;

    public const CODE_TTL_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public const MAX_RESENDS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    /** Mensagem única para todo caminho de falha de confirmação — não revela qual peça falhou. */
    private const GENERIC_CODE_ERROR = 'Código inválido ou expirado. Confira o e-mail ou peça um novo código.';

    /**
     * Grava (ou regrava) o pré-cadastro do e-mail e manda o código.
     *
     * @param  array{name: string, email: string, password: string, whatsapp?: string|null}  $data
     * @return string o handle em claro, que só quem submeteu recebe
     *
     * @throws PreRegistrationThrottled quando o último envio para este e-mail foi há menos de RESEND_COOLDOWN_SECONDS
     */
    public function start(array $data): string
    {
        $existing = UserPreCreate::where('email', $data['email'])->first();

        if ($existing !== null) {
            $this->guardResendCooldown($existing);
        }

        $code = $this->generateCode();
        $handle = bin2hex(random_bytes(32));
        $previousSentAt = $existing?->last_sent_at;

        $preCreate = UserPreCreate::updateOrCreate(
            ['email' => $data['email']],
            [
                'name' => $data['name'],
                'whatsapp' => $data['whatsapp'] ?? null,
                'password' => Hash::make($data['password']),
                'code_hash' => Hash::make($code),
                'handle_hash' => Hash::make($handle),
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'last_sent_at' => now(),
            ]
        );

        // Contadores nunca vêm de mass assignment (não estão em $fillable).
        $preCreate->forceFill([
            'attempts' => 0,
            'resend_count' => 0,
            'consumed_at' => null,
        ])->save();

        $this->sendCode($preCreate, $code, $previousSentAt);

        return $handle;
    }

    /**
     * Gera um código novo para um pré-cadastro que já existe e reenvia.
     *
     * @throws ValidationException quando não há pré-cadastro pendente para o par e-mail+handle
     * @throws PreRegistrationThrottled quando o cooldown ainda não passou ou o teto de reenvios foi atingido
     */
    public function resend(string $email, string $handle): void
    {
        $preCreate = $this->findPending($email, $handle);

        $this->guardResendCooldown($preCreate);

        if ($preCreate->resend_count >= self::MAX_RESENDS) {
            throw new PreRegistrationThrottled(
                'Você já pediu códigos demais para este cadastro. Preencha o formulário novamente.',
                0
            );
        }

        $code = $this->generateCode();
        $previousSentAt = $preCreate->last_sent_at;

        // Código novo zera as tentativas daquele código, mas o teto de reenvios
        // impede que isso vire orçamento infinito de adivinhação.
        $preCreate->forceFill([
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'resend_count' => $preCreate->resend_count + 1,
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'last_sent_at' => now(),
        ])->save();

        $this->sendCode($preCreate, $code, $previousSentAt);
    }

    /**
     * Confere o código e, se bater, cria o `User` definitivo.
     *
     * @throws ValidationException quando o par e-mail+handle não existe, ou o código está errado/expirado
     * @throws PreRegistrationThrottled quando as tentativas daquele código se esgotaram
     */
    public function confirm(string $email, string $handle, string $code): User
    {
        $preCreate = $this->findPending($email, $handle);

        if ($preCreate->isExpired()) {
            throw $this->genericCodeError();
        }

        // Reserva a tentativa ANTES de conferir o código, com um UPDATE
        // condicional: ler `attempts` e só depois incrementar deixaria N
        // requisições concorrentes passarem todas pelo Hash::check com a mesma
        // leitura de 0, e o teto de MAX_ATTEMPTS só valeria em cenário serial.
        $reserved = UserPreCreate::where('id', $preCreate->id)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->increment('attempts');

        if ($reserved === 0) {
            throw new PreRegistrationThrottled(
                'Muitas tentativas com este código. Peça um novo código.',
                $this->secondsUntilResend($preCreate)
            );
        }

        if (! Hash::check($code, $preCreate->code_hash)) {
            throw $this->genericCodeError();
        }

        return DB::transaction(function () use ($email) {
            $preCreate = UserPreCreate::where('email', $email)->lockForUpdate()->first();

            if ($preCreate === null || $preCreate->isConsumed()) {
                throw $this->genericCodeError();
            }

            // O unique:ex_users do FormRequest valeu no start(); entre ele e
            // aqui há até 15 min em que POST /register ou um convite a grupo
            // podem ter criado o usuário. Sem esta checagem, o save() estoura
            // QueryException -- que interpola os bindings na mensagem logada,
            // levando e-mail, telefone e o hash da senha para o laravel.log.
            if (User::where('email', $preCreate->email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'Este e-mail já está cadastrado. Faça login para continuar.',
                ]);
            }

            $user = new User;
            $user->name = $preCreate->name;
            $user->email = $preCreate->email;
            // A senha já vem hasheada do pré-cadastro. O cast 'hashed' de User
            // detecta bcrypt e não re-hasheia -- comportamento coberto por teste.
            $user->password = $preCreate->password;
            // `whatsapp` não está em $fillable de User, então é atribuído direto.
            $user->whatsapp = $preCreate->whatsapp;
            $user->email_verified_at = now();
            $user->save();

            // A linha fica (hard delete é gate humano), mas sem o material de
            // credencial: depois do consumo ela não precisa mais guardar uma
            // segunda cópia do hash da senha nem os segredos do fluxo.
            $preCreate->forceFill([
                'consumed_at' => now(),
                'password' => '',
                'code_hash' => '',
                'handle_hash' => '',
            ])->save();

            return $user;
        });
    }

    /** Quantos segundos faltam para este pré-cadastro poder pedir outro código. */
    public function secondsUntilResend(UserPreCreate $preCreate): int
    {
        if ($preCreate->last_sent_at === null) {
            return 0;
        }

        $elapsed = $preCreate->last_sent_at->diffInSeconds(now());

        return (int) max(0, self::RESEND_COOLDOWN_SECONDS - $elapsed);
    }

    /**
     * Pré-cadastro pendente daquele e-mail, desde que o handle bata.
     *
     * Handle errado e e-mail inexistente devolvem exatamente o mesmo erro, para
     * a rota não virar um oráculo de quem tem cadastro pendente. Handle errado
     * também não gasta tentativa: quem não submeteu o formulário não pode
     * queimar o saldo de quem submeteu.
     *
     * @throws ValidationException
     */
    private function findPending(string $email, string $handle): UserPreCreate
    {
        $preCreate = UserPreCreate::where('email', $email)->first();

        if ($preCreate === null || $preCreate->isConsumed()) {
            throw $this->genericCodeError();
        }

        if ($preCreate->handle_hash === '' || ! Hash::check($handle, $preCreate->handle_hash)) {
            throw $this->genericCodeError();
        }

        return $preCreate;
    }

    private function genericCodeError(): ValidationException
    {
        return ValidationException::withMessages(['code' => self::GENERIC_CODE_ERROR]);
    }

    /** @throws PreRegistrationThrottled */
    private function guardResendCooldown(UserPreCreate $preCreate): void
    {
        $remaining = $this->secondsUntilResend($preCreate);

        if ($remaining > 0) {
            throw new PreRegistrationThrottled(
                "Já enviamos um código para este e-mail. Aguarde {$remaining} segundos para pedir outro.",
                $remaining
            );
        }
    }

    /** Código numérico de CODE_LENGTH dígitos, com zeros à esquerda preservados. */
    private function generateCode(): string
    {
        $max = (10 ** self::CODE_LENGTH) - 1;

        return str_pad((string) random_int(0, $max), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Envia o código. Se o envio falhar, devolve `last_sent_at` ao valor
     * anterior: sem isso a pessoa ficaria presa 60 s num cooldown por um código
     * que nunca chegou (mesmo cuidado que `InvitationController::forgotPassword`
     * já toma ao só gravar a chave de rate limit depois do envio).
     */
    private function sendCode(UserPreCreate $preCreate, string $code, ?\Illuminate\Support\Carbon $previousSentAt): void
    {
        try {
            Mail::to($preCreate->email)->send(
                new PreRegisterCodeMail($preCreate->name, $code, self::CODE_TTL_MINUTES)
            );
        } catch (Throwable $e) {
            $preCreate->forceFill(['last_sent_at' => $previousSentAt])->save();

            // Sem o código e sem o e-mail no log -- só o que dá para agir.
            Log::error('Falha ao enviar o código de pré-cadastro.', [
                'pre_create_id' => $preCreate->id,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
