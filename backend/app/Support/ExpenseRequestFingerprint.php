<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Impressão digital do corpo de um cadastro de despesa, para a idempotência de
 * `POST /api/expenses` (docs/feature/concluidas/202610/20261027-pagamento-no-cadastro-despesa,
 * plan.md §2 e specify.md D7). Dada a mesma chave `client_request_id`, o
 * servidor compara este hash com o que gravou: igual é reenvio e devolve a
 * despesa que já existe; diferente é reuso da chave com outros dados (409).
 *
 * Classe pura, no estilo de `BillingCycle`: array entra, string sai, sem banco
 * nem HTTP. O hash é calculado sobre os campos NORMALIZADOS, para o mesmo
 * cadastro dar o mesmo resultado ainda que `payers` chegue em outra ordem, o
 * valor venha como "10" no lugar de 10.00, ou haja espaços nas pontas dos
 * textos. Fora da identidade ficam o `paid` que o cliente manda nas quotas
 * (o servidor o ignora) e o `user_creator_id` do corpo (o servidor usa o
 * usuário autenticado).
 */
class ExpenseRequestFingerprint
{
    /**
     * @param  array<string, mixed>  $input  corpo validado do cadastro
     * @param  string|null  $proofSha256  sha-256 do CONTEÚDO da foto, ou null sem foto
     */
    public static function make(array $input, ?string $proofSha256): string
    {
        $quotas = null;

        if (isset($input['quotas']) && is_array($input['quotas'])) {
            $quotas = array_map(fn (array $quota) => [
                'date_expected' => self::date($quota['date_expected'] ?? null),
                'number' => (int) ($quota['number'] ?? 0),
                'value_quota' => self::cents($quota['value_quota'] ?? 0),
            ], array_values($input['quotas']));

            usort($quotas, fn (array $a, array $b) => [$a['date_expected'], $a['number']] <=> [$b['date_expected'], $b['number']]);
        }

        $payers = array_map('intval', array_values($input['payers'] ?? []));
        sort($payers);

        $normalized = [
            'group_id' => (int) ($input['group_id'] ?? 0),
            'date_payment' => self::date($input['date_payment'] ?? null),
            'description' => self::text($input['description'] ?? null),
            'expense_type' => (string) ($input['expense_type'] ?? ''),
            'installments' => (int) ($input['installments'] ?? 0),
            'total_value' => self::cents($input['total_value'] ?? 0),
            'user_payer_id' => (int) ($input['user_payer_id'] ?? 0),
            'payers' => $payers,
            'quotas' => $quotas,
            'descricao_pagamento' => self::text($input['descricao_pagamento'] ?? null),
            'proof' => $proofSha256,
        ];

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Centavos inteiros, com o mesmo arredondamento de `InstallmentSchedule::build`. */
    private static function cents(mixed $value): int
    {
        return (int) round(round((float) $value, 2) * 100);
    }

    private static function date(mixed $value): ?string
    {
        return blank($value) ? null : Carbon::parse($value)->toDateString();
    }

    /** Texto aparado nas pontas (inclui NBSP e espaços Unicode); vazio vira null. */
    private static function text(mixed $value): ?string
    {
        $trimmed = preg_replace('/^[\s\p{Z}\x{200B}\x{FEFF}]+|[\s\p{Z}\x{200B}\x{FEFF}]+$/u', '', (string) ($value ?? ''));

        return ($trimmed === null || $trimmed === '') ? null : $trimmed;
    }
}
