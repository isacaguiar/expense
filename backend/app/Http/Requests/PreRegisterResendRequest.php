<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Pedido de um novo código para um pré-cadastro que já existe. */
class PreRegisterResendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
        ];
    }
}
