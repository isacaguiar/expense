<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\SettlementConfirmation;
use App\Models\SettlementProofEntry;
use App\Models\User;
use App\Services\CycleSealingService;
use App\Services\SettlementConfirmationService;
use App\Services\SettlementProofHistoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\Concerns\BuildsThirdPartyProofScenarios;
use Tests\Concerns\FailsToWriteProofs;
use Tests\TestCase;

/**
 * Reenviar o comprovante do acerto guarda o anterior no histórico e respeita o teto (feature
 * 20261114-historico-de-comprovantes-e-justificativa, plan §2, TASK-619): o arquivo substituído **não é
 * apagado** (R2) e vai, com a nota, o estado e quem o enviou, para `ex_settlement_proof_entries` na mesma
 * transação da troca (F1); o primeiro envio não cria entrada; o aceito segue definitivo (R4) e o ciclo
 * encerrado segue imutável (R5); com 10 entradas, enviar e reenviar dão 422 **antes de gravar o arquivo** (R8).
 */
class SettlementResendKeepsHistoryTest extends TestCase
{
    use BuildsSettlementScenarios;
    use BuildsThirdPartyProofScenarios;
    use DatabaseTransactions;
    use FailsToWriteProofs;

    private const LIMIT_MESSAGE = 'O limite de comprovantes guardados deste acerto foi atingido.';

