<?php

namespace Tests\Unit;

use App\Support\InstallmentSchedule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Rateio de parcelas no backend. Os vetores são os mesmos que a conferência de
 * paridade usa contra `frontend/src/utils/installments.ts` (TASK-398): resto na
 * última parcela, em centavos, e meses somados SEMPRE a partir da data inicial,
 * com clamp de fim de mês. Ver docs/feature/20261008-rateio-parcelas-no-backend/plan.md §1.
 */
class InstallmentScheduleTest extends TestCase
{
    /**
     * @return list<float>
     */
    private function values(array $schedule): array
    {
        return array_column($schedule, 'value_quota');
    }

    /**
     * @return list<string>
     */
    private function dates(array $schedule): array
    {
        return array_column($schedule, 'date_expected');
    }

    /**
     * @return array<string, array{0: float|int|string, 1: int, 2: list<float>}>
     */
    public static function rateioVectors(): array
    {
        return [
            '100 em 3' => [100, 3, [33.33, 33.33, 33.34]],
            '10 em 3' => [10, 3, [3.33, 3.33, 3.34]],
            '0,10 em 3' => [0.10, 3, [0.03, 0.03, 0.04]],
            '1,00 em 3' => [1, 3, [0.33, 0.33, 0.34]],
            '1000,01 em 7' => [1000.01, 7, [142.85, 142.85, 142.85, 142.85, 142.85, 142.85, 142.91]],
            'total zero em 3' => [0, 3, [0.0, 0.0, 0.0]],
            'uma parcela leva o total inteiro' => [100, 1, [100.0]],
            'divisao exata, sem resto' => [300, 3, [100.0, 100.0, 100.0]],
            'total como string (como chega do request)' => ['100.00', 3, [33.33, 33.33, 33.34]],
        ];
    }

    /**
     * @dataProvider rateioVectors
     *
     * @param  list<float>  $expected
     */
    public function test_rateio_splits_in_cents_and_puts_the_remainder_in_the_last_installment(float|int|string $total, int $installments, array $expected): void
    {
        $schedule = InstallmentSchedule::build($total, $installments, '2026-08-15');

        $this->assertSame($expected, $this->values($schedule));
    }

    public function test_values_are_always_floats_even_when_the_division_is_exact(): void
    {
        foreach (InstallmentSchedule::build(300, 3, '2026-08-15') as $installment) {
            $this->assertIsFloat($installment['value_quota']);
        }
    }

    public function test_installments_sum_back_to_the_total_for_every_count_up_to_the_limit(): void
    {
        foreach ([0.01, 1, 10, 100, 1000.01, 99999.99] as $total) {
            $totalCents = (int) round($total * 100);

            for ($n = 1; $n <= InstallmentSchedule::MAX_INSTALLMENTS; $n++) {
                $schedule = InstallmentSchedule::build($total, $n, '2026-08-15');

                $this->assertCount($n, $schedule);
                $this->assertSame(
                    $totalCents,
                    (int) round(array_sum($this->values($schedule)) * 100),
                    "total {$total} em {$n} parcelas não fecha"
                );
            }
        }
    }

    public function test_numbers_go_from_one_to_the_installments_count(): void
    {
        $schedule = InstallmentSchedule::build(100, 5, '2026-08-15');

        $this->assertSame([1, 2, 3, 4, 5], array_column($schedule, 'number'));
    }

    public function test_dates_add_whole_months_to_the_start_date(): void
    {
        $schedule = InstallmentSchedule::build(300, 3, '2026-08-15');

        $this->assertSame(['2026-08-15', '2026-09-15', '2026-10-15'], $this->dates($schedule));
    }

    public function test_dates_clamp_to_the_end_of_shorter_months_counting_from_the_start_date(): void
    {
        // 31/01 + 1 = 28/02, mas + 2 = 31/03: o clamp é sempre a partir da data
        // inicial, não acumulado sobre a parcela anterior (que daria 28/03).
        $schedule = InstallmentSchedule::build(300, 3, '2026-01-31');

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $this->dates($schedule));
    }

    public function test_dates_clamp_to_the_leap_day_in_a_leap_year(): void
    {
        $schedule = InstallmentSchedule::build(200, 2, '2028-01-31');

        $this->assertSame(['2028-01-31', '2028-02-29'], $this->dates($schedule));
    }

    public function test_dates_roll_over_the_year(): void
    {
        $schedule = InstallmentSchedule::build(200, 2, '2026-12-31');

        $this->assertSame(['2026-12-31', '2027-01-31'], $this->dates($schedule));
    }

    public function test_the_last_of_many_installments_keeps_the_clamp_from_the_start_date(): void
    {
        $schedule = InstallmentSchedule::build(1300, 13, '2026-01-31');

        $this->assertSame('2027-01-31', $this->dates($schedule)[12]);
        $this->assertSame('2026-11-30', $this->dates($schedule)[10]);
    }

    public function test_zero_or_negative_installments_is_a_programming_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        InstallmentSchedule::build(100, 0, '2026-08-15');
    }

    public function test_max_installments_is_one_hundred_and_twenty(): void
    {
        $this->assertSame(120, InstallmentSchedule::MAX_INSTALLMENTS);
    }
}
