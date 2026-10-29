<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasFactory;

    protected $table = 'ex_expenses';

    protected $fillable = [
        'create_date',
        'date_payment',
        'description',
        'expense_type',
        'installments',
        'total_value',
        'group_id',
        'user_creator_id',
        'user_payer_id',
        'deleted',
        'fixed_recurrence_ends_at',
        'client_request_id',
        'request_fingerprint',
    ];

    /**
     * Só servem à idempotência do cadastro (`store()`); o cliente não precisa
     * delas, e a impressão digital não deve sair da API.
     */
    protected $hidden = [
        'client_request_id',
        'request_fingerprint',
    ];

    protected $casts = [
        // Num `multipart` o valor chega como string, e o `Notifier` compara ids com `===`.
        'group_id' => 'integer',
        'user_creator_id' => 'integer',
        'user_payer_id' => 'integer',
        'create_date' => 'datetime',
        'date_payment' => 'date',
        'total_value' => 'decimal:2',
        'deleted' => 'boolean',
        'fixed_recurrence_ends_at' => 'date',
    ];

    /**
     * Relação com o grupo (ex_groups)
     */
    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    /**
     * Criador da despesa (usuário que registrou)
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'user_creator_id');
    }

    /**
     * Pagador da despesa (usuário que pagou)
     */
    public function payer()
    {
        return $this->belongsTo(User::class, 'user_payer_id');
    }

    public function payers()
    {
        return $this->belongsToMany(User::class, 'ex_expenses_payers', 'expense_id', 'user_id');
    }

    public function quotas()
    {
        return $this->hasMany(Quota::class, 'expense_id');
    }
}
