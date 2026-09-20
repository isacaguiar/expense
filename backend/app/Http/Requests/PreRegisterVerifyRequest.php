<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmação do código de 6 dígitos enviado por e-mail.
 *
 * Não usa `exists:ex_user_pre_create` de propósito: se o pré-cadastro não
 * existe, quem responde é o Service, com a mesma mensagem de código inválido —
 * assim a validação não vira um oráculo de quais e-mails têm cadastro pendente.
 */
class PreRegisterVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'handle' => 'required|string',
            'code' => 'required|string|digits:6',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Informe o código recebido por e-mail.',
            'code.digits' => 'O código tem 6 dígitos.',
            'handle.required' => 'Sessão de cadastro perdida. Preencha o formulário novamente.',
        ];
    }
}
