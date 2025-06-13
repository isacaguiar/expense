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
    ];

    protected $casts = [
        'create_date'    => 'datetime',
        'date_payment'   => 'date',
        'total_value'    => 'decimal:2',
        'deleted'        => 'boolean',
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
