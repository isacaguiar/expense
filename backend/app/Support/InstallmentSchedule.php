<?php

namespace App\Support;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Rateio de uma despesa em parcelas mensais: divide o total em centavos, joga o
 * resto na última parcela e soma os meses SEMPRE a partir da data inicial, com
 * clamp de fim de mês (31/01 + 1 mês = 28/02, mas + 2 meses = 31/03).
 *
 * É a regra que o frontend web monta hoje em `frontend/src/utils/installments.ts`
 * (`buildInstallmentQuotas`/`addMonthsClamped`); o resultado precisa ser idêntico
 * enquanto os dois existirem. À Vista e Fixa usam o mesmo caminho com uma parcela.
 * Ver docs/feature/concluidas/202610/20261008-rateio-parcelas-no-backend/plan.md §1.
 */
class InstallmentSchedule
{
    /**
     * Teto de parcelas quando é o servidor quem as gera. Impede que um payload
     * pequeno peça milhares de linhas; quem envia `quotas` próprias não passa
     * por aqui. Usado nas regras de validação dos controllers — esta classe não
     * o impõe, para o rateio continuar testável com qualquer N.
     */
    public const MAX_INSTALLMENTS = 120;

    /**
     * Último ano que a coluna `date` do MySQL comporta. Um rateio cuja última
     * parcela passa dele grava datas erradas em silêncio (10000-01-31 vira
     * 2000-01-31), por isso os controllers conferem `fits()` antes de `build()`.
     */
    public const MAX_YEAR = 9999;

    /**
     * Se a última de `$installments` parcelas, a partir de `$startDate`, ainda cabe
     * na coluna `date`.
     */
    public static function fits(string $startDate, int $installments): bool
    {
        return Carbon::parse($startDate)->addMonthsNoOverflow(max($installments, 1) - 1)->year <= self::MAX_YEAR;
    }

    /**
     * @param  float|int|string  $totalValue  Valor total da despesa.
     * @param  int  $installments  Quantidade de parcelas (>= 1).
     * @param  string  $startDate  Data da primeira parcela (`Y-m-d`).
     * @return list<array{number: int, date_expected: string, value_quota: float}>
     *
     * @throws InvalidArgumentException Se `$installments` for menor que 1: é erro
     *                                  de programação, os controllers validam antes.
     */
    public static function build(float|int|string $totalValue, int $installments, string $startDate): array
    {
        if ($installments < 1) {
            throw new InvalidArgumentException('O número de parcelas deve ser pelo menos 1.');
        }

        // O primeiro round(…, 2) trata entradas com mais de 2 casas como o banco
        // (decimal(38,2)) e como `ExpenseController::update()` já fazem; para até 2
        // casas, que é o que a UI produz, equivale ao Math.round(total * 100) do web.
        $totalCents = (int) round(round((float) $totalValue, 2) * 100);
        $baseCents = intdiv($totalCents, $installments);
        $remainderCents = $totalCents - ($baseCents * $installments);
        $start = Carbon::parse($startDate);

        $schedule = [];

        for ($number = 1; $number <= $installments; $number++) {
            $cents = $baseCents + ($number === $installments ? $remainderCents : 0);

            $schedule[] = [
                'number' => $number,
                'date_expected' => $start->copy()->addMonthsNoOverflow($number - 1)->toDateString(),
                // (float): `/` devolve int quando a divisão é exata, e o contrato é float.
                'value_quota' => (float) ($cents / 100),
            ];
        }

        return $schedule;
    }
}
