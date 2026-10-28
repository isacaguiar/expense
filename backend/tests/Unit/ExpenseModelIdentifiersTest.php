<?php

namespace Tests\Unit;

use App\Models\Expense;
use PHPUnit\Framework\TestCase;

/**
 * Achados N4 e N5 da revisão de segurança da feature
 * 20261027-pagamento-no-cadastro-despesa (TASK-547): num `multipart` os ids
 * chegam como string e o `Notifier` os compara com `===`; e as colunas de
 * idempotência do cadastro não saem na API.
 */
class ExpenseModelIdentifiersTest extends TestCase
{
    public function test_the_ids_read_back_as_integers_even_when_assigned_as_strings(): void
    {
        $expense = new Expense([
            'group_id' => '7',
            'user_creator_id' => '12',
            'user_payer_id' => '12',
        ]);

        $this->assertSame(7, $expense->group_id);
        $this->assertSame(12, $expense->user_creator_id);
        $this->assertSame(12, $expense->user_payer_id);
    }

    public function test_the_idempotency_columns_are_left_out_of_the_serialized_expense(): void
    {
        $expense = new Expense([
            'description' => 'Mercado',
            'client_request_id' => '0b0e7a2e-7d5e-4a3e-9d6b-1f1f6a0c9a10',
            'request_fingerprint' => str_repeat('a', 64),
        ]);

        $this->assertSame('Mercado', $expense->toArray()['description']);
        $this->assertArrayNotHasKey('client_request_id', $expense->toArray());
        $this->assertArrayNotHasKey('request_fingerprint', $expense->toArray());
        $this->assertSame('0b0e7a2e-7d5e-4a3e-9d6b-1f1f6a0c9a10', $expense->client_request_id, 'continua legível para o servidor');
    }
}
