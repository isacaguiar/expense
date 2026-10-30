<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Services\ExpensePaymentService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * `ExpensePaymentService`: regras do pagamento registrado junto do cadastro da
 * despesa (feature 20261027-pagamento-no-cadastro-despesa, plan.md §4). Aqui só
 * o que não precisa de banco: os atributos de uma quota paga, qual é a ocorrência
 * inicial de cada tipo e se uma data cai em competência futura.
 */
class ExpensePaymentServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function service(): ExpensePaymentService
    {
        return app(ExpensePaymentService::class);
    }

    public function test_paid_attributes_are_exactly_the_payment_of_the_creditor(): void
    {
        Carbon::setTestNow('2026-10-19 12:00:00');

        $attributes = $this->service()->paidAttributes(7, 'comprovantes/3/compra.jpg', 'Cartão final 1234');

        $this->assertEquals(Carbon::parse('2026-10-19 12:00:00'), $attributes['paid_at']);

        unset($attributes['paid_at']);

        $this->assertSame([
            'paid' => true,
            'paid_by' => 7,
            'payment_proof_path' => 'comprovantes/3/compra.jpg',
            'payment_note' => 'Cartão final 1234',
            'born_paid' => false,
        ], $attributes);
    }

    public function test_paid_attributes_never_mark_the_quota_as_born_paid(): void
    {
        $this->assertFalse($this->service()->paidAttributes(1, null, 'pago')['born_paid']);
    }

    public function test_paid_attributes_keep_a_missing_proof_and_a_blank_note_as_null(): void
    {
        $withoutNote = $this->service()->paidAttributes(1, 'comprovantes/3/a.jpg', null);
        $blankNote = $this->service()->paidAttributes(1, null, "  \n ");

        $this->assertNull($withoutNote['payment_note']);
        $this->assertNull($blankNote['payment_note']);
        $this->assertNull($blankNote['payment_proof_path']);
    }

    public function test_the_note_zero_is_kept(): void
    {
        $this->assertSame('0', $this->service()->paidAttributes(1, null, '0')['payment_note']);
    }

    public function test_the_earliest_occurrence_of_an_in_cash_or_fixed_expense_is_its_date_payment(): void
    {
        foreach (['IN_CASH', 'FIXED'] as $type) {
            $date = $this->service()->earliestOccurrence($type, '2026-08-10', [
                ['number' => 1, 'date_expected' => '2026-08-12'],
            ]);

            $this->assertSame('2026-08-10', $date->toDateString(), $type);
        }
    }

    public function test_the_earliest_occurrence_of_an_installment_expense_is_its_earliest_quota(): void
    {
        $date = $this->service()->earliestOccurrence('IN_INSTALLMENTS', '2026-09-01', [
            ['number' => 3, 'date_expected' => '2026-11-10'],
            ['number' => 1, 'date_expected' => '2026-09-10'],
            ['number' => 2, 'date_expected' => '2026-10-10'],
        ]);

        $this->assertSame('2026-09-10', $date->toDateString());
    }

    public function test_the_earliest_occurrence_ignores_the_quota_order_and_a_repeated_number(): void
    {
        $date = $this->service()->earliestOccurrence('IN_INSTALLMENTS', '2026-09-01', [
            ['number' => 1, 'date_expected' => '2026-12-10'],
            ['number' => 1, 'date_expected' => '2026-10-10'],
            ['number' => '1', 'date_expected' => '2026-11-10'],
        ]);

        $this->assertSame('2026-10-10', $date->toDateString());
    }

    /**
     * @return array<string, array{0: ?int, 1: string, 2: string, 3: bool}>
     */
    public static function futureCases(): array
    {
        // Colunas: closing_day, "agora", data da ocorrência, é futura?
        return [
            'mês calendário: ciclo vigente' => [null, '2026-10-19', '2026-10-25', false],
            'mês calendário: hoje' => [null, '2026-10-19', '2026-10-19', false],
            'mês calendário: ciclo fechado' => [null, '2026-10-19', '2026-09-30', false],
            'mês calendário: ciclo em carência' => [null, '2026-10-03', '2026-09-20', false],
            'mês calendário: próximo ciclo' => [null, '2026-10-19', '2026-11-01', true],
            'mês calendário: dois ciclos à frente' => [null, '2026-10-19', '2026-12-15', true],
            'dia 15: dentro do ciclo vigente' => [15, '2026-10-19', '2026-11-10', false],
            'dia 15: ciclo seguinte' => [15, '2026-10-19', '2026-11-20', true],
            'dia 15: ciclo anterior em carência' => [15, '2026-10-19', '2026-10-10', false],
            'dia 15: ciclo anterior fechado' => [15, '2026-10-19', '2026-09-10', false],
        ];
    }

    /**
     * @dataProvider futureCases
     */
    public function test_is_future_follows_the_billing_cycle_of_the_group(?int $closingDay, string $now, string $date, bool $expected): void
    {
        Carbon::setTestNow($now);
        $group = new Group(['name' => 'Grupo', 'closing_day' => $closingDay]);

        $this->assertSame($expected, $this->service()->isFuture($group, Carbon::parse($date)));
    }
}
