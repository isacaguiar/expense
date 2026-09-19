<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pré-cadastro de usuário: os dados que alguém preencheu no formulário de
 * `/cadastro` ficam aqui até o e-mail ser confirmado pelo código de 6 dígitos.
 * Só então um `User` real nasce (com `email_verified_at` preenchido) e a linha
 * é marcada como consumida — nunca apagada, porque hard delete é gate humano
 * (docs/sdd/00-constitution.md §5.2).
 *
 * `password` chega aqui já hasheado e `code_hash` guarda só o hash do código:
 * nem a senha nem o código existem em claro nesta tabela.
 * Ver docs/feature/20260919-cadastro-de-usuarios/plan.md §1.
 */
class UserPreCreate extends Model
{
    protected $table = 'ex_user_pre_create';

    protected $fillable = [
        'name',
        'email',
        'whatsapp',
        'password',
        'code_hash',
        'attempts',
        'expires_at',
        'last_sent_at',
        'consumed_at',
    ];

    /** Nunca serializar o material sensível, mesmo que a linha vaze para uma resposta. */
    protected $hidden = [
        'password',
        'code_hash',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    /** O código já foi usado para criar o User correspondente. */
    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    /** O código passou da validade e não serve mais. */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
