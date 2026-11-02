<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use App\Services\MemberExitBlockers;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\BuildsPurchaseScenarios;
use Tests\TestCase;

/**
 * MemberExitBlockers: o que impede alguém de sair (ou ser removido) de um grupo,
 * decisão D3-B de docs/feature/20261030-saida-e-remocao-de-membro/specify.md.
 *
 * Só bloqueia o que corrompe o fluxo depois da saída: o **credor** com parcela
 * não paga de outra pessoa (só ele confirma o pagamento, e confirmar exige ser
 * membro) e quem está numa despesa **fixa ativa** (continuaria sendo cobrado
 * todo mês). Quem apenas **deve** uma parcela avulsa ou parcelada sai livre.
 */
class MemberExitBlockersTest extends TestCase
{
    use BuildsPurchaseScenarios;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // closing_day nulo → competência = mês calendário; "agora" fixo em outubro/2026.
        Carbon::setTestNow('2026-10-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function blockers(Group $group, User $user): array
    {
        return app(MemberExitBlockers::class)->for($group, $user->id);
    }

    public function test_a_debtor_of_an_installment_purchase_is_not_blocked(): void
    {
        ['group' => $group, 'users' => [$creditor, $debtor, $other]] = $this->makeGroup(3);
        $this->makePurchase($group, $creditor, [$creditor, $debtor, $other], 'IN_INSTALLMENTS', 300.00, '2026-10-10', 3);

        $this->assertSame([], $this->blockers($group, $debtor));
    }

    public function test_a_creditor_with_unpaid_future_installments_of_others_is_blocked(): void
    {
        ['group' => $group, 'users' => [$creditor, $debtor]] = $this->makeGroup(2);
        // Só parcelas de dezembro e janeiro: nenhuma no ciclo corrente, todas ainda sem confirmar.
        $expense = $this->makePurchase($group, $creditor, [$creditor, $debtor], 'IN_INSTALLMENTS', 200.00, '2026-12-10', 2);

        $this->assertSame(
            [['expense_id' => $expense->id, 'description' => 'Compra de teste', 'reason' => 'creditor_open_quotas']],
            $this->blockers($group, $creditor)
        );
    }

    public function test_a_creditor_who_is_the_only_participant_is_not_blocked(): void
    {
        ['group' => $group, 'users' => [$creditor]] = $this->makeGroup(1);
        $this->makePurchase($group, $creditor, [$creditor], 'IN_INSTALLMENTS', 100.00, '2026-12-10', 2);

        $this->assertSame([], $this->blockers($group, $creditor));
    }

    public function test_a_creditor_whose_installments_are_all_paid_is_not_blocked(): void
    {
        ['group' => $group, 'users' => [$creditor, $debtor]] = $this->makeGroup(2);
        $expense = $this->makePurchase($group, $creditor, [$creditor, $debtor], 'IN_INSTALLMENTS', 200.00, '2026-10-10', 2);
        $this->markPaid($expense);

        $this->assertSame([], $this->blockers($group, $creditor));
    }

    public function test_an_active_fixed_expense_blocks_a_participant_who_is_not_the_creditor(): void
    {
        ['group' => $group, 'users' => [$creditor, $participant]] = $this->makeGroup(2);
        $expense = $this->makePurchase($group, $creditor, [$creditor, $participant], 'FIXED', 100.00, '2026-10-05');

        $this->assertSame(
            [['expense_id' => $expense->id, 'description' => 'Compra de teste', 'reason' => 'active_fixed']],
            $this->blockers($group, $participant)
        );
    }

    public function test_an_active_fixed_expense_blocks_its_creditor_once_even_with_an_unpaid_first_quota(): void
    {
        ['group' => $group, 'users' => [$creditor, $participant]] = $this->makeGroup(2);
        // A quota inicial da fixa nasce sem pagar: cairia também em "credor com parcela aberta".
        $expense = $this->makePurchase($group, $creditor, [$creditor, $participant], 'FIXED', 100.00, '2026-10-05');

        $this->assertSame(
            [['expense_id' => $expense->id, 'description' => 'Compra de teste', 'reason' => 'active_fixed']],
            $this->blockers($group, $creditor)
        );
    }

    public function test_a_fixed_expense_creditor_with_no_other_participant_is_not_blocked(): void
    {
        ['group' => $group, 'users' => [$creditor]] = $this->makeGroup(1);
        $this->makePurchase($group, $creditor, [$creditor], 'FIXED', 100.00, '2026-10-05');

        $this->assertSame([], $this->blockers($group, $creditor));
    }

    public function test_a_fixed_expense_whose_recurrence_ends_this_month_does_not_block(): void
    {
        ['group' => $group, 'users' => [$creditor, $participant]] = $this->makeGroup(2);
        $expense = $this->makePurchase($group, $creditor, [$creditor, $participant], 'FIXED', 100.00, '2026-10-05');
        $this->markPaid($expense);
        // `stopRecurrence` grava o dia 1 do mês de corte: a fixa já não entra no ciclo de outubro.
        $expense->update(['fixed_recurrence_ends_at' => '2026-10-01']);

        $this->assertSame([], $this->blockers($group, $participant));
        $this->assertSame([], $this->blockers($group, $creditor));
    }

    public function test_a_fixed_expense_whose_recurrence_ends_next_month_still_blocks(): void
    {
        ['group' => $group, 'users' => [$creditor, $participant]] = $this->makeGroup(2);
        $expense = $this->makePurchase($group, $creditor, [$creditor, $participant], 'FIXED', 100.00, '2026-10-05');
        $this->markPaid($expense);
        // Corte em novembro: a ocorrência de outubro ainda existe e ninguém a confirmaria.
        $expense->update(['fixed_recurrence_ends_at' => '2026-11-01']);

        $this->assertSame(
            [['expense_id' => $expense->id, 'description' => 'Compra de teste', 'reason' => 'active_fixed']],
            $this->blockers($group, $participant)
        );
    }

    public function test_a_stopped_fixed_expense_with_an_unpaid_quota_still_blocks_its_creditor(): void
    {
        ['group' => $group, 'users' => [$creditor, $participant]] = $this->makeGroup(2);
        $expense = $this->makePurchase($group, $creditor, [$creditor, $participant], 'FIXED', 100.00, '2026-10-05');
        // Recorrência encerrada, mas a quota inicial segue sem confirmar.
        $expense->update(['fixed_recurrence_ends_at' => '2026-10-01']);

        $this->assertSame(
            [['expense_id' => $expense->id, 'description' => 'Compra de teste', 'reason' => 'creditor_open_quotas']],
            $this->blockers($group, $creditor)
        );
    }

    public function test_deleted_expenses_and_expenses_of_other_groups_are_ignored(): void
    {
        ['group' => $group, 'users' => [$creditor, $debtor]] = $this->makeGroup(2);
        ['group' => $otherGroup, 'users' => [$otherDebtor]] = $this->makeGroup(1);

        $deleted = $this->makePurchase($group, $creditor, [$creditor, $debtor], 'IN_INSTALLMENTS', 200.00, '2026-12-10', 2);
        $deleted->update(['deleted' => true]);
        $this->makePurchase($otherGroup, $creditor, [$creditor, $otherDebtor], 'IN_INSTALLMENTS', 200.00, '2026-12-10', 2);
        $this->makePurchase($otherGroup, $creditor, [$creditor, $otherDebtor], 'FIXED', 100.00, '2026-10-05');

        $this->assertSame([], $this->blockers($group, $creditor));
    }

    public function test_the_list_stops_at_ten_items(): void
    {
        ['group' => $group, 'users' => [$creditor, $debtor]] = $this->makeGroup(2);

        for ($i = 0; $i < 12; $i++) {
            $this->makePurchase($group, $creditor, [$creditor, $debtor], 'IN_INSTALLMENTS', 200.00, '2026-12-10', 2);
        }

        $this->assertCount(10, $this->blockers($group, $creditor));
    }
}
