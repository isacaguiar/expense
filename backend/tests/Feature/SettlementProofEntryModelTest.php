<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\SettlementConfirmation;
use App\Models\SettlementProofEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * Histórico dos comprovantes do acerto (feature 20261114-historico-de-comprovantes-e-justificativa,
 * plan §1, TASK-616): a tabela `ex_settlement_proof_entries` (só de acréscimo, R1), a coluna
 * `ex_settlement_confirmations.refusal_reason` e o model `SettlementProofEntry`.
 */
class SettlementProofEntryModelTest extends TestCase
{
    use DatabaseTransactions;

    private const TABLE = 'ex_settlement_proof_entries';

    /** @return array<string, object> as colunas da tabela, por nome, lidas do `INFORMATION_SCHEMA`. */
    private function columnsOf(string $table): array
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME AS name, IS_NULLABLE AS nullable, COLUMN_TYPE AS type, EXTRA AS extra, ORDINAL_POSITION AS position
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION',
            [$table]
        );

        return collect($rows)->keyBy('name')->all();
    }

    /** @return array{0: Group, 1: User, 2: User, 3: User} */
    private function scenario(): array
    {
        [$debtor, $creditor, $sender] = User::factory()->count(3)->create()->all();
        $group = Group::create(['name' => 'Grupo do histórico']);
        $group->members()->attach([$debtor->id, $creditor->id, $sender->id]);

        return [$group, $debtor, $creditor, $sender];
    }

    /** @param  array<string, mixed>  $overrides */
    private function entry(Group $group, User $debtor, User $creditor, ?User $sender, array $overrides = []): SettlementProofEntry
    {
        return SettlementProofEntry::create($overrides + [
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'kind' => 'replaced',
            'sent_by' => $sender?->id,
            'status_at_exit' => 'awaiting_acceptance',
            'amount' => 50.25,
            'proof_path' => "comprovantes/{$group->id}/antigo.jpg",
            'note' => 'foto trocada',
        ]);
    }

    // --- o schema ----------------------------------------------------------

    public function test_the_entries_table_has_the_planned_columns(): void
    {
        $this->assertTrue(Schema::hasTable(self::TABLE));
        $this->assertTrue(Schema::hasColumns(self::TABLE, [
            'id', 'group_id', 'cycle_start', 'from_user_id', 'to_user_id', 'kind', 'sent_by', 'status_at_exit',
            'amount', 'proof_path', 'note', 'refusal_reason', 'sent_at', 'refused_at', 'created_at',
        ]));
    }

    public function test_the_entries_table_has_no_updated_at_because_it_is_append_only(): void
    {
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'updated_at'));
    }

    public function test_the_date_columns_are_nullable_and_nothing_updates_by_itself(): void
    {
        $columns = $this->columnsOf(self::TABLE);

        foreach (['sent_at', 'refused_at', 'created_at'] as $name) {
            $this->assertSame('YES', $columns[$name]->nullable, "{$name} precisa ser nula (a armadilha do item 147)");
        }

        foreach ($columns as $name => $column) {
            $this->assertStringNotContainsStringIgnoringCase('on update', (string) $column->extra, "{$name} não pode ter ON UPDATE");
        }
    }

    public function test_the_pair_index_is_not_unique_and_follows_the_pair_key(): void
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME AS name, NON_UNIQUE AS non_unique
               FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
              ORDER BY SEQ_IN_INDEX',
            [self::TABLE, 'settlement_proof_entries_pair_index']
        );

        $this->assertSame(['group_id', 'cycle_start', 'from_user_id', 'to_user_id'], array_column($rows, 'name'));
        $this->assertSame(1, (int) $rows[0]->non_unique);
    }

    public function test_the_foreign_keys_cascade_except_the_sender_which_is_nulled(): void
    {
        $rows = DB::select(
            'SELECT k.COLUMN_NAME AS name, r.DELETE_RULE AS delete_rule, k.REFERENCED_TABLE_NAME AS referenced
               FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
               JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r
                 ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
              WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL',
            [self::TABLE]
        );
        $rules = collect($rows)->mapWithKeys(fn ($row) => [$row->name => [$row->delete_rule, $row->referenced]])->all();

        $this->assertSame(['CASCADE', 'ex_groups'], $rules['group_id']);
        $this->assertSame(['CASCADE', 'ex_users'], $rules['from_user_id']);
        $this->assertSame(['CASCADE', 'ex_users'], $rules['to_user_id']);
        $this->assertSame(['SET NULL', 'ex_users'], $rules['sent_by']);
    }

    public function test_the_confirmations_table_gains_a_nullable_refusal_reason_after_refused_at(): void
    {
        $columns = $this->columnsOf('ex_settlement_confirmations');

        $this->assertArrayHasKey('refusal_reason', $columns);
        $this->assertSame('varchar(500)', $columns['refusal_reason']->type);
        $this->assertSame('YES', $columns['refusal_reason']->nullable);
        $this->assertSame((int) $columns['refused_at']->position + 1, (int) $columns['refusal_reason']->position);
    }

    // --- o model -----------------------------------------------------------

    public function test_creating_an_entry_stamps_created_at_and_casts_the_values(): void
    {
        Carbon::setTestNow('2026-08-22 09:30:00');
        [$group, $debtor, $creditor, $sender] = $this->scenario();

        $entry = $this->entry($group, $debtor, $creditor, $sender, [
            'sent_at' => '2026-08-20 10:00:00',
            'refused_at' => '2026-08-21 11:00:00',
            'refusal_reason' => 'foto cortada',
        ])->fresh();

        $this->assertSame('2026-08-22 09:30:00', $entry->created_at->toDateTimeString());
        $this->assertNull($entry->getAttribute('updated_at'));
        $this->assertSame('2026-08-01', $entry->cycle_start->toDateString());
        $this->assertSame(50.25, $entry->amount);
        $this->assertSame('2026-08-20 10:00:00', $entry->sent_at->toDateTimeString());
        $this->assertSame('2026-08-21 11:00:00', $entry->refused_at->toDateTimeString());
        $this->assertSame('foto cortada', $entry->refusal_reason);
        $this->assertSame('replaced', $entry->kind);
        $this->assertSame('awaiting_acceptance', $entry->status_at_exit);
    }

    public function test_an_entry_cannot_be_updated(): void
    {
        [$group, $debtor, $creditor, $sender] = $this->scenario();
        $entry = $this->entry($group, $debtor, $creditor, $sender);

        try {
            $entry->update(['note' => 'reescrito']);
            $this->fail('R1: uma entrada do histórico não pode ser editada');
        } catch (LogicException) {
            // esperado
        }

        $this->assertSame('foto trocada', $entry->fresh()->note);
    }

    public function test_an_entry_cannot_be_deleted(): void
    {
        [$group, $debtor, $creditor, $sender] = $this->scenario();
        $entry = $this->entry($group, $debtor, $creditor, $sender);

        try {
            $entry->delete();
            $this->fail('R1: uma entrada do histórico não pode ser apagada');
        } catch (LogicException) {
            // esperado
        }

        $this->assertNotNull(SettlementProofEntry::find($entry->id));
    }

    public function test_the_proof_url_is_a_signed_url_of_the_history_type_valid_for_thirty_minutes(): void
    {
        [$group, $debtor, $creditor, $sender] = $this->scenario();
        $entry = $this->entry($group, $debtor, $creditor, $sender);

        $url = $entry->proofUrl();

        $this->assertNotNull($url);
        $this->assertStringContainsString("/groups/{$group->id}/proofs/settlement_history/{$entry->id}", $url);
        $this->assertTrue(Request::create($url)->hasValidSignature());

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, (int) $query['expires'], 5);
    }

    public function test_the_proof_url_is_null_without_a_path_and_is_not_serialized_with_the_model(): void
    {
        [$group, $debtor, $creditor, $sender] = $this->scenario();
        $entry = $this->entry($group, $debtor, $creditor, $sender);

        $this->assertArrayNotHasKey('proof_url', $entry->toArray());

        $entry->proof_path = '';
        $this->assertNull($entry->proofUrl());
    }

    public function test_the_relations_resolve(): void
    {
        [$group, $debtor, $creditor, $sender] = $this->scenario();
        $entry = $this->entry($group, $debtor, $creditor, $sender);

        $this->assertTrue($entry->sender->is($sender));
        $this->assertTrue($entry->fromUser->is($debtor));
        $this->assertTrue($entry->toUser->is($creditor));
    }

    public function test_deleting_the_sender_nulls_sent_by_and_keeps_the_entry(): void
    {
        [$group, $debtor, $creditor, $sender] = $this->scenario();
        $entry = $this->entry($group, $debtor, $creditor, $sender);

        DB::table('ex_users')->where('id', $sender->id)->delete();

        $entry = SettlementProofEntry::find($entry->id);
        $this->assertNotNull($entry, 'a entrada fica quando a conta de quem enviou some');
        $this->assertNull($entry->sent_by);
    }

    public function test_the_confirmation_accepts_a_refusal_reason(): void
    {
        [$group, $debtor, $creditor] = $this->scenario();

        $confirmation = SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'amount' => 10,
            'proof_path' => "comprovantes/{$group->id}/a.jpg",
            'confirmed_at' => '2026-08-20 10:00:00',
            'refusal_reason' => 'não é deste acerto',
        ]);

        $this->assertSame('não é deste acerto', $confirmation->fresh()->refusal_reason);
    }
}
