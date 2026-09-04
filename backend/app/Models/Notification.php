<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notificação in-app de um evento do grupo (pagamento, ciclo, membro, despesa),
 * entregue a um usuário destinatário — uma linha por destinatário, com fan-out
 * feito no momento da escrita pelo serviço `App\Support\Notifier`. Consumida
 * pelo `NotificationController` (sino do cabeçalho, com polling da contagem de
 * não-lidas). Ver docs/feature/concluidas/202609/20260903-notificacoes-in-app/plan.md §1.
 *
 * Namespace explícito para não colidir com `Illuminate\Notifications\Notification`
 * (a stack nativa de notificações do Laravel não é usada neste projeto).
 */
class Notification extends Model
{
    protected $table = 'ex_notifications';

    protected $fillable = [
        'user_id',
        'type',
        'group_id',
        'data',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    /** Destinatário da notificação. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Grupo a que a notificação se refere (nulo para notificações fora de grupo). */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
