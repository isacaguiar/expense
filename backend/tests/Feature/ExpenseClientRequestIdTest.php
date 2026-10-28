<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Colunas de idempotência de `ex_expenses` (feature
 * 20261027-pagamento-no-cadastro-despesa, plan.md §2 e specify.md D7): a chave
 * de cada cadastro e a impressão digital do corpo, com índice único por
 * (criador, chave). Ambas nullable: despesa sem chave convive à vontade.
 */
class ExpenseClientRequestIdTest extends TestCase
{
    use DatabaseTransactions;

    private function expenseFor(Group $group, User $creator, array $overrides = []): Expense
    {
        return Expense::create(array_merge([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $creator->id,
            'user_payer_id' => $creator->id,
            'deleted' => false,
        ], $overrides));
    }

    public function test_the_idempotency_columns_exist_and_are_nullable(): void
    {
        $this->assertTrue(Schema::hasColumns('ex_expenses', ['client_request_id', 'request_fingerprint']));

        $group = Group::create(['name' => 'Grupo']);
        $creator = User::factory()->create();

        $expense = $this->expenseFor($group, $creator);

        $this->assertNull($expense->fresh()->client_request_id);
        $this->assertNull($expense->fresh()->request_fingerprint);
    }

    public function test_the_columns_can_be_filled_through_the_model(): void
    {
        $group = Group::create(['name' => 'Grupo']);
        $creator = User::factory()->create();
        $key = (string) Str::uuid();
        $fingerprint = hash('sha256', 'corpo');

        $expense = $this->expenseFor($group, $creator, ['client_request_id' => $key, 'request_fingerprint' => $fingerprint]);

        $this->assertSame($key, $expense->fresh()->client_request_id);
        $this->assertSame($fingerprint, $expense->fresh()->request_fingerprint);
    }

    public function test_several_expenses_of_the_same_creator_without_a_key_coexist(): void
    {
        $group = Group::create(['name' => 'Grupo']);
        $creator = User::factory()->create();

        $this->expenseFor($group, $creator);
        $this->expenseFor($group, $creator);

        $this->assertSame(2, Expense::where('user_creator_id', $creator->id)->whereNull('client_request_id')->count());
    }

    public function test_the_same_creator_and_key_pair_is_rejected_with_a_duplicate_key_error(): void
    {
        $group = Group::create(['name' => 'Grupo']);
        $creator = User::factory()->create();
        $key = (string) Str::uuid();

        $this->expenseFor($group, $creator, ['client_request_id' => $key]);

        try {
            $this->expenseFor($group, $creator, ['client_request_id' => $key]);
            $this->fail('o par (criador, chave) repetido deveria violar o índice único');
        } catch (QueryException $e) {
            $this->assertSame(1062, $e->errorInfo[1], 'esperado erro 1062 (chave duplicada) do MySQL');
        }
    }

    public function test_the_same_key_from_different_creators_coexists(): void
    {
        $group = Group::create(['name' => 'Grupo']);
        $key = (string) Str::uuid();

        $this->expenseFor($group, User::factory()->create(), ['client_request_id' => $key]);
        $this->expenseFor($group, User::factory()->create(), ['client_request_id' => $key]);

        $this->assertSame(2, Expense::where('client_request_id', $key)->count());
    }
}
