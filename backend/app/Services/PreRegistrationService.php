<?php

namespace App\Services;

use App\Exceptions\PreRegistrationThrottled;
use App\Mail\PreRegisterCodeMail;
use App\Models\User;
use App\Models\UserPreCreate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

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
 * Ver docs/feature/20260919-cadastro-de-usuarios/plan.md §2.
 */
class PreRegistrationService
{
    public const CODE_LENGTH = 6;

    public const CODE_TTL_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Grava (ou regrava) o pré-cadastro do e-mail e manda o código.
     *
     * @param  array{name: string, email: string, password: string, whatsapp?: string|null}  $data
     *
     * @throws PreRegistrationThrottled quando o último envio para este e-mail foi há menos de RESEND_COOLDOWN_SECONDS
     */
    public function start(array $data): UserPreCreate
    {
        $existing = UserPreCreate::where('email', $data['email'])->first();

        if ($existing !== null) {
            $this->guardResendCooldown($existing);
        }

        $code = $this->generateCode();

        $preCreate = UserPreCreate::updateOrCreate(
            ['email' => $data['email']],
            [
                'name' => $data['name'],
                'whatsapp' => $data['whatsapp'] ?? null,
                'password' => Hash::make($data['password']),
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'last_sent_at' => now(),
                'consumed_at' => null,
            ]
        );

        $this->sendCode($preCreate, $code);

        return $preCreate;
    }

    /**
     * Gera um código novo para um pré-cadastro que já existe e reenvia.
     *
     * @throws ValidationException quando não há pré-cadastro pendente para o e-mail
     * @throws PreRegistrationThrottled quando o cooldown ainda não passou
     */
    public function resend(string $email): UserPreCreate
    {
        $preCreate = UserPreCreate::where('email', $email)->first();

        if ($preCreate === null || $preCreate->isConsumed()) {
            throw ValidationException::withMessages([
                'email' => 'Não há cadastro pendente para este e-mail. Preencha o formulário novamente.',
            ]);
        }

        $this->guardResendCooldown($preCreate);

        $code = $this->generateCode();

        // Código novo zera as tentativas: quem pediu reenvio não deve herdar
        // o saldo de erros do código anterior.
        $preCreate->forceFill([
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'last_sent_at' => now(),
        ])->save();

        $this->sendCode($preCreate, $code);

        return $preCreate;
    }

    /**
     * Confere o código e, se bater, cria o `User` definitivo.
     *
     * @throws ValidationException quando o código está errado, expirado ou não há pré-cadastro
     * @throws PreRegistrationThrottled quando as tentativas daquele código se esgotaram
     */
    public function confirm(string $email, string $code): User
    {
        $preCreate = UserPreCreate::where('email', $email)->first();

        if ($preCreate === null || $preCreate->isConsumed()) {
            throw ValidationException::withMessages([
                'code' => 'Não há cadastro pendente para este e-mail. Preencha o formulário novamente.',
            ]);
        }

        if ($preCreate->isExpired()) {
            throw ValidationException::withMessages([
                'code' => 'Este código expirou. Peça um novo código.',
            ]);
        }

        if ($preCreate->attempts >= self::MAX_ATTEMPTS) {
            throw new PreRegistrationThrottled(
                'Muitas tentativas com este código. Peça um novo código.',
                $this->secondsUntilResend($preCreate)
            );
        }

        // Fora da transação de propósito: se o incremento ficasse dentro dela,
        // o rollback disparado pela exceção abaixo desfaria a contagem e o
        // limite de tentativas nunca seria atingido.
        if (! Hash::check($code, $preCreate->code_hash)) {
            $preCreate->increment('attempts');

            throw ValidationException::withMessages([
                'code' => 'Código inválido. Confira o e-mail e tente novamente.',
            ]);
        }

        // Só a criação do User entra na transação, com lock para que duas
        // confirmações simultâneas não gerem dois usuários para o mesmo e-mail.
        return DB::transaction(function () use ($email) {
            $preCreate = UserPreCreate::where('email', $email)->lockForUpdate()->first();

            if ($preCreate === null || $preCreate->isConsumed()) {
                throw ValidationException::withMessages([
                    'code' => 'Não há cadastro pendente para este e-mail. Preencha o formulário novamente.',
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

            $preCreate->forceFill(['consumed_at' => now()])->save();

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

    private function sendCode(UserPreCreate $preCreate, string $code): void
    {
        Mail::to($preCreate->email)->send(
            new PreRegisterCodeMail($preCreate->name, $code, self::CODE_TTL_MINUTES)
        );
    }
}
