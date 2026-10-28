<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\Notification;
use App\Models\Quota;
use App\Models\User;
use App\Support\Notifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Avisos do pagamento registrado no cadastro da despesa (feature
 * 20261027-pagamento-no-cadastro-despesa, plan.md §4, D4/D9 e R21):
 * `expensePaid` aceita o valor da compra inteira, e `expensePaidOnBehalf` avisa
 * o credor quando outra pessoa registrou o pagamento em nome dele. Os gatilhos
 * já existentes seguem cobertos por `NotifierTriggersTest`, que não é editado.
 */
class NotifierPaymentOnCreateTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Despesa de $creditor criada por $creator, dividida com um devedor.
     *
     * @return array{group: Group, creator: User, creditor: User, debtor: User, expense: Expense, quota: Quota}
     */
    private function scenario(bool $sameCreatorAndCreditor = false): array
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $creator = $sameCreatorAndCreditor ? $creditor : User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'República']);
        $group->members()->attach(array_unique([$creditor->id, $creator->id, $debtor->id]));

        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Geladeira',
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 3,
            'total_value' => 300,
            'group_id' => $group->id,
            'user_creator_id' => $creator->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);
        $expense->payers()->sync([$creditor->id, $debtor->id]);
        $quota = $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => true, 'value_quota' => 100]);

        return compact('group', 'creator', 'creditor', 'debtor', 'expense', 'quota');
    }

    public function test_expense_paid_with_an_amount_records_that_amount(): void
    {
        ['expense' => $expense, 'quota' => $quota, 'debtor' => $debtor] = $this->scenario(true);

        Notifier::expensePaid($expense, $quota, 300.0);

        $row = Notification::where('type', 'expense_paid')->where('user_id', $debtor->id)->firstOrFail();
        $this->assertSame('300.00', $row->data['amount']);
    }

    public function test_expense_paid_without_an_amount_keeps_the_quota_value(): void
    {
        ['expense' => $expense, 'quota' => $quota, 'debtor' => $debtor] = $this->scenario(true);

        Notifier::expensePaid($expense, $quota);

        $row = Notification::where('type', 'expense_paid')->where('user_id', $debtor->id)->firstOrFail();
        $this->assertSame('100.00', $row->data['amount']);
    }

    public function test_expense_paid_on_behalf_notifies_only_the_creditor(): void
    {
        ['group' => $group, 'creator' => $creator, 'creditor' => $creditor, 'expense' => $expense] = $this->scenario();

        Notifier::expensePaidOnBehalf($expense, 3);

        $rows = Notification::where('type', 'expense_paid_on_behalf')->get();

        $this->assertCount(1, $rows);
        $this->assertSame($creditor->id, $rows->first()->user_id);

        $data = $rows->first()->data;
        $this->assertSame($creator->name, $data['actorName']);
        $this->assertSame($group->id, $data['groupId']);
        $this->assertSame('República', $data['groupName']);
        $this->assertSame($expense->id, $data['expenseId']);
        $this->assertSame('Geladeira', $data['expenseDescription']);
        $this->assertSame(3, $data['quotasCount']);
    }

    public function test_expense_paid_on_behalf_does_nothing_when_the_creditor_is_the_creator(): void
    {
        ['expense' => $expense] = $this->scenario(true);

        Notifier::expensePaidOnBehalf($expense, 3);

        $this->assertSame(0, Notification::where('type', 'expense_paid_on_behalf')->count());
    }

    public function test_expense_paid_on_behalf_never_reaches_the_creator_or_the_debtor(): void
    {
        ['creator' => $creator, 'debtor' => $debtor, 'expense' => $expense] = $this->scenario();

        Notifier::expensePaidOnBehalf($expense, 1);

        $this->assertSame(0, Notification::where('type', 'expense_paid_on_behalf')->whereIn('user_id', [$creator->id, $debtor->id])->count());
    }
}
