<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

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

    /**
     * `expense` é usada pelo accessor `payment_proof_url` (precisa de
     * `expense->group_id`) e pode ser pré-carregada via `setRelation` nos
     * pontos que serializam Quota junto com o Expense pai — escondê-la evita
     * a recursão Expense→quotas→expense→quotas na serialização.
     */
    protected $hidden = [
        'expense',
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

    /**
     * URL assinada de curta duração para baixar o comprovante pela rota
     * `proofs.show` (fora do `jwt.auth` — a aba do browser não manda Bearer).
     * Ver `docs/sdd/decisions/ADR-005-download-arquivo-signed-url.md`.
     */
    public function getPaymentProofUrlAttribute(): ?string
    {
        if (! $this->payment_proof_path) {
            return null;
        }

        return URL::temporarySignedRoute('proofs.show', now()->addMinutes(30), [
            'groupId' => $this->expense->group_id,
            'type' => 'quota',
            'id' => $this->id,
        ]);
    }
}