    protected function tearDown(): void
    {
        SettlementConfirmation::flushEventListeners();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return list<string> */
    private function files(Group $group): array
    {
        return Storage::disk('local')->allFiles("comprovantes/{$group->id}");
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, SettlementProofEntry> */
    private function entries(Group $group)
    {
        return SettlementProofEntry::where('group_id', $group->id)->orderBy('id')->get();
    }

    private function addEntries(Group $group, User $debtor, User $creditor, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            SettlementProofEntry::create([
                'group_id' => $group->id,
                'cycle_start' => '2026-08-01',
                'from_user_id' => $debtor->id,
                'to_user_id' => $creditor->id,
                'kind' => SettlementProofEntry::KIND_REPLACED,
                'sent_by' => $debtor->id,
                'status_at_exit' => 'sent',
                'amount' => 100,
                'proof_path' => "comprovantes/{$group->id}/velho{$i}.jpg",
            ]);
        }
    }

    // --- reenviar guarda o anterior ---------------------------------------

    public function test_the_debtor_resending_their_own_proof_archives_the_old_one_with_its_file(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->linkedScenario();
        $proof->update(['confirmed_by' => null, 'note' => 'primeira foto']);
        $oldPath = $proof->proof_path;

        $this->confirmAs($debtor, $group, $creditor)->assertOk();

        $entries = $this->entries($group);
        $this->assertCount(1, $entries);
        $entry = $entries->first();
        $this->assertSame(SettlementProofEntry::KIND_REPLACED, $entry->kind);
        $this->assertSame('sent', $entry->status_at_exit);
        $this->assertSame($debtor->id, (int) $entry->sent_by);
        $this->assertSame($oldPath, $entry->proof_path);
        $this->assertSame('primeira foto', $entry->note);
        $this->assertSame(100.0, $entry->amount);

        $row = $proof->fresh();
        $this->assertNotSame($oldPath, $row->proof_path);
        Storage::disk('local')->assertExists($oldPath);
        Storage::disk('local')->assertExists($row->proof_path);
        $this->assertCount(2, $this->files($group));
    }

    public function test_the_responsible_resending_over_an_awaiting_proof_archives_it(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $oldPath = $proof->proof_path;

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();

        $entry = $this->entries($group)->sole();
        $this->assertSame('awaiting_acceptance', $entry->status_at_exit);
        $this->assertSame($responsible->id, (int) $entry->sent_by);
        $this->assertSame($oldPath, $entry->proof_path);
        Storage::disk('local')->assertExists($oldPath);
    }

    public function test_resending_a_refused_proof_archives_it_with_the_reason_and_clears_the_live_row(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $proof->update(['refused_at' => '2026-08-21 09:00:00', 'refusal_reason' => 'foto cortada']);
        $oldPath = $proof->proof_path;

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();

        $entry = $this->entries($group)->sole();
        $this->assertSame('refused', $entry->status_at_exit);
        $this->assertSame('foto cortada', $entry->refusal_reason);
        $this->assertSame('2026-08-21 09:00:00', $entry->refused_at->toDateTimeString());
        $this->assertSame($oldPath, $entry->proof_path);
        Storage::disk('local')->assertExists($oldPath);

        $row = $proof->fresh();
        $this->assertNull($row->refused_at);
        $this->assertNull($row->refusal_reason);
        $this->assertNull($row->accepted_at);
    }

    public function test_the_debtor_taking_over_a_responsible_proof_archives_it_as_the_responsibles(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();

        $this->confirmAs($debtor, $group, $creditor)->assertOk();

        $entry = $this->entries($group)->sole();
        $this->assertSame($responsible->id, (int) $entry->sent_by, 'a entrada guarda quem enviou o comprovante que saiu');
        $this->assertNull($proof->fresh()->confirmed_by, 'o devedor assumiu o comprovante');
    }

    public function test_each_resend_adds_one_entry_and_keeps_every_file(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $firstPath = $proof->proof_path;

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();
        $secondPath = $proof->fresh()->proof_path;
        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();

        $entries = $this->entries($group);
        $this->assertCount(2, $entries);
        $this->assertSame([$firstPath, $secondPath], $entries->pluck('proof_path')->all());
        $this->assertCount(3, $this->files($group), 'os dois do histórico e o atual');
        foreach ($this->files($group) as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_the_first_send_creates_no_entry(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->linkedScenario();
        $proof->delete();

        $this->confirmAs($debtor, $group, $creditor)->assertOk();

        $this->assertSame(0, $this->entries($group)->count());
    }

    public function test_submit_proof_no_longer_returns_the_previous_path(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $key = [
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
        ];

        $result = app(SettlementConfirmationService::class)->submitProof($key, [
            'cycle_end' => '2026-08-31',
            'amount' => 100,
            'proof_path' => "comprovantes/{$group->id}/novo.jpg",
            'note' => null,
            'confirmed_at' => Carbon::now(),
            'confirmed_by' => $responsible->id,
        ], true);

        $this->assertArrayNotHasKey('previousPath', $result, 'nada mais apaga o arquivo anterior (R2)');
        $this->assertNotNull($result['confirmation']);
        $this->assertSame(1, $this->entries($group)->count());
    }

    // --- o que continua barrado -------------------------------------------

    public function test_an_accepted_proof_is_still_definitive_and_creates_no_entry_or_file(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $proof->update(['accepted_at' => '2026-08-21 09:00:00']);

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)
            ->assertStatus(422)
            ->assertJson(['error' => 'Este comprovante já foi aceito.']);

        $this->assertSame(0, $this->entries($group)->count());
        $this->assertSame([$proof->proof_path], $this->files($group));
    }

    public function test_the_responsible_still_cannot_replace_the_debtors_own_proof(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $proof->update(['confirmed_by' => null]);

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)
            ->assertStatus(422)
            ->assertJson(['error' => 'O devedor já enviou o comprovante deste acerto.']);

        $this->assertSame(0, $this->entries($group)->count());
        $this->assertSame([$proof->proof_path], $this->files($group));
    }

    public function test_a_sealed_cycle_still_refuses_the_resend_and_creates_no_entry(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->linkedScenario(paid: true);
        $proof->update(['confirmed_by' => null]);
        $this->assertTrue(app(CycleSealingService::class)->sealIfSettled($group, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')));

        $this->confirmAs($debtor, $group, $creditor)
            ->assertStatus(422)
            ->assertJson(['error' => 'Esta competência já foi encerrada.']);

        $this->assertSame(0, $this->entries($group)->count());
        $this->assertSame([$proof->proof_path], $this->files($group));
    }

    // --- o teto -----------------------------------------------------------

    public function test_with_ten_entries_a_resend_is_refused_before_the_file_is_written(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $this->addEntries($group, $debtor, $creditor, 10);
        // Se o controller tentasse gravar o arquivo antes de checar o teto, daria 500 em vez de 422.
        $this->failProofWrites();
        Storage::disk('local')->put($proof->proof_path, 'comprovante anterior');

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)
            ->assertStatus(422)
            ->assertJson(['error' => self::LIMIT_MESSAGE]);

        $this->assertSame(10, $this->entries($group)->count());
        $this->assertSame([$proof->proof_path], $this->files($group));
        $this->assertSame($proof->proof_path, $proof->fresh()->proof_path);
    }

    public function test_with_ten_entries_even_the_first_send_is_refused_before_the_file_is_written(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->linkedScenario();
        $proof->delete();
        $this->addEntries($group, $debtor, $creditor, 10);
        $this->failProofWrites();

        $this->confirmAs($debtor, $group, $creditor)
            ->assertStatus(422)
            ->assertJson(['error' => self::LIMIT_MESSAGE]);

        $this->assertSame(0, SettlementConfirmation::where('group_id', $group->id)->count());
    }

    public function test_nine_entries_still_leave_room_for_one_more_resend(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->linkedScenario();
        $this->addEntries($group, $debtor, $creditor, 9);

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();

        $this->assertSame(10, $this->entries($group)->count());
        $this->assertTrue(app(SettlementProofHistoryService::class)->isFull($group->id, '2026-08-01', $debtor->id, $creditor->id));
    }

    public function test_the_service_checks_the_limit_again_under_the_row_lock(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $this->addEntries($group, $debtor, $creditor, 10);
        $before = $proof->fresh()->getAttributes();
        $key = [
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
        ];

        // A checagem do controller já passou (outro pedido encheu o histórico depois dela): é a do service que vale.
        $result = app(SettlementConfirmationService::class)->submitProof($key, [
            'cycle_end' => '2026-08-31',
            'amount' => 100,
            'proof_path' => "comprovantes/{$group->id}/novo.jpg",
            'note' => null,
            'confirmed_at' => Carbon::now(),
            'confirmed_by' => $responsible->id,
        ], true);

        $this->assertNull($result['confirmation']);
        $this->assertSame(SettlementConfirmationService::BLOCKED_HISTORY_FULL, $result['blockedBy']);
        $this->assertSame(10, $this->entries($group)->count(), 'nada foi arquivado');
        $this->assertSame($before, $proof->fresh()->getAttributes(), 'a linha atual segue igual');
    }

    public function test_the_service_also_limits_the_first_send_when_the_history_filled_up_meanwhile(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $proof->delete();
        $this->addEntries($group, $debtor, $creditor, 10);
        $key = [
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
        ];

        // Sem linha atual (`$hadRow` falso): o teto vale mesmo assim, é o que fecha o laço enviar, retirar, enviar.
        $result = app(SettlementConfirmationService::class)->submitProof($key, [
            'cycle_end' => '2026-08-31',
            'amount' => 100,
            'proof_path' => "comprovantes/{$group->id}/novo.jpg",
            'note' => null,
            'confirmed_at' => Carbon::now(),
            'confirmed_by' => $responsible->id,
        ], false);

        $this->assertNull($result['confirmation']);
        $this->assertSame(SettlementConfirmationService::BLOCKED_HISTORY_FULL, $result['blockedBy']);
        $this->assertSame(0, SettlementConfirmation::where('group_id', $group->id)->count());
    }

    // --- atomicidade ------------------------------------------------------

    public function test_a_failure_saving_the_new_row_rolls_back_the_entry_and_removes_only_the_new_file(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $oldPath = $proof->proof_path;
        $before = $proof->fresh()->getAttributes();

        SettlementConfirmation::saving(function () {
            throw new RuntimeException('falha simulada ao gravar a linha');
        });

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertStatus(500);

        SettlementConfirmation::flushEventListeners();
        $this->assertSame(0, $this->entries($group)->count(), 'a entrada desfez junto da troca (F1)');
        $this->assertSame($before, $proof->fresh()->getAttributes());
        $this->assertSame([$oldPath], $this->files($group), 'o arquivo anterior segue, o novo foi apagado');
    }
}
