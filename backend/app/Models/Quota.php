<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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
        'payment_proof_path',
        'value_quota',
        'expense_id',
    ];

    protected $casts = [
        'date_expected' => 'date',
        'paid' => 'boolean',
        'paid_at' => 'datetime',
        'value_quota' => 'decimal:2',
    ];

    protected $appends = [
        'payment_proof_url',
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

    public function getPaymentProofUrlAttribute(): ?string
    {
        if (! $this->payment_proof_path) {
            return null;
        }

        return Storage::disk('public')->url($this->payment_proof_path);
    }
}
