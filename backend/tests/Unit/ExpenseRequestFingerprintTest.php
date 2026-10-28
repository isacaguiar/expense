<?php

namespace Tests\Unit;

use App\Support\ExpenseRequestFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * Impressão digital do corpo do cadastro de despesa (feature
 * 20261027-pagamento-no-cadastro-despesa, plan.md §2, specify.md D7 e CA-34):
 * o mesmo cadastro reenviado tem que dar o mesmo hash, ainda que `payers`
 * chegue em outra ordem ou o valor venha como "10" no lugar de 10.00; e
 * qualquer dado que mude o sentido do cadastro tem que mudar o hash.
 */
class ExpenseRequestFingerprintTest extends TestCase
{
    /** @return array<string, mixed> */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'group_id' => 7,
            'date_payment' => '2026-08-10',
            'description' => 'Mercado',
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 2,
            'total_value' => 100.0,
            'user_payer_id' => 1,
            'payers' => [1, 2, 3],
            'quotas' => [
                ['number' => 1, 'date_expected' => '2026-08-10', 'value_quota' => 50.0, 'paid' => true],
                ['number' => 2, 'date_expected' => '2026-09-10', 'value_quota' => 50.0, 'paid' => false],
            ],
            'descricao_pagamento' => 'Cartão final 1234',
        ], $overrides);
    }

    private function hash(array $body, ?string $proof = null): string
    {
        return ExpenseRequestFingerprint::make($body, $proof);
    }

    public function test_it_returns_a_64_character_hex_string(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->hash($this->body()));
    }

    public function test_it_is_stable_for_the_same_body(): void
    {
        $this->assertSame($this->hash($this->body()), $this->hash($this->body()));
    }

    public function test_it_ignores_payers_order(): void
    {
        $this->assertSame(
            $this->hash($this->body(['payers' => [1, 2, 3]])),
            $this->hash($this->body(['payers' => [3, 1, 2]]))
        );
    }

    public function test_it_treats_string_10_and_float_10_00_alike(): void
    {
        $asFloat = $this->body(['total_value' => 10.0, 'installments' => 1, 'quotas' => [
            ['number' => 1, 'date_expected' => '2026-08-10', 'value_quota' => 10.0],
        ]]);
        $asString = $this->body(['total_value' => '10', 'installments' => '1', 'quotas' => [
            ['number' => '1', 'date_expected' => '2026-08-10', 'value_quota' => '10.00'],
        ], 'group_id' => '7', 'user_payer_id' => '1', 'payers' => ['1', '2', '3']]);

        $this->assertSame($this->hash($asFloat), $this->hash($asString));
    }

    public function test_it_ignores_edge_whitespace_in_texts(): void
    {
        $this->assertSame(
            $this->hash($this->body(['description' => 'Mercado', 'descricao_pagamento' => 'Cartão final 1234'])),
            $this->hash($this->body(['description' => "  Mercado\n", 'descricao_pagamento' => "\u{00A0}Cartão final 1234 "]))
        );
    }

    public function test_an_empty_blank_or_absent_payment_note_is_the_same(): void
    {
        $absent = $this->body();
        unset($absent['descricao_pagamento']);

        $this->assertSame($this->hash($absent), $this->hash($this->body(['descricao_pagamento' => null])));
        $this->assertSame($this->hash($absent), $this->hash($this->body(['descricao_pagamento' => ''])));
        $this->assertSame($this->hash($absent), $this->hash($this->body(['descricao_pagamento' => " \t\n "])));
    }

    public function test_it_ignores_quotas_order_and_the_paid_flag_the_client_sends(): void
    {
        $reordered = $this->body(['quotas' => [
            ['number' => 2, 'date_expected' => '2026-09-10', 'value_quota' => 50.0, 'paid' => true],
            ['number' => 1, 'date_expected' => '2026-08-10', 'value_quota' => 50.0, 'paid' => false],
        ]]);

        $this->assertSame($this->hash($this->body()), $this->hash($reordered));
    }

    public function test_it_normalizes_dates_to_y_m_d(): void
    {
        $this->assertSame(
            $this->hash($this->body(['date_payment' => '2026-08-10'])),
            $this->hash($this->body(['date_payment' => '2026-08-10T00:00:00.000000Z']))
        );
    }

    public function test_a_body_without_quotas_is_not_the_same_as_one_with_them(): void
    {
        $without = $this->body();
        unset($without['quotas']);

        $this->assertNotSame($this->hash($this->body()), $this->hash($without));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: ?string}>
     */
    public static function meaningfulChanges(): array
    {
        return [
            'valor total' => [['total_value' => 100.01], null],
            'valor de uma parcela' => [['quotas' => [
                ['number' => 1, 'date_expected' => '2026-08-10', 'value_quota' => 49.99],
                ['number' => 2, 'date_expected' => '2026-09-10', 'value_quota' => 50.0],
            ]], null],
            'data da despesa' => [['date_payment' => '2026-08-11'], null],
            'data de uma parcela' => [['quotas' => [
                ['number' => 1, 'date_expected' => '2026-08-10', 'value_quota' => 50.0],
                ['number' => 2, 'date_expected' => '2026-09-11', 'value_quota' => 50.0],
            ]], null],
            'credor' => [['user_payer_id' => 2], null],
            'participante a menos' => [['payers' => [1, 2]], null],
            'participante diferente' => [['payers' => [1, 2, 4]], null],
            'grupo' => [['group_id' => 8], null],
            'descrição da despesa' => [['description' => 'Padaria'], null],
            'tipo' => [['expense_type' => 'IN_CASH', 'installments' => 2], null],
            'número de parcelas' => [['installments' => 3], null],
            'descrição do pagamento' => [['descricao_pagamento' => 'Cartão final 9999'], null],
            'foto' => [[], str_repeat('a', 64)],
        ];
    }

    /**
     * @dataProvider meaningfulChanges
     */
    public function test_it_changes_with_value_date_payer_participant_or_photo(array $change, ?string $proof): void
    {
        $this->assertNotSame(
            $this->hash($this->body()),
            $this->hash($this->body($change), $proof)
        );
    }

    public function test_two_different_photos_give_different_hashes(): void
    {
        $this->assertNotSame(
            $this->hash($this->body(), str_repeat('a', 64)),
            $this->hash($this->body(), str_repeat('b', 64))
        );
    }

    public function test_a_body_with_a_photo_differs_from_one_without(): void
    {
        $this->assertNotSame($this->hash($this->body(), null), $this->hash($this->body(), str_repeat('a', 64)));
    }
}
