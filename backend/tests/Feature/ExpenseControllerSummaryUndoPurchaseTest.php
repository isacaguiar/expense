<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `canUndoPurchase` no `GET .../expenses/summary` (feature
 * 20261027-pagamento-no-cadastro-despesa, plan.md §5, P5): avisa a tela quando o credor ainda
 * pode usar "Desfazer pagamento da compra". Só aparece quando é verdadeiro (ausente = falso), só
 * na resposta (nunca em `compute()` nem no snapshot) e só para o credor autenticado.
 */
class ExpenseControllerSummaryUndoPurchaseTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array{group: Group, creditor: User, debtor: User}
     */
    private function scenario(string $now = '2026-08-19 12:00:00'): array
    {
        Carbon::setTestNow($now);
        Storage::fake('local');

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'República']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        return ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor];
    }

    private function token(User $user): string
    {
        return auth('api')->login($user);
    }

    /** Cadastra uma compra pelo `POST /api/expenses`. Sem `$paid`, nasce pendente. */
    private function register(Group $group, User $creditor, User $debtor, array $overrides = [], bool $paid = true): int
    {
        $body = array_merge([
            'date_payment' => '2026-08-10',
            'description' => 'Geladeira',
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 3,
            'total_value' => 300,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'payers' => [$creditor->id, $debtor->id],
        ], $overrides);

        if ($paid) {
            $body['descricao_pagamento'] = 'Cartão';
        }

        return $this->withToken($this->token($creditor))->postJson('/api/expenses', $body)->assertStatus(201)->json('expense_id');
    }

    /** @return array<string, mixed> */
    private function summary(User $user, Group $group, int $cyclesAgo = 0): array
    {
        return $this->withToken($this->token($user))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago={$cyclesAgo}")
            ->assertOk()->json();
    }

    public function test_summary_flags_can_undo_purchase_only_for_the_creditor_inside_the_window(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $id = $this->register($group, $creditor, $debtor);

        $forCreditor = collect($this->summary($creditor, $group)['expenses'])->firstWhere('id', $id);
        $forDebtor = collect($this->summary($debtor, $group)['expenses'])->firstWhere('id', $id);

        $this->assertTrue($forCreditor['canUndoPurchase']);
        $this->assertArrayNotHasKey('canUndoPurchase', $forDebtor, 'quem não é o credor não vê a ação');
    }

    public function test_the_flag_is_also_set_on_a_future_installment_of_the_same_purchase(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $id = $this->register($group, $creditor, $debtor);

        // Setembro ainda não começou: o `unpay` por parcela não o alcança, mas o desfazer da compra sim.
        $september = $this->summary($creditor, $group, -1);

        $this->assertSame('future', $september['cycle']['status']);
        $this->assertTrue(collect($september['expenses'])->firstWhere('id', $id)['canUndoPurchase']);
    }

    public function test_the_flag_is_absent_once_a_paid_quota_is_in_a_closed_cycle(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $id = $this->register($group, $creditor, $debtor);

        Carbon::setTestNow('2026-09-10 12:00:00'); // agosto fechou em 05/09

        $this->assertArrayNotHasKey('canUndoPurchase', collect($this->summary($creditor, $group, 0)['expenses'])->firstWhere('id', $id) ?? []);
        $this->assertArrayNotHasKey('canUndoPurchase', collect($this->summary($creditor, $group, 1)['expenses'])->firstWhere('id', $id));
    }

    public function test_the_flag_is_absent_for_other_types_and_for_pending_installments(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $inCash = $this->register($group, $creditor, $debtor, ['expense_type' => 'IN_CASH', 'installments' => 1, 'total_value' => 100]);
        $pending = $this->register($group, $creditor, $debtor, ['description' => 'Pendente'], paid: false);

        $expenses = collect($this->summary($creditor, $group)['expenses']);

        $this->assertArrayNotHasKey('canUndoPurchase', $expenses->firstWhere('id', $inCash));
        $this->assertArrayNotHasKey('canUndoPurchase', $expenses->firstWhere('id', $pending));
    }

    public function test_a_sealed_cycle_serves_its_snapshot_without_the_flag(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $id = $this->register($group, $creditor, $debtor);

        SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'amount' => 50,
            'proof_path' => "comprovantes/{$group->id}/acerto.jpg",
            'confirmed_at' => now(),
        ]);
        $this->withToken($this->token($creditor))->postJson("/api/groups/{$group->id}/expenses/close")->assertOk();

        $summary = $this->summary($creditor, $group);

        $this->assertTrue($summary['cycle']['settled']);
        $this->assertArrayNotHasKey('canUndoPurchase', collect($summary['expenses'])->firstWhere('id', $id));
    }

    public function test_the_flag_never_enters_the_snapshot_or_the_other_endpoints(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $id = $this->register($group, $creditor, $debtor);

        SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'amount' => 50,
            'proof_path' => "comprovantes/{$group->id}/acerto.jpg",
            'confirmed_at' => now(),
        ]);

        // O flag aparece na resposta do resumo vigente...
        $this->assertTrue(collect($this->summary($creditor, $group)['expenses'])->firstWhere('id', $id)['canUndoPurchase']);

        // ...mas o snapshot gravado ao selar não o carrega, nem o relatório de fechamento.
        $this->withToken($this->token($creditor))->postJson("/api/groups/{$group->id}/expenses/close")->assertOk();
        $snapshot = GroupCycleSnapshot::where('group_id', $group->id)->firstOrFail();

        $this->assertArrayNotHasKey('canUndoPurchase', $snapshot->expenses[0]);

        $report = $this->withToken($this->token($creditor))
            ->getJson("/api/groups/{$group->id}/expenses/closing-report?cycle_start=2026-08-01")->assertOk()->json();
        $this->assertArrayNotHasKey('canUndoPurchase', $report['expenses'][0]);
    }

    public function test_the_flag_costs_the_same_number_of_queries_for_one_or_many_purchases(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $this->register($group, $creditor, $debtor, ['description' => 'Compra 1']);
        $token = $this->token($creditor);

        $count = function () use ($group, $token): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->withToken($token)->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=0")->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $withOne = $count();

        foreach (['Compra 2', 'Compra 3', 'Compra 4'] as $description) {
            $this->register($group, $creditor, $debtor, ['description' => $description]);
        }

        $this->assertSame($withOne, $count(), 'o número de consultas não cresce com o número de compras');
    }

    public function test_summary_without_any_purchase_to_flag_is_unchanged(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $id = $this->register($group, $creditor, $debtor, ['expense_type' => 'IN_CASH', 'installments' => 1, 'total_value' => 100], paid: false);

        $expense = collect($this->summary($creditor, $group)['expenses'])->firstWhere('id', $id);

        $this->assertArrayNotHasKey('canUndoPurchase', $expense);
        $this->assertFalse($expense['paid']);
    }
}
