<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Quota;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `POST /api/expenses/{id}/undo-purchase` (feature 20261027-pagamento-no-cadastro-despesa,
 * specify.md R19, D11, F11 e CA-29/CA-30): o credor desfaz de uma vez o pagamento de uma
 * compra parcelada paga no cadastro, enquanto nenhuma parcela paga estiver em ciclo
 * encerrado (fechado por data, fechamento manual ativo ou selado). Depois disso, nada.
 */
class ExpenseControllerUndoPurchaseTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        Event::forget('eloquent.updating: '.Quota::class);

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

    /**
     * Cadastra pelo `POST /api/expenses` uma compra parcelada paga (3x de 100,00, a partir de
     * 2026-08-10) com foto e descrição, e devolve o id.
     */
    private function registerPaidPurchase(Group $group, User $creditor, User $debtor, array $overrides = []): int
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
            'descricao_pagamento' => 'Cartão final 1234',
        ], $overrides);

        return $this->withToken($this->token($creditor))
            ->post('/api/expenses', $body + ['comprovante' => UploadedFile::fake()->image('cartao.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->json('expense_id');
    }

    private function undo(User $user, int $expenseId)
    {
        return $this->withToken($this->token($user))->postJson("/api/expenses/{$expenseId}/undo-purchase");
    }

    private function files(): int
    {
        return count(Storage::disk('local')->allFiles());
    }

    // --- dentro da janela ----------------------------------------------------------------------

    public function test_the_creditor_undoes_every_paid_installment_inside_the_window(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $id = $this->registerPaidPurchase($group, $creditor, $debtor);

        $this->undo($creditor, $id)
            ->assertOk()
            ->assertJsonPath('quotas_cleared', 3);

        $quotas = Expense::findOrFail($id)->quotas;

        $this->assertSame(0, $quotas->where('paid', true)->count());
    }

    public function test_it_clears_proof_note_paid_at_and_paid_by_and_unlocks_update_and_destroy(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $id = $this->registerPaidPurchase($group, $creditor, $debtor);
        $token = $this->token($creditor);

        // Paga, a despesa está travada pelas regras de hoje.
        $this->withToken($token)->putJson("/api/expenses/{$id}", ['description' => 'Outro nome'])->assertStatus(422);
        $this->assertSame(1, $this->files());

        $this->undo($creditor, $id)->assertOk();

        foreach (Expense::findOrFail($id)->quotas as $quota) {
            $this->assertFalse($quota->paid);
            $this->assertNull($quota->paid_at);
            $this->assertNull($quota->paid_by);
            $this->assertNull($quota->payment_proof_path);
            $this->assertNull($quota->payment_note);
        }

        $this->assertSame(0, $this->files(), 'o arquivo sai do disco quando ninguém mais o usa');

        // Sem parcela paga, a edição e a exclusão voltam a valer pelas regras existentes.
        $this->withToken($this->token($creditor))->putJson("/api/expenses/{$id}", ['description' => 'Outro nome'])->assertOk();
        $this->withToken($this->token($creditor))->deleteJson("/api/expenses/{$id}")->assertOk();
    }

    public function test_born_paid_quotas_do_not_count_for_the_window_and_are_left_alone(): void
    {
        // 2026-09-20: junho, julho e agosto já fechados. As 3 primeiras nascem `born_paid`.
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario('2026-09-20 12:00:00');

        $quotas = [];
        foreach ([1 => '2026-06-05', 2 => '2026-07-05', 3 => '2026-08-05', 4 => '2026-09-05', 5 => '2026-10-05', 6 => '2026-11-05'] as $number => $date) {
            $quotas[] = ['number' => $number, 'date_expected' => $date, 'value_quota' => 100];
        }

        $id = $this->registerPaidPurchase($group, $creditor, $debtor, [
            'date_payment' => '2026-06-05',
            'installments' => 6,
            'total_value' => 600,
            'quotas' => $quotas,
        ]);

        $this->undo($creditor, $id)->assertOk()->assertJsonPath('quotas_cleared', 3);

        $stored = Expense::findOrFail($id)->quotas()->orderBy('number')->get();

        foreach ($stored->take(3) as $quota) {
            $this->assertTrue($quota->born_paid);
            $this->assertTrue($quota->paid, "parcela {$quota->number} nasceu quitada e continua");
        }

        foreach ($stored->slice(3) as $quota) {
            $this->assertFalse($quota->paid, "parcela {$quota->number}");
        }
    }

    // --- fora da janela ------------------------------------------------------------------------

    public function test_it_is_refused_when_a_paid_quota_is_in_a_cycle_closed_by_date(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $id = $this->registerPaidPurchase($group, $creditor, $debtor);

        // Agosto termina em 31/08 e fecha em 05/09.
        Carbon::setTestNow('2026-09-10 12:00:00');

        $this->undo($creditor, $id)
            ->assertStatus(422)
            ->assertJsonPath('error', 'O ciclo desta compra já foi encerrado; o pagamento não pode mais ser desfeito.');

        $this->assertSame(3, Expense::findOrFail($id)->quotas()->where('paid', true)->count());
        $this->assertSame(1, $this->files());
    }

    public function test_it_is_refused_in_a_manually_closed_cycle(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $id = $this->registerPaidPurchase($group, $creditor, $debtor);

        $this->withToken($this->token($creditor))->postJson("/api/groups/{$group->id}/expenses/close")->assertOk();
        $this->assertFalse(GroupCycleSnapshot::where('group_id', $group->id)->whereNotNull('settled_at')->exists(), 'fechado, não selado');

        $this->undo($creditor, $id)->assertStatus(422);
        $this->assertSame(3, Expense::findOrFail($id)->quotas()->where('paid', true)->count());
    }

    public function test_it_is_refused_in_a_sealed_cycle(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $id = $this->registerPaidPurchase($group, $creditor, $debtor);

        // O devedor confirma o acerto de agosto (50,00); fechar o mês quitado sela o ciclo.
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
        $this->assertTrue(GroupCycleSnapshot::where('group_id', $group->id)->whereNotNull('settled_at')->exists(), 'o ciclo precisa estar selado');

        $this->undo($creditor, $id)->assertStatus(422);
        $this->assertSame(3, Expense::findOrFail($id)->quotas()->where('paid', true)->count());
    }

    // --- quem pode ------------------------------------------------------------------------------

    public function test_a_non_creditor_gets_403_and_a_non_member_404(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $id = $this->registerPaidPurchase($group, $creditor, $debtor);
        $outsider = User::factory()->create();

        $this->undo($debtor, $id)->assertStatus(403);
        $this->undo($outsider, $id)->assertStatus(404);

        $this->assertSame(3, Expense::findOrFail($id)->quotas()->where('paid', true)->count());
    }

    // --- o que não é uma compra parcelada paga --------------------------------------------------

    public function test_other_types_get_422(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        foreach (['IN_CASH', 'FIXED'] as $type) {
            $id = $this->registerPaidPurchase($group, $creditor, $debtor, ['expense_type' => $type, 'installments' => 1, 'total_value' => 100]);

            $this->undo($creditor, $id)
                ->assertStatus(422)
                ->assertJsonPath('error', 'O desfazer da compra vale só para despesa parcelada. Use "Desfazer pagamento" na ocorrência.');
        }
    }

    public function test_it_is_refused_when_there_is_no_payment_to_undo(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $id = $this->registerPaidPurchase($group, $creditor, $debtor);

        $this->undo($creditor, $id)->assertOk();
        $this->undo($creditor, $id)
            ->assertStatus(422)
            ->assertJsonPath('error', 'Esta despesa não tem pagamento a desfazer.');
    }

    // --- atomicidade e arquivo ------------------------------------------------------------------

    public function test_a_failure_in_the_middle_changes_nothing(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $id = $this->registerPaidPurchase($group, $creditor, $debtor);

        $calls = 0;
        Event::listen('eloquent.updating: '.Quota::class, function () use (&$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('falha forçada na segunda parcela');
            }
        });

        $this->undo($creditor, $id)->assertStatus(500);

        $quotas = Expense::findOrFail($id)->quotas;
        $this->assertSame(3, $quotas->where('paid', true)->count(), 'a transação foi desfeita por inteiro');
        $this->assertNotNull($quotas->first()->payment_proof_path);
        $this->assertSame(1, $this->files(), 'o arquivo só sai depois do commit');
    }

    public function test_the_file_is_removed_only_after_commit_and_only_when_unreferenced(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $id = $this->registerPaidPurchase($group, $creditor, $debtor);
        $path = Expense::findOrFail($id)->quotas()->first()->payment_proof_path;

        // Outra despesa, de outro cadastro, aponta para o mesmo arquivo (referência de fora da compra).
        $other = Expense::create([
            'create_date' => now(), 'date_payment' => '2026-08-10', 'description' => 'Outra',
            'expense_type' => 'IN_CASH', 'installments' => 1, 'total_value' => 10,
            'group_id' => $group->id, 'user_creator_id' => $creditor->id, 'user_payer_id' => $creditor->id, 'deleted' => false,
        ]);
        $other->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => true, 'payment_proof_path' => $path, 'value_quota' => 10]);

        $this->undo($creditor, $id)->assertOk();

        Storage::disk('local')->assertExists($path);
    }

    // --- CA-30: os caminhos por parcela seguem funcionando ---------------------------------------

    public function test_unpay_and_payment_proof_still_work_on_a_quota_paid_at_registration(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $id = $this->registerPaidPurchase($group, $creditor, $debtor);
        $token = $this->token($creditor);

        $this->withToken($token)->postJson("/api/expenses/{$id}/payment-proof", ['cycles_ago' => 0, 'descricao' => 'Nota trocada'])->assertOk();
        $this->assertSame('Nota trocada', Expense::findOrFail($id)->quotas()->orderBy('number')->first()->payment_note);

        $this->withToken($token)->postJson("/api/expenses/{$id}/unpay", ['cycles_ago' => 0])->assertOk();

        $quotas = Expense::findOrFail($id)->quotas()->orderBy('number')->get();
        $this->assertFalse($quotas[0]->paid);
        $this->assertTrue($quotas[1]->paid, 'as outras parcelas seguem pagas');
        $this->assertSame(1, $this->files(), 'o arquivo fica enquanto outra parcela o usa');
    }
}
