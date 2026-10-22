<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Quota;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `POST /api/expenses/{id}/payment-proof`: o credor acrescenta, troca ou remove
 * a foto e a descrição de uma despesa JÁ paga, sem desfazer o pagamento
 * (docs/feature/20261022-editar-comprovante-depois-de-paga, plan.md §1).
 */
class ExpenseControllerPaymentProofTest extends TestCase
{
    use DatabaseTransactions;

    private const PAID_AT = '2026-08-12 10:00:00';

    protected function tearDown(): void
    {
        Quota::flushEventListeners();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    /**
     * Grupo, credor e uma despesa à vista de agosto/2026 com a quota JÁ paga
     * (relógio em 2026-08-19, dentro da competência da quota).
     *
     * @param  array<string, mixed>  $quota  sobrescreve campos da quota (payment_note, payment_proof_path, born_paid…)
     * @return array{0: User, 1: Group, 2: Expense, 3: Quota}
     */
    private function paidExpense(array $quota = [], array $expense = []): array
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($creditor->id);

        $expense = Expense::create(array_merge([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ], $expense));
        $expense->payers()->attach($creditor->id);

        $quota = $expense->quotas()->create(array_merge([
            'date_expected' => '2026-08-10',
            'number' => 1,
            'paid' => true,
            'paid_at' => self::PAID_AT,
            'paid_by' => $creditor->id,
            'value_quota' => 100,
        ], $quota));

        return [$creditor, $group, $expense, $quota];
    }

    /** Grava um comprovante "antigo" no disco privado e devolve o path. */
    private function putProof(Group $group, string $name = 'antiga.jpg'): string
    {
        $path = "comprovantes/{$group->id}/{$name}";
        Storage::disk('local')->put($path, 'img');

        return $path;
    }

    private function edit(User $user, Expense $expense, array $body = [])
    {
        return $this->withToken($this->tokenFor($user))
            ->postJson("/api/expenses/{$expense->id}/payment-proof", $body);
    }

    private function editMultipart(User $user, Expense $expense, array $body = [])
    {
        return $this->withToken($this->tokenFor($user))
            ->post("/api/expenses/{$expense->id}/payment-proof", $body, ['Accept' => 'application/json']);
    }

    /** Nada mudou: nem a quota nem o disco. */
    private function assertNothingChanged(Quota $before, array $filesBefore): void
    {
        $after = $before->fresh();

        $this->assertSame($before->payment_proof_path, $after->payment_proof_path);
        $this->assertSame($before->payment_note, $after->payment_note);
        $this->assertTrue((bool) $after->paid);
        $this->assertEqualsCanonicalizing($filesBefore, Storage::disk('local')->allFiles());
    }

    // --- Casos de sucesso -------------------------------------------------------

