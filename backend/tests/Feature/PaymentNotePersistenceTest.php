<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Colunas de descrição do pagamento (docs/feature/concluidas/202610/20261021-comprovante-ao-marcar-paga,
 * plan.md §1): `ex_quotas.payment_note` e `ex_settlement_confirmations.note`.
 */
class PaymentNotePersistenceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_columns_exist_with_the_expected_length(): void
    {
        $this->assertTrue(Schema::hasColumn('ex_quotas', 'payment_note'));
        $this->assertTrue(Schema::hasColumn('ex_settlement_confirmations', 'note'));

        foreach ([['ex_quotas', 'payment_note'], ['ex_settlement_confirmations', 'note']] as [$table, $column]) {
            $info = \DB::selectOne(
                'SELECT CHARACTER_MAXIMUM_LENGTH AS len, IS_NULLABLE AS nullable FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );

            $this->assertSame(500, (int) $info->len, "{$table}.{$column} deve ser varchar(500)");
            $this->assertSame('YES', $info->nullable, "{$table}.{$column} deve ser nullable");
        }
    }

    public function test_quota_persists_payment_note_by_mass_assignment(): void
    {
        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-01',
            'description' => 'Despesa',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);

        $quota = $expense->quotas()->create([
            'date_expected' => '2026-08-01',
            'number' => 1,
            'paid' => true,
            'value_quota' => 100,
            'payment_note' => 'Pago em dinheiro, recibo 123',
        ]);

        $this->assertSame('Pago em dinheiro, recibo 123', $quota->fresh()->payment_note);
        $this->assertSame('Pago em dinheiro, recibo 123', $quota->fresh()->toArray()['payment_note']);
    }

    public function test_settlement_confirmation_persists_note_by_mass_assignment(): void
    {
        $debtor = User::factory()->create();
        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$debtor->id, $creditor->id]);

        $confirmation = SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'amount' => 50,
            'proof_path' => "comprovantes/{$group->id}/x.jpg",
            'note' => 'Pix agendado para sexta',
            'confirmed_at' => now(),
        ]);

        $this->assertSame('Pix agendado para sexta', $confirmation->fresh()->note);
    }
}
