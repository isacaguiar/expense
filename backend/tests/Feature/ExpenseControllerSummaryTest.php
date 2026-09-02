<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseControllerSummaryTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    private function createExpense(Group $group, User $payer, array $overrides = []): Expense
    {
        return Expense::create(array_merge([
            'create_date' => now(),
            'date_payment' => '2026-08-01',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $payer->id,
            'user_payer_id' => $payer->id,
            'deleted' => false,
        ], $overrides));
    }

    public function test_default_cycle_is_the_current_calendar_month_and_is_open(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $participant->id]);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-05']);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-05', 'number' => 1, 'paid' => true, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200)
            ->assertJsonPath('cycle.start', '2026-08-01')
            ->assertJsonPath('cycle.end', '2026-08-31')
            ->assertJsonPath('cycle.status', 'open')
            ->assertJsonPath('totals.total', 100)
            ->assertJsonPath('totals.paid', 100)
            ->assertJsonPath('totals.pending', 0);
    }

    public function test_expense_entries_include_creditor_and_creator_ids(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $creator->id]);

        $expense = $this->createExpense($group, $payer, [
            'date_payment' => '2026-08-05',
            'user_creator_id' => $creator->id,
            'user_payer_id' => $payer->id,
        ]);
        $expense->payers()->sync([$payer->id, $creator->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-05', 'number' => 1, 'paid' => false, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200)->assertJsonFragment([
            'id' => $expense->id,
            'userPayerId' => $payer->id,
            'userCreatorId' => $creator->id,
        ]);
    }

    public function test_expense_entries_include_value_per_person_and_null_payment_proof_url(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participantA = User::factory()->create();
        $participantB = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $participantA->id, $participantB->id]);

        // 3 participantes dividindo 300 -> 100 por pessoa.
        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-05', 'total_value' => 300]);
        $expense->payers()->sync([$payer->id, $participantA->id, $participantB->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-05', 'number' => 1, 'paid' => false, 'value_quota' => 300]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200)->assertJsonFragment([
            'id' => $expense->id,
            'value' => 300,
            'valuePerPerson' => 100,
            'paymentProofUrl' => null,
        ]);
    }

    public function test_paid_expense_entry_exposes_payment_proof_url_when_present(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-05', 'total_value' => 100]);
        $expense->payers()->sync([$payer->id]);
        $quota = $expense->quotas()->create([
            'date_expected' => '2026-08-05', 'number' => 1, 'paid' => true,
            'value_quota' => 100, 'payment_proof_path' => 'comprovantes/exemplo.jpg',
        ]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200);
        $entry = collect($response->json('expenses'))->firstWhere('id', $expense->id);
        $this->assertNotNull($entry['paymentProofUrl']);
        $this->assertStringContainsString(
            "/groups/{$group->id}/proofs/quota/{$quota->id}",
            $entry['paymentProofUrl']
        );
        $this->assertStringContainsString('signature=', $entry['paymentProofUrl']);
    }

    public function test_future_cycle_without_expenses_returns_zero_totals(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=-1");

        $response->assertStatus(200)
            ->assertJsonPath('cycle.start', '2026-09-01')
            ->assertJsonPath('cycle.end', '2026-09-30')
            ->assertJsonPath('cycle.status', 'future')
            ->assertJsonPath('totals.total', 0)
            ->assertJsonPath('expenses', [])
            ->assertJsonPath('balances.0.balance', 0);
    }

    public function test_installment_with_date_expected_in_a_later_cycle_still_appears(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $participant->id]);

        $expense = $this->createExpense($group, $payer, [
            'date_payment' => '2026-07-01',
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 3,
            'total_value' => 300,
        ]);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-07-01', 'number' => 1, 'paid' => true, 'value_quota' => 100]);
        $expense->quotas()->create(['date_expected' => '2026-08-01', 'number' => 2, 'paid' => false, 'value_quota' => 100]);
        $expense->quotas()->create(['date_expected' => '2026-09-01', 'number' => 3, 'paid' => false, 'value_quota' => 100]);

        // Ciclo futuro (setembro): a despesa foi criada (date_payment) em julho,
        // mas a 3ª parcela vence em setembro — precisa aparecer mesmo assim.
        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=-1");

        $response->assertStatus(200)->assertJsonFragment([
            'id' => $expense->id,
            'date' => '2026-09-01',
            'value' => 100,
            'paid' => false,
        ]);
    }

    public function test_installment_row_shows_quota_value_and_paid_status_not_total(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'closing_day' => 15]);
        $group->members()->attach([$payer->id, $participant->id]);

        $expense = $this->createExpense($group, $payer, [
            'date_payment' => '2026-07-20',
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 3,
            'total_value' => 900,
        ]);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-07-20', 'number' => 1, 'paid' => false, 'value_quota' => 300]);
        $expense->quotas()->create(['date_expected' => '2026-08-20', 'number' => 2, 'paid' => false, 'value_quota' => 300]);
        $expense->quotas()->create(['date_expected' => '2026-09-20', 'number' => 3, 'paid' => false, 'value_quota' => 300]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200)->assertJsonFragment([
            'id' => $expense->id,
            'value' => 300,
            'paid' => false,
        ]);
    }

    public function test_fixed_expense_projected_without_own_quota_defaults_to_pending(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'closing_day' => 15]);
        $group->members()->attach([$payer->id, $participant->id]);

        $expense = $this->createExpense($group, $payer, [
            'date_payment' => '2026-06-05',
            'expense_type' => 'FIXED',
            'total_value' => 500,
        ]);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-06-05', 'number' => 1, 'paid' => false, 'value_quota' => 500]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        // Ciclo corrente (aberto): 16/ago-15/set; a ocorrência projetada cai em 05/set, sem Quota própria.
        $response->assertStatus(200)->assertJsonFragment([
            'id' => $expense->id,
            'date' => '2026-09-05',
            'value' => 500,
            'paid' => false,
            'isFixed' => true,
        ]);
    }

    public function test_fixed_expense_uses_materialized_quota_value_when_present(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'closing_day' => 15]);
        $group->members()->attach([$payer->id, $participant->id]);

        $expense = $this->createExpense($group, $payer, [
            'date_payment' => '2026-06-05',
            'expense_type' => 'FIXED',
            'total_value' => 500,
        ]);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-06-05', 'number' => 1, 'paid' => false, 'value_quota' => 500]);

        // Ocorrência do ciclo corrente (16/ago-15/set cai em 05/set) já foi congelada
        // com um valor diferente do total_value atual — o resumo deve refletir isso,
        // não o total_value ao vivo (500).
        $expense->quotas()->create(['date_expected' => '2026-09-05', 'number' => 1, 'paid' => true, 'value_quota' => 350]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200)->assertJsonFragment([
            'id' => $expense->id,
            'date' => '2026-09-05',
            'value' => 350,
            'paid' => true,
            'isFixed' => true,
        ]);
    }

    public function test_balances_include_every_member_and_sum_to_zero(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $uninvolved = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $participant->id, $uninvolved->id]);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-10', 'total_value' => 200]);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => true, 'value_quota' => 200]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200)
            ->assertJsonFragment(['user_id' => $payer->id, 'balance' => 100])
            ->assertJsonFragment(['user_id' => $participant->id, 'balance' => -100])
            ->assertJsonFragment(['user_id' => $uninvolved->id, 'balance' => 0]);
    }

    public function test_balances_are_unchanged_after_the_fixed_occurrence_quota_is_materialized(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $participant->id]);

        $expense = $this->createExpense($group, $payer, [
            'date_payment' => '2026-06-05',
            'expense_type' => 'FIXED',
            'total_value' => 200,
        ]);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-06-05', 'number' => 1, 'paid' => false, 'value_quota' => 200]);

        $before = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $before->assertStatus(200);
        $balancesBefore = $before->json('balances');

        // Materializa a ocorrência do mês corrente (agosto) exatamente como
        // materializeFixedOccurrenceQuota faria — a origem do dado (Quota
        // real em vez de projeção ao vivo) muda, mas o valor é o mesmo.
        $expense->quotas()->create(['date_expected' => '2026-08-05', 'number' => 1, 'paid' => false, 'value_quota' => 200]);

        $after = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $after->assertStatus(200);
        $balancesAfter = $after->json('balances');

        $this->assertSame($balancesBefore, $balancesAfter);
        $after->assertJsonFragment(['user_id' => $payer->id, 'balance' => 100])
            ->assertJsonFragment(['user_id' => $participant->id, 'balance' => -100]);
    }

    public function test_cycles_ago_navigates_to_previous_closed_cycle(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($payer->id);

        $juneExpense = $this->createExpense($group, $payer, ['date_payment' => '2026-06-10', 'total_value' => 50]);
        $juneExpense->payers()->sync([$payer->id]);
        $juneExpense->quotas()->create(['date_expected' => '2026-06-10', 'number' => 1, 'paid' => true, 'value_quota' => 50]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=2");

        $response->assertStatus(200)
            ->assertJsonPath('cycle.start', '2026-06-01')
            ->assertJsonPath('cycle.end', '2026-06-30')
            ->assertJsonPath('cycle.status', 'closed')
            ->assertJsonPath('totals.total', 50);
    }

    public function test_non_member_cannot_view_group_summary(): void
    {
        $outsider = User::factory()->create();
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($outsider))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(404);
    }

    public function test_settlements_has_no_self_owed_entry_and_reconciles_with_balances(): void
    {
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $participant->id]);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-08-10', 'total_value' => 200]);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => true, 'value_quota' => 200]);

        $response = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200)->assertJsonFragment([
            'from_user_id' => $participant->id,
            'to_user_id' => $payer->id,
            'amount' => 100,
        ]);

        $settlements = $response->json('settlements');
        $balances = $response->json('balances');

        foreach ($settlements as $settlement) {
            $this->assertNotSame($settlement['from_user_id'], $settlement['to_user_id']);
        }

        $this->assertNetBalancesMatchSettlements($balances, $settlements);
    }

    public function test_settlements_nets_mutual_debt_between_the_same_pair_into_one_entry(): void
    {
        Carbon::setTestNow('2026-08-19');

        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$alice->id, $bob->id]);

        // Alice paga 300 divididos com Bob (Bob deve 150 a Alice).
        $expenseA = $this->createExpense($group, $alice, ['date_payment' => '2026-08-05', 'total_value' => 300]);
        $expenseA->payers()->sync([$alice->id, $bob->id]);
        $expenseA->quotas()->create(['date_expected' => '2026-08-05', 'number' => 1, 'paid' => true, 'value_quota' => 300]);

        // Bob paga 100 divididos com Alice (Alice deve 50 a Bob).
        $expenseB = $this->createExpense($group, $bob, ['date_payment' => '2026-08-12', 'total_value' => 100]);
        $expenseB->payers()->sync([$alice->id, $bob->id]);
        $expenseB->quotas()->create(['date_expected' => '2026-08-12', 'number' => 1, 'paid' => true, 'value_quota' => 100]);

        $response = $this->withToken($this->tokenFor($alice))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200);
        $settlements = $response->json('settlements');

        // Só a diferença líquida (150 - 50 = 100), numa única entrada — nunca
        // as duas dívidas brutas separadas.
        $this->assertCount(1, $settlements);
        $this->assertSame($bob->id, $settlements[0]['from_user_id']);
        $this->assertSame($alice->id, $settlements[0]['to_user_id']);
        $this->assertEquals(100, $settlements[0]['amount']);
    }

    public function test_settlements_regression_scenario_from_the_summary_discrepancy_conversation(): void
    {
        Carbon::setTestNow('2026-08-22');

        $isac = User::factory()->create(['name' => 'Isac']);
        $joao = User::factory()->create(['name' => 'João']);
        $maria = User::factory()->create(['name' => 'Maria']);
        $novemax = User::factory()->create(['name' => 'novemaxdev']);
        $group = Group::create(['name' => 'Familiar']);
        $group->members()->attach([$isac->id, $joao->id, $maria->id, $novemax->id]);

        // Água #1: 150, só Isac (não gera dívida).
        $agua1 = $this->createExpense($group, $isac, ['date_payment' => '2026-08-19', 'description' => 'Água #1', 'total_value' => 150]);
        $agua1->payers()->sync([$isac->id]);
        $agua1->quotas()->create(['date_expected' => '2026-08-19', 'number' => 1, 'paid' => true, 'value_quota' => 150]);

        // Água #2: 150, Isac/João/Maria (50 cada).
        $agua2 = $this->createExpense($group, $isac, ['date_payment' => '2026-08-19', 'description' => 'Água #2', 'total_value' => 150]);
        $agua2->payers()->sync([$isac->id, $joao->id, $maria->id]);
        $agua2->quotas()->create(['date_expected' => '2026-08-19', 'number' => 1, 'paid' => true, 'value_quota' => 150]);

        // Luz: 200, Isac/Maria (100 cada).
        $luz = $this->createExpense($group, $isac, ['date_payment' => '2026-08-19', 'description' => 'Luz', 'total_value' => 200]);
        $luz->payers()->sync([$isac->id, $maria->id]);
        $luz->quotas()->create(['date_expected' => '2026-08-19', 'number' => 1, 'paid' => false, 'value_quota' => 200]);

        // Placa Solar: 600, Isac/João/Maria (200 cada).
        $placaSolar = $this->createExpense($group, $isac, ['date_payment' => '2026-08-22', 'description' => 'Placa Solar', 'total_value' => 600]);
        $placaSolar->payers()->sync([$isac->id, $joao->id, $maria->id]);
        $placaSolar->quotas()->create(['date_expected' => '2026-08-22', 'number' => 1, 'paid' => false, 'value_quota' => 600]);

        // Mercado: 450, os 4 (112,50 cada).
        $mercado = $this->createExpense($group, $joao, ['date_payment' => '2026-08-22', 'description' => 'Mercado', 'total_value' => 450]);
        $mercado->payers()->sync([$isac->id, $joao->id, $maria->id, $novemax->id]);
        $mercado->quotas()->create(['date_expected' => '2026-08-22', 'number' => 1, 'paid' => false, 'value_quota' => 450]);

        $response = $this->withToken($this->tokenFor($isac))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200)
            ->assertJsonFragment(['user_id' => $isac->id, 'balance' => 487.50])
            ->assertJsonFragment(['user_id' => $joao->id, 'balance' => 87.50])
            ->assertJsonFragment(['user_id' => $maria->id, 'balance' => -462.50])
            ->assertJsonFragment(['user_id' => $novemax->id, 'balance' => -112.50]);

        $response
            ->assertJsonFragment(['from_user_id' => $maria->id, 'to_user_id' => $isac->id, 'amount' => 350.0])
            ->assertJsonFragment(['from_user_id' => $joao->id, 'to_user_id' => $isac->id, 'amount' => 137.50])
            ->assertJsonFragment(['from_user_id' => $maria->id, 'to_user_id' => $joao->id, 'amount' => 112.50])
            ->assertJsonFragment(['from_user_id' => $novemax->id, 'to_user_id' => $joao->id, 'amount' => 112.50]);

        $settlements = $response->json('settlements');
        $this->assertCount(4, $settlements);

        $this->assertNetBalancesMatchSettlements($response->json('balances'), $settlements);
    }

    public function test_sealed_cycle_settlements_are_immutable_after_a_new_expense_is_created(): void
    {
        // TASK-247: um ciclo fechado só congela quando SELADO (tudo pago e todo
        // acerto confirmado). Aqui junho fecha por data, é pago e tem o acerto
        // confirmado → sela na 1ª leitura → despesa nova depois disso não muda
        // mais o `settlements`.
        Carbon::setTestNow('2026-08-19');

        $payer = User::factory()->create();
        $participant = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$payer->id, $participant->id]);

        $expense = $this->createExpense($group, $payer, ['date_payment' => '2026-06-10', 'total_value' => 200]);
        $expense->payers()->sync([$payer->id, $participant->id]);
        $expense->quotas()->create(['date_expected' => '2026-06-10', 'number' => 1, 'paid' => true, 'value_quota' => 200]);

        // Acerto participant → payer (200/2 = 100) confirmado → junho fica quitado.
        SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-06-01',
            'cycle_end' => '2026-06-30',
            'from_user_id' => $participant->id,
            'to_user_id' => $payer->id,
            'amount' => 100,
            'proof_path' => 'comprovantes/'.$group->id.'/x.jpg',
            'confirmed_at' => Carbon::now(),
        ]);

        $before = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=2");
        $before->assertStatus(200)->assertJsonPath('cycle.settled', true);
        $settlementsBefore = $before->json('settlements');

        // Despesa nova criada depois do ciclo já selado — não deve alterar o
        // `settlements` já persistido no snapshot.
        $newExpense = $this->createExpense($group, $participant, ['date_payment' => '2026-06-15', 'total_value' => 1000]);
        $newExpense->payers()->sync([$payer->id, $participant->id]);
        $newExpense->quotas()->create(['date_expected' => '2026-06-15', 'number' => 1, 'paid' => true, 'value_quota' => 1000]);

        $after = $this->withToken($this->tokenFor($payer))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=2");
        $after->assertStatus(200);

        // assertEquals (não assertSame): a ordem das chaves de cada item pode
        // mudar depois de ida e volta pelo cast `json` do MySQL (que
        // canonicaliza a ordem das chaves do objeto) — o conteúdo é que
        // precisa ser idêntico, não a ordem de serialização.
        $this->assertEquals($settlementsBefore, $after->json('settlements'));
    }

    /**
     * Para todo user_id presente em $balances, a soma de `amount` recebido
     * (settlement.to_user_id) menos o pago (settlement.from_user_id) deve
     * bater com o `balance` líquido dessa pessoa.
     */
    private function assertNetBalancesMatchSettlements(array $balances, array $settlements): void
    {
        $net = [];
        foreach ($balances as $balance) {
            $net[$balance['user_id']] = 0.0;
        }

        foreach ($settlements as $settlement) {
            $net[$settlement['to_user_id']] = ($net[$settlement['to_user_id']] ?? 0) + $settlement['amount'];
            $net[$settlement['from_user_id']] = ($net[$settlement['from_user_id']] ?? 0) - $settlement['amount'];
        }

        foreach ($balances as $balance) {
            $this->assertEqualsWithDelta($balance['balance'], round($net[$balance['user_id']], 2), 0.01, "user_id {$balance['user_id']}");
        }
    }
}