    public function test_adds_a_photo_to_a_note_only_payment_keeping_the_note_and_the_payment_data(): void
    {
        Storage::fake('local');
        [$creditor, $group, $expense, $quota] = $this->paidExpense(['payment_note' => 'Pago em dinheiro']);

        $response = $this->editMultipart($creditor, $expense, [
            'comprovante' => UploadedFile::fake()->image('recibo.jpg'),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('paid', true)
            ->assertJsonPath('payment_note', 'Pago em dinheiro');
        $this->assertNotNull($response->json('payment_proof_url'));

        $fresh = $quota->fresh();
        $this->assertStringStartsWith("comprovantes/{$group->id}/", $fresh->payment_proof_path);
        Storage::disk('local')->assertExists($fresh->payment_proof_path);
        $this->assertSame('Pago em dinheiro', $fresh->payment_note);
        $this->assertTrue((bool) $fresh->paid);
        $this->assertSame(self::PAID_AT, $fresh->paid_at->format('Y-m-d H:i:s'));
        $this->assertSame($creditor->id, $fresh->paid_by);
    }

    public function test_replaces_the_photo_and_deletes_the_old_file_from_the_disk(): void
    {
        Storage::fake('local');
        [$creditor, $group, $expense, $quota] = $this->paidExpense();
        $old = $this->putProof($group);
        $quota->update(['payment_proof_path' => $old]);

        $this->editMultipart($creditor, $expense, [
            'comprovante' => UploadedFile::fake()->image('nova.jpg'),
        ])->assertStatus(200);

        $new = $quota->fresh()->payment_proof_path;
        $this->assertNotSame($old, $new);
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($new);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_removes_the_photo_keeping_the_note(): void
    {
        Storage::fake('local');
        [$creditor, $group, $expense, $quota] = $this->paidExpense(['payment_note' => 'Pix agendado']);
        $old = $this->putProof($group);
        $quota->update(['payment_proof_path' => $old]);

        $this->edit($creditor, $expense, ['remover_comprovante' => true])
            ->assertStatus(200)
            ->assertJsonPath('payment_proof_url', null)
            ->assertJsonPath('payment_note', 'Pix agendado');

        $fresh = $quota->fresh();
        $this->assertNull($fresh->payment_proof_path);
        $this->assertSame('Pix agendado', $fresh->payment_note);
        Storage::disk('local')->assertMissing($old);
    }

    public function test_removing_the_photo_is_allowed_when_a_description_comes_in_the_same_request(): void
    {
        Storage::fake('local');
        [$creditor, $group, $expense, $quota] = $this->paidExpense();
        $old = $this->putProof($group);
        $quota->update(['payment_proof_path' => $old]);

        $this->edit($creditor, $expense, ['remover_comprovante' => true, 'descricao' => 'Agora só texto'])
            ->assertStatus(200);

        $fresh = $quota->fresh();
        $this->assertNull($fresh->payment_proof_path);
        $this->assertSame('Agora só texto', $fresh->payment_note);
        Storage::disk('local')->assertMissing($old);
    }

    public function test_edits_only_the_note_leaving_the_file_untouched(): void
    {
        Storage::fake('local');
        [$creditor, $group, $expense, $quota] = $this->paidExpense(['payment_note' => 'Antiga']);
        $old = $this->putProof($group);
        $quota->update(['payment_proof_path' => $old]);

        $this->edit($creditor, $expense, ['descricao' => '  Nova descrição  '])
            ->assertStatus(200)
            ->assertJsonPath('payment_note', 'Nova descrição');

        $fresh = $quota->fresh();
        $this->assertSame('Nova descrição', $fresh->payment_note);
        $this->assertSame($old, $fresh->payment_proof_path);
        Storage::disk('local')->assertExists($old);
    }

    public function test_clears_the_note_keeping_the_photo_with_null_or_an_empty_string(): void
    {
        Storage::fake('local');
        [$creditor, $group, $expense, $quota] = $this->paidExpense(['payment_note' => 'Some']);
        $old = $this->putProof($group);
        $quota->update(['payment_proof_path' => $old]);

        $this->edit($creditor, $expense, ['descricao' => null])->assertStatus(200);
        $this->assertNull($quota->fresh()->payment_note);
        $this->assertSame($old, $quota->fresh()->payment_proof_path);

        $quota->update(['payment_note' => 'Voltou']);

        $this->edit($creditor, $expense, ['descricao' => ''])->assertStatus(200);
        $this->assertNull($quota->fresh()->payment_note);
        $this->assertSame($old, $quota->fresh()->payment_proof_path);
        Storage::disk('local')->assertExists($old);
    }

    public function test_stores_a_descricao_that_is_the_string_zero(): void
    {
        Storage::fake('local');
        [$creditor, , $expense, $quota] = $this->paidExpense(['payment_note' => 'Antiga']);

        $this->edit($creditor, $expense, ['descricao' => '0'])
            ->assertStatus(200)
            ->assertJsonPath('payment_note', '0');

        $this->assertSame('0', $quota->fresh()->payment_note);
    }

    public function test_an_empty_body_changes_nothing_and_returns_200(): void
    {
        Storage::fake('local');
        [$creditor, $group, $expense, $quota] = $this->paidExpense(['payment_note' => 'Mantida']);
        $quota->update(['payment_proof_path' => $this->putProof($group)]);
        $quota = $quota->fresh();
        $files = Storage::disk('local')->allFiles();

        $this->edit($creditor, $expense)->assertStatus(200);

        $this->assertNothingChanged($quota, $files);
    }

    // --- Estado final: foto OU descrição (D5) ----------------------------------

    public function test_removing_the_photo_of_a_payment_without_a_note_is_rejected_and_keeps_the_file(): void
    {
        Storage::fake('local');
        [$creditor, $group, $expense, $quota] = $this->paidExpense();
        $quota->update(['payment_proof_path' => $this->putProof($group)]);
        $quota = $quota->fresh();
        $files = Storage::disk('local')->allFiles();

        $this->edit($creditor, $expense, ['remover_comprovante' => true])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Informe a foto do comprovante ou uma descrição do pagamento.');

        $this->assertNothingChanged($quota, $files);
    }

    public function test_clearing_the_note_of_a_payment_without_a_photo_is_rejected(): void
    {
        Storage::fake('local');
        [$creditor, , $expense, $quota] = $this->paidExpense(['payment_note' => 'Só texto']);
        $files = Storage::disk('local')->allFiles();

        $this->edit($creditor, $expense, ['descricao' => ''])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Informe a foto do comprovante ou uma descrição do pagamento.');

        $this->assertNothingChanged($quota, $files);
    }

    public function test_removing_the_photo_and_clearing_the_note_together_is_rejected(): void
    {
        Storage::fake('local');
        [$creditor, $group, $expense, $quota] = $this->paidExpense(['payment_note' => 'Algo']);
        $quota->update(['payment_proof_path' => $this->putProof($group)]);
        $quota = $quota->fresh();
        $files = Storage::disk('local')->allFiles();

        $this->edit($creditor, $expense, ['remover_comprovante' => true, 'descricao' => null])
            ->assertStatus(422);

        $this->assertNothingChanged($quota, $files);
    }

    // --- Validação (R4) e conflito ---------------------------------------------

    public function test_sending_a_photo_together_with_remover_comprovante_is_rejected(): void
    {
        Storage::fake('local');
        [$creditor, $group, $expense, $quota] = $this->paidExpense(['payment_note' => 'Nota']);
        $quota->update(['payment_proof_path' => $this->putProof($group)]);
        $quota = $quota->fresh();
        $files = Storage::disk('local')->allFiles();

        $this->editMultipart($creditor, $expense, [
            'comprovante' => UploadedFile::fake()->image('nova.jpg'),
            'remover_comprovante' => '1',
        ])->assertStatus(422)
            ->assertJsonPath('error', 'Não é possível enviar uma foto nova e remover a foto na mesma edição.');

        $this->assertNothingChanged($quota, $files);
    }

    public function test_rejects_a_file_that_is_not_an_image(): void
    {
        Storage::fake('local');
        [$creditor, , $expense, $quota] = $this->paidExpense(['payment_note' => 'Nota']);
        $files = Storage::disk('local')->allFiles();

        $this->editMultipart($creditor, $expense, [
            'comprovante' => UploadedFile::fake()->create('recibo.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('comprovante');

        $this->assertNothingChanged($quota, $files);
    }

    public function test_rejects_a_photo_above_5_mb(): void
    {
        Storage::fake('local');
        [$creditor, , $expense, $quota] = $this->paidExpense(['payment_note' => 'Nota']);
        $files = Storage::disk('local')->allFiles();

        $this->editMultipart($creditor, $expense, [
            'comprovante' => UploadedFile::fake()->image('grande.jpg')->size(6000),
        ])->assertStatus(422)->assertJsonValidationErrors('comprovante');

        $this->assertNothingChanged($quota, $files);
    }

    public function test_rejects_a_descricao_longer_than_500_characters(): void
    {
        Storage::fake('local');
        [$creditor, , $expense, $quota] = $this->paidExpense(['payment_note' => 'Nota']);
        $files = Storage::disk('local')->allFiles();

        $this->edit($creditor, $expense, ['descricao' => str_repeat('a', 501)])
            ->assertStatus(422)->assertJsonValidationErrors('descricao');

        $this->assertNothingChanged($quota, $files);
    }

    public function test_accepts_a_descricao_of_exactly_500_characters(): void
    {
        Storage::fake('local');
        [$creditor, , $expense, $quota] = $this->paidExpense();

        $note = str_repeat('ã', 500);

        $this->edit($creditor, $expense, ['descricao' => $note])->assertStatus(200);

        $this->assertSame($note, $quota->fresh()->payment_note);
    }

    public function test_rejects_a_descricao_sent_as_an_array(): void
    {
        Storage::fake('local');
        [$creditor, , $expense, $quota] = $this->paidExpense(['payment_note' => 'Nota']);
        $files = Storage::disk('local')->allFiles();

        $this->edit($creditor, $expense, ['descricao' => ['x']])
            ->assertStatus(422)->assertJsonValidationErrors('descricao');

        $this->assertNothingChanged($quota, $files);
    }

    // --- Estado da quota e da competência ---------------------------------------

    public function test_an_unpaid_quota_is_rejected(): void
    {
        Storage::fake('local');
        [$creditor, , $expense, $quota] = $this->paidExpense(['paid' => false, 'paid_at' => null, 'paid_by' => null]);

        $this->editMultipart($creditor, $expense, [
            'comprovante' => UploadedFile::fake()->image('recibo.jpg'),
        ])->assertStatus(422)
            ->assertJsonPath('error', 'Esta despesa não está paga nessa competência.');

        $this->assertFalse((bool) $quota->fresh()->paid);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_a_fixed_occurrence_that_was_never_materialized_is_rejected_without_creating_a_quota(): void
    {
        Storage::fake('local');
        // Fixa criada em junho, quota só de junho: a ocorrência de agosto ainda é virtual.
        [$creditor, , $expense] = $this->paidExpense(
            ['date_expected' => '2026-06-05', 'paid' => false, 'paid_at' => null, 'paid_by' => null],
            ['expense_type' => 'FIXED', 'date_payment' => '2026-06-05']
        );

        $this->assertSame(1, Quota::where('expense_id', $expense->id)->count());

        $this->edit($creditor, $expense, ['descricao' => 'Nada a editar'])->assertStatus(422);

        $this->assertSame(1, Quota::where('expense_id', $expense->id)->count());
    }

    public function test_a_sealed_cycle_is_rejected_and_the_snapshot_and_quota_stay_intact(): void
    {
        Storage::fake('local');
        [$creditor, $group, $expense, $quota] = $this->paidExpense(['payment_note' => 'Selada']);
        Carbon::setTestNow('2026-09-15');

        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'totals' => ['total' => 100, 'paid' => 100, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'settlements' => [],
            'settled_at' => now(),
        ]);
        $files = Storage::disk('local')->allFiles();

        $this->edit($creditor, $expense, ['cycles_ago' => 1, 'descricao' => 'Tentativa'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Esta competência já foi encerrada. Desfaça o pagamento para corrigir o comprovante.');

        $this->assertNothingChanged($quota, $files);
        $this->assertDatabaseMissing('ex_group_cycle_snapshots', [
            'group_id' => $group->id, 'cycle_start' => '2026-08-01', 'settled_at' => null,
        ]);
    }

    public function test_a_cycle_closed_by_date_but_not_sealed_can_be_edited(): void
    {
        Storage::fake('local');
        [$creditor, , $expense, $quota] = $this->paidExpense(['payment_note' => 'Antiga']);
        Carbon::setTestNow('2026-09-15');

        $this->edit($creditor, $expense, ['cycles_ago' => 1, 'descricao' => 'Corrigida'])
            ->assertStatus(200);

        $this->assertSame('Corrigida', $quota->fresh()->payment_note);
    }

    public function test_a_manually_closed_cycle_that_is_not_sealed_can_be_edited(): void
    {
        Storage::fake('local');
        [$creditor, $group, $expense, $quota] = $this->paidExpense(['payment_note' => 'Antiga']);

        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'totals' => ['total' => 0, 'paid' => 0, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => now(),
        ]);

        $this->edit($creditor, $expense, ['descricao' => 'Corrigida'])->assertStatus(200);

        $this->assertSame('Corrigida', $quota->fresh()->payment_note);
    }

    public function test_cycles_ago_edits_the_quota_of_the_requested_cycle_and_not_the_current_one(): void
    {
        Storage::fake('local');
        [$creditor, , $expense, $august] = $this->paidExpense(
            ['payment_note' => 'Agosto'],
            ['expense_type' => 'IN_INSTALLMENTS', 'installments' => 2, 'total_value' => 200]
        );
        $september = $expense->quotas()->create([
            'date_expected' => '2026-09-10', 'number' => 2, 'paid' => true,
            'paid_at' => self::PAID_AT, 'paid_by' => $creditor->id, 'value_quota' => 100,
            'payment_note' => 'Setembro',
        ]);
        Carbon::setTestNow('2026-09-15');

        $this->edit($creditor, $expense, ['cycles_ago' => 1, 'descricao' => 'Agosto corrigido'])
            ->assertStatus(200);

        $this->assertSame('Agosto corrigido', $august->fresh()->payment_note);
        $this->assertSame('Setembro', $september->fresh()->payment_note);
    }

    public function test_a_born_paid_quota_can_be_edited(): void
    {
        Storage::fake('local');
        [$creditor, , $expense, $quota] = $this->paidExpense(['born_paid' => true]);

        $this->edit($creditor, $expense, ['descricao' => 'Registro tardio'])->assertStatus(200);

        $this->assertSame('Registro tardio', $quota->fresh()->payment_note);
    }

    // --- Autorização (R1) ------------------------------------------------------

    public function test_a_member_who_is_not_the_creditor_gets_403(): void
    {
        Storage::fake('local');
        [, $group, $expense, $quota] = $this->paidExpense(['payment_note' => 'Nota']);
        $other = User::factory()->create();
        $group->members()->attach($other->id);
        $files = Storage::disk('local')->allFiles();

        $this->edit($other, $expense, ['descricao' => 'Invasão'])->assertStatus(403);

        $this->assertNothingChanged($quota, $files);
    }

    public function test_a_non_member_gets_404(): void
    {
        Storage::fake('local');
        [, , $expense, $quota] = $this->paidExpense(['payment_note' => 'Nota']);
        $outsider = User::factory()->create();
        $files = Storage::disk('local')->allFiles();

        $this->edit($outsider, $expense, ['descricao' => 'Invasão'])->assertStatus(404);

        $this->assertNothingChanged($quota, $files);
    }

    public function test_requires_authentication(): void
    {
        [, , $expense] = $this->paidExpense();

        $this->postJson("/api/expenses/{$expense->id}/payment-proof", ['descricao' => 'x'])
            ->assertStatus(401);
    }

    // --- Efeitos que a rota NÃO pode ter (R2, D3) ------------------------------

    public function test_editing_sends_no_notification_of_any_kind(): void
    {
        Storage::fake('local');
        Http::fake();
        config()->set('services.whatsapp', [
            'enabled' => true,
            'token' => 'tok',
            'phone_number_id' => '999',
            'api_version' => 'v21.0',
            'locale' => 'pt_BR',
            'templates' => ['expense_proof' => 'tpl_e', 'settlement_proof' => 'tpl_s'],
        ]);

        [$creditor, $group, $expense] = $this->paidExpense();
        $participant = User::factory()->create();
        $participant->whatsapp = '(11) 90000-0002';
        $participant->notify_whatsapp = true;
        $participant->save();
        $group->members()->attach($participant->id);
        $expense->payers()->attach($participant->id);

        $notificationsBefore = DB::table('ex_notifications')->count();

        $this->editMultipart($creditor, $expense, [
            'comprovante' => UploadedFile::fake()->image('recibo.jpg'),
            'descricao' => 'Com foto e texto',
        ])->assertStatus(200);

        Http::assertNothingSent();
        $this->assertSame($notificationsBefore, DB::table('ex_notifications')->count());
    }

    // --- Falha na gravação (R3) ------------------------------------------------

    public function test_a_failure_while_updating_deletes_the_new_file_and_keeps_the_old_one(): void
    {
        Storage::fake('local');
        [$creditor, $group, $expense, $quota] = $this->paidExpense(['payment_note' => 'Nota']);
        $old = $this->putProof($group);
        $quota->update(['payment_proof_path' => $old]);
        $quota = $quota->fresh();

        Quota::updating(function () {
            throw new \RuntimeException('falha simulada ao gravar a quota');
        });

        $this->editMultipart($creditor, $expense, [
            'comprovante' => UploadedFile::fake()->image('nova.jpg'),
        ])->assertStatus(500);

        Quota::flushEventListeners();

        $this->assertSame([$old], Storage::disk('local')->allFiles());
        $this->assertSame($old, $quota->fresh()->payment_proof_path);
    }

    // --- Corrida entre editar e desfazer (TASK-462, achado B2 da revisão de segurança) ---

    public function test_the_quota_is_read_with_a_row_lock_inside_a_nested_transaction(): void
    {
        // O que impede a edição de gravar foto e descrição numa quota que o
        // `unpay` acabou de despagar: a leitura que decide (e o `update`) rodam
        // numa transação, com a linha travada (`for update`). Não dá para
        // intercalar duas conexões num teste com `DatabaseTransactions`; então
        // provamos o mecanismo: o `select` travado existe e roda dentro de uma
        // transação mais funda que a do próprio teste.
        Storage::fake('local');
        [$creditor, , $expense] = $this->paidExpense(['payment_note' => 'Antiga']);

        $baseline = DB::transactionLevel();
        $lockedAtLevel = [];

        DB::listen(function ($query) use (&$lockedAtLevel) {
            if (str_contains($query->sql, 'ex_quotas') && str_contains(strtolower($query->sql), 'for update')) {
                $lockedAtLevel[] = DB::transactionLevel();
            }
        });

        $this->edit($creditor, $expense, ['descricao' => 'Nova'])->assertStatus(200);

        $this->assertNotEmpty($lockedAtLevel, 'a quota deveria ser lida com `for update`');

        foreach ($lockedAtLevel as $level) {
            $this->assertGreaterThan($baseline, $level, 'o `for update` deveria rodar dentro de uma transação aninhada');
        }
    }

    public function test_editing_right_after_unpay_is_rejected_and_never_leaves_a_proof_on_an_unpaid_quota(): void
    {
        Storage::fake('local');
        [$creditor, $group, $expense, $quota] = $this->paidExpense(['payment_note' => 'Antes de desfazer']);
        $quota->update(['payment_proof_path' => $this->putProof($group)]);

        $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/expenses/{$expense->id}/unpay")
            ->assertStatus(200);

        $this->editMultipart($creditor, $expense, [
            'comprovante' => UploadedFile::fake()->image('tarde-demais.jpg'),
            'descricao' => 'Tarde demais',
        ])->assertStatus(422)
            ->assertJsonPath('error', 'Esta despesa não está paga nessa competência.');

        $fresh = $quota->fresh();
        $this->assertFalse((bool) $fresh->paid);
        $this->assertNull($fresh->payment_proof_path);
        $this->assertNull($fresh->payment_note);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }
}
