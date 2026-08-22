<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quota extends Model
{
    use HasFactory;

    protected $table = 'ex_quotas';

    protected $fillable = [
        'date_expected',
        'number',
        'paid',
        'paid_at',
        'paid_by',
        'value_quota',
        'expense_id',
    ];

    protected $casts = [
        'date_expected' => 'date',
        'paid' => 'boolean',
        'paid_at' => 'datetime',
        'value_quota' => 'decimal:2',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }

    public function participations()
    {
        return $this->hasMany(Participation::class, 'quota_id');
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
