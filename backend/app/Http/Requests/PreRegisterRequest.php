<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Formulário de `/cadastro`. `confirmed` em `email` e `password` exige os
 * campos `email_confirmation` e `password_confirmation`.
 *
 * Primeiro FormRequest do projeto — a validação inline (`$request->validate`)
 * continua sendo o padrão nos controllers antigos; aqui a regra cresceu o
 * suficiente para justificar a camada prevista em docs/sdd/06-context-backend.md.
 */
class PreRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:255|confirmed|unique:ex_users,email',
            // Mesmo formato já validado em UserController@updateProfile, para
            // não criar um segundo padrão de telefone no sistema.
            'whatsapp' => 'nullable|string|regex:/^\(\d{2}\) 9\d{4}-\d{4}$/',
            'password' => 'required|string|min:6|confirmed',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe seu nome.',
            'email.required' => 'Informe seu e-mail.',
            'email.email' => 'Informe um e-mail válido.',
            'email.confirmed' => 'Os e-mails não conferem.',
            'email.unique' => 'Este e-mail já está cadastrado.',
            'whatsapp.regex' => 'Informe o telefone no formato (11) 91234-5678.',
            'password.required' => 'Informe uma senha.',
            'password.min' => 'A senha precisa ter ao menos 6 caracteres.',
            'password.confirmed' => 'As senhas não conferem.',
        ];
    }
}
