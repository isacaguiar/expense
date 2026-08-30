<?php

namespace App\Support\WhatsApp;

use App\Models\Quota;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Traduz um evento de comprovante de pagamento em mensagens de WhatsApp para
 * as contrapartes que optaram por receber (`whatsapp` preenchido +
 * `notify_whatsapp = true`).
 *
 * - `expenseProofPaid`: o credor anexou o comprovante ao marcar a despesa
 *   como paga (`ExpenseController@pay`) → avisa os pagadores, menos o credor.
 * - `settlementProofConfirmed`: o devedor confirmou o acerto do ciclo com
 *   comprovante (`ExpenseController@confirmSettlement`) → avisa o credor.
 *
 * Envio é best-effort: falha de um destinatário só vira `Log::warning` e não
 * impede os demais nem a ação que disparou. No-op se a feature está desligada
 * (`services.whatsapp.enabled`).
 */
class WhatsAppNotifier
{
    private const MESES = [
        1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr', 5 => 'mai', 6 => 'jun',
        7 => 'jul', 8 => 'ago', 9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez',
    ];

    /** Rótulos pt_BR de `expense_type` — espelham os do frontend (`ExpenseForm`). */
    private const TIPO_LABEL = [
        'IN_CASH' => 'À Vista',
        'IN_INSTALLMENTS' => 'Parcelada',
        'FIXED' => 'Fixa',
    ];

    public static function expenseProofPaid(Quota $quota): void
    {
        if (! config('services.whatsapp.enabled')) {
            return;
        }

        $expense = $quota->expense;

        $recipients = self::optedIn(
            $expense->payers->reject(fn (User $u) => $u->id === $expense->user_payer_id)
        );

        if ($recipients->isEmpty()) {
            return;
        }

        self::sendToAll(
            $recipients,
            config('services.whatsapp.templates.expense_proof'),
            [
                $expense->payer->name,
                $expense->description,
                self::TIPO_LABEL[$expense->expense_type] ?? $expense->expense_type,
                self::brl($quota->value_quota),
                self::competencia($quota->date_expected),
            ],
            "groups/{$expense->group_id}/expenses/{$expense->id}"
        );
    }

    public static function settlementProofConfirmed(SettlementConfirmation $confirmation): void
    {
        if (! config('services.whatsapp.enabled')) {
            return;
        }

        $creditor = $confirmation->toUser;

        $recipients = self::optedIn(collect(array_filter([$creditor])));

        if ($recipients->isEmpty()) {
            return;
        }

        self::sendToAll(
            $recipients,
            config('services.whatsapp.templates.settlement_proof'),
            [
                $confirmation->fromUser?->name ?? '',
                self::brl($confirmation->amount),
                self::competencia($confirmation->cycle_start),
            ],
            "groups/{$confirmation->group_id}/payments"
        );
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    private static function optedIn(Collection $users): Collection
    {
        return $users
            ->filter(fn (User $u) => filled($u->whatsapp) && $u->notify_whatsapp === true)
            ->values();
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @param  array<int, string>  $bodyParams
     */
    private static function sendToAll(Collection $recipients, string $template, array $bodyParams, string $buttonPath): void
    {
        $components = [
            [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($text) => ['type' => 'text', 'text' => (string) $text],
                    $bodyParams
                ),
            ],
            [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => '0',
                'parameters' => [['type' => 'text', 'text' => $buttonPath]],
            ],
        ];

        $locale = config('services.whatsapp.locale');

        foreach ($recipients as $user) {
            $phone = PhoneNumber::toApiFormat($user->whatsapp);

            if ($phone === null) {
                Log::warning('WhatsApp: número inválido, notificação ignorada', ['user_id' => $user->id]);

                continue;
            }

            try {
                MetaCloudClient::sendTemplate($phone, $template, $locale, $components);
            } catch (\Throwable $e) {
                Log::warning('WhatsApp: falha ao enviar notificação', [
                    'user_id' => $user->id,
                    'template' => $template,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private static function brl(float|string $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }

    private static function competencia(mixed $date): string
    {
        $carbon = Carbon::parse($date);

        return self::MESES[$carbon->month].'/'.$carbon->year;
    }
}
