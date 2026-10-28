<?php

namespace App\Services;

use App\Models\Group;
use App\Support\BillingCycle;
use Carbon\Carbon;

/**
 * Regras do pagamento registrado junto do cadastro da despesa
 * (docs/feature/20261027-pagamento-no-cadastro-despesa, plan.md §4 e §5).
 *
 * Esta classe guarda a regra de negócio; o `ExpenseController` orquestra
 * (Constitution §1 item 3). Nada aqui grava arquivo nem notifica.
 */
class ExpensePaymentService
{
    /**
     * Atributos de uma quota paga no cadastro (specify R4): o pagamento é do
     * credor, `paid_at` é o momento do cadastro (o mesmo em todas as quotas da
     * compra, quem chama reutiliza o array) e `born_paid` fica falso: `born_paid`
     * nunca representa um pagamento comum.
     *
     * @return array{paid: bool, paid_at: Carbon, paid_by: int, payment_proof_path: ?string, payment_note: ?string, born_paid: bool}
     */
    public function paidAttributes(int $creditorId, ?string $proofPath, ?string $note): array
    {
        return [
            'paid' => true,
            'paid_at' => Carbon::now(),
            'paid_by' => $creditorId,
            'payment_proof_path' => $proofPath,
            // `filled()`, não `empty()`: `empty("0")` descartaria a descrição "0".
            'payment_note' => filled($note) ? $note : null,
            'born_paid' => false,
        ];
    }

    /**
     * A ocorrência inicial da despesa, a que não pode cair em competência futura
     * (specify R6): `date_payment` em À Vista e Fixa; a menor `date_expected` na
     * Parcelada, sem confiar na ordem nem no `number` das quotas que o cliente
     * enviou (item 079 do backlog).
     *
     * @param  array<int, array{date_expected: string, number?: int|string}>  $quotas
     */
    public function earliestOccurrence(string $type, string $datePayment, array $quotas): Carbon
    {
        if ($type !== 'IN_INSTALLMENTS' || $quotas === []) {
            return Carbon::parse($datePayment);
        }

        return collect($quotas)
            ->map(fn (array $quota) => Carbon::parse($quota['date_expected']))
            ->sort()
            ->first();
    }

    /**
     * Se `$date` cai numa competência que ainda não começou, em relação a "agora".
     * É a regra de `pay()`, que só alcança a competência vigente ou passada
     * (`cycles_ago >= 0`): não existe pagamento antecipado (specify R6, D5).
     */
    public function isFuture(Group $group, Carbon $date): bool
    {
        return BillingCycle::statusFor($group->closing_day, $date, Carbon::now()) === 'future';
    }
}
