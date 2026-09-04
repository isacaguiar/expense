<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

/**
 * Leitura e marcação de lida das notificações in-app do usuário autenticado.
 * O escopo de autorização é sempre o próprio destinatário (`user_id`), não um
 * grupo — por isso não há `authorizeGroupMembership` aqui.
 * Ver docs/feature/20260903-notificacoes-in-app/plan.md §2.
 */
class NotificationController extends Controller
{
    /**
     * Lista paginada das notificações do usuário autenticado, mais recente
     * primeiro. Envelope cru do `LengthAwarePaginator` (mesmo formato de
     * `ExpenseController::cycleHistory`, já consumido pelo frontend).
     */
    public function index(Request $request)
    {
        return Notification::where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    /**
     * Contagem de notificações não-lidas do usuário autenticado — endpoint
     * barato, alvo do polling do sino no cabeçalho.
     */
    public function unreadCount()
    {
        return response()->json([
            'count' => Notification::where('user_id', auth()->id())
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    /**
     * Marca notificações do usuário autenticado como lidas: todas as não-lidas
     * por padrão, ou só a de `id` quando informado. `id` de notificação que não
     * é do usuário responde 404 (não confirma a existência de notificação
     * alheia).
     */
    public function markRead(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer',
        ]);

        $query = Notification::where('user_id', auth()->id())
            ->whereNull('read_at');

        if (! empty($data['id'])) {
            $belongsToUser = Notification::where('user_id', auth()->id())
                ->where('id', $data['id'])
                ->exists();

            abort_unless($belongsToUser, 404);

            $query->where('id', $data['id']);
        }

        $query->update(['read_at' => now()]);

        return response()->json(['message' => 'ok']);
    }
}
