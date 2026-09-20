<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail com o código de 6 dígitos que confirma o endereço de quem preencheu
 * o formulário de `/cadastro`. Enviado por `PreRegistrationService::start()` e
 * por `resend()`. Ver docs/feature/20260919-cadastro-de-usuarios/plan.md §5.
 *
 * Recebe o código em claro (que não existe em lugar nenhum além deste e-mail —
 * a tabela guarda só o hash) e nunca o registra em log.
 */
class PreRegisterCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $name;

    public $code;

    public $expiresInMinutes;

    public function __construct(string $name, string $code, int $expiresInMinutes)
    {
        $this->name = $name;
        $this->code = $code;
        $this->expiresInMinutes = $expiresInMinutes;
    }

    public function build()
    {
        return $this->subject('Seu código de confirmação — Shared Expense')
            ->view('email.pre-register-code')
            ->with([
                'name' => $this->name,
                'code' => $this->code,
                'expiresInMinutes' => $this->expiresInMinutes,
            ]);
    }
}
