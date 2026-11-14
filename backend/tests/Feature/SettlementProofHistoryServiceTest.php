<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\SettlementConfirmation;
use App\Models\SettlementProofEntry;
use App\Models\User;
use App\Services\SettlementProofHistoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * `SettlementProofHistoryService` (feature 20261114-historico-de-comprovantes-e-justificativa, plan §2,
 * TASK-617): o teto de entradas por par e ciclo (`isFull`, D5) e o arquivamento do comprovante que sai de
 * cena (`archive`, R1, R2 e R4). Não grava arquivo, não muda a linha atual e não abre transação: quem
 * chama (reenviar e retirar, TASK-619 e 621) já está numa.
 */
class SettlementProofHistoryServiceTest extends TestCase
{
    use DatabaseTransactions;

    private const CYCLE = '2026-08-01';

    private SettlementProofHistoryService $history;

    private Group $group;

    private User $debtor;

    private User $creditor;

    private User $responsible;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-19 12:00:00');
        $this->history = app(SettlementProofHistoryService::class);

        [$this->debtor, $this->creditor, $this->responsible] = User::factory()->count(3)->create()->all();
        $this->group = Group::create(['name' => 'Grupo do histórico']);
        $this->group->members()->attach([$this->debtor->id, $this->creditor->id, $this->responsible->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $overrides */
    private function liveRow(array $overrides = []): SettlementConfirmation
    {
        return SettlementConfirmation::create($overrides + [
            'group_id' => $this->group->id,
            'cycle_start' => self::CYCLE,
            'cycle_end' => '2026-08-31',
            'from_user_id' => $this->debtor->id,
            'to_user_id' => $this->creditor->id,
            'confirmed_by' => null,
            'amount' => 75.5,
            'proof_path' => "comprovantes/{$this->group->id}/atual.jpg",
            'note' => 'pix de agosto',
            'confirmed_at' => '2026-08-20 10:00:00',
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function addEntries(int $count, array $overrides = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            SettlementProofEntry::create($overrides + [
                'group_id' => $this->group->id,
                'cycle_start' => self::CYCLE,
                'from_user_id' => $this->debtor->id,
                'to_user_id' => $this->creditor->id,
                'kind' => SettlementProofEntry::KIND_REPLACED,
                'sent_by' => $this->debtor->id,
                'status_at_exit' => 'sent',
                'amount' => 10,
                'proof_path' => "comprovantes/{$this->group->id}/e{$i}.jpg",
            ]);
        }
    }

    private function isFull(): bool
    {
        return $this->history->isFull($this->group->id, self::CYCLE, $this->debtor->id, $this->creditor->id);
    }

    // --- o teto ------------------------------------------------------------

    public function test_the_limit_is_ten_entries_per_pair_and_cycle(): void
    {
        $this->assertSame(10, SettlementProofHistoryService::MAX_ENTRIES);
    }

    public function test_it_is_not_full_with_nine_entries_and_is_full_with_ten(): void
    {
        $this->assertFalse($this->isFull(), 'sem entradas');

        $this->addEntries(9);
        $this->assertFalse($this->isFull(), 'com 9 ainda cabe uma');

        $this->addEntries(1);
        $this->assertTrue($this->isFull(), 'com 10 encheu');

        $this->addEntries(1);
        $this->assertTrue($this->isFull(), 'acima de 10 segue cheio (a retirada não tem teto)');
    }

    public function test_entries_of_other_pairs_cycles_and_groups_do_not_count(): void
    {
        $other = User::factory()->create();
        $otherGroup = Group::create(['name' => 'Outro grupo']);

        $this->addEntries(10, ['cycle_start' => '2026-07-01']);
        $this->addEntries(10, ['from_user_id' => $this->responsible->id]);
        $this->addEntries(10, ['to_user_id' => $other->id]);
        $this->addEntries(10, ['group_id' => $otherGroup->id]);

        $this->assertFalse($this->isFull());
    }

    public function test_the_cycle_start_can_be_a_carbon_or_a_date_string(): void
    {
        $this->addEntries(10);

        $this->assertTrue($this->history->isFull($this->group->id, Carbon::parse(self::CYCLE), $this->debtor->id, $this->creditor->id));
        $this->assertTrue($this->history->isFull($this->group->id, self::CYCLE, $this->debtor->id, $this->creditor->id));
    }

    // --- arquivar ----------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3: string}> */
    public static function exitingStates(): array
    {
        return [
            'sent by the debtor (confirmed_by null)' => [['confirmed_by' => null], 'sent', 'debtor', 'replaced'],
            'sent by the debtor (confirmed_by the debtor)' => [['confirmed_by' => 'DEBTOR'], 'sent', 'debtor', 'withdrawn'],
            'awaiting the creditor acceptance' => [['confirmed_by' => 'RESPONSIBLE'], 'awaiting_acceptance', 'responsible', 'replaced'],
            'refused with a reason' => [[
                'confirmed_by' => 'RESPONSIBLE',
                'refused_at' => '2026-08-21 09:00:00',
                'refusal_reason' => 'foto cortada',
            ], 'refused', 'responsible', 'withdrawn'],
            'refused without a reason' => [['confirmed_by' => null, 'refused_at' => '2026-08-21 09:00:00'], 'refused', 'debtor', 'replaced'],
        ];
    }

    /**
     * @dataProvider exitingStates
     *
     * @param  array<string, mixed>  $attributes
     */
    public function test_archive_copies_the_proof_that_leaves(array $attributes, string $status, string $sender, string $kind): void
    {
        // O provider roda antes do `setUp`: os ids dos usuários entram aqui.
        $attributes['confirmed_by'] = match ($attributes['confirmed_by']) {
            'DEBTOR' => $this->debtor->id,
            'RESPONSIBLE' => $this->responsible->id,
            default => null,
        };
        $row = $this->liveRow($attributes);

        $entry = $this->history->archive($row, $kind);

        $entry = $entry->fresh();
        $this->assertSame($kind, $entry->kind);
        $this->assertSame($status, $entry->status_at_exit);
        $this->assertSame($sender === 'debtor' ? $this->debtor->id : $this->responsible->id, (int) $entry->sent_by);
        $this->assertSame($this->group->id, (int) $entry->group_id);
        $this->assertSame(self::CYCLE, $entry->cycle_start->toDateString());
        $this->assertSame($this->debtor->id, (int) $entry->from_user_id);
        $this->assertSame($this->creditor->id, (int) $entry->to_user_id);
        $this->assertSame(75.5, $entry->amount);
        $this->assertSame($row->proof_path, $entry->proof_path);
        $this->assertSame('pix de agosto', $entry->note);
        $this->assertSame($attributes['refusal_reason'] ?? null, $entry->refusal_reason);
        $this->assertSame('2026-08-20 10:00:00', $entry->sent_at->toDateTimeString());
        $this->assertSame($attributes['refused_at'] ?? null, $entry->refused_at?->toDateTimeString());
        $this->assertSame('2026-08-19 12:00:00', $entry->created_at->toDateTimeString());
    }

    public function test_archive_never_takes_an_accepted_proof_into_the_history(): void
    {
        $row = $this->liveRow(['confirmed_by' => $this->responsible->id, 'accepted_at' => '2026-08-21 09:00:00']);

        try {
            $this->history->archive($row, SettlementProofEntry::KIND_REPLACED);
            $this->fail('R4: o comprovante aceito é definitivo e não vai ao histórico');
        } catch (LogicException) {
            // esperado
        }

        $this->assertSame(0, SettlementProofEntry::count());
    }

    public function test_archive_only_takes_the_kinds_of_a_proof_that_leaves(): void
    {
        $row = $this->liveRow();

        foreach (['complement', 'qualquer'] as $kind) {
            try {
                $this->history->archive($row, $kind);
                $this->fail("o tipo {$kind} não é de um comprovante que sai de cena");
            } catch (InvalidArgumentException) {
                // esperado
            }
        }

        $this->assertSame(0, SettlementProofEntry::count());
    }

    public function test_archive_does_not_touch_the_live_row_or_the_file(): void
    {
        Storage::fake('local');
        $row = $this->liveRow(['confirmed_by' => $this->responsible->id]);
        Storage::disk('local')->put($row->proof_path, 'conteudo do comprovante');
        $before = $row->fresh()->getAttributes();

        $this->history->archive($row, SettlementProofEntry::KIND_WITHDRAWN);

        $this->assertSame($before, $row->fresh()->getAttributes(), 'a linha atual segue igual (R3)');
        Storage::disk('local')->assertExists($row->proof_path);
        $this->assertSame(1, SettlementConfirmation::count());
    }
}
