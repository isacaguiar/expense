<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Estado do comprovante de acerto (feature 20261110-quem-paga-se-declara-e-credor-recusa, plan §1,
 * specify R5 a R7): derivado de `confirmed_by`, `accepted_at` e `refused_at`, sem coluna `status`.
 * `refused_at` preenchido = recusado; senão `accepted_at` = aceito; senão `confirmed_by` de outra
 * pessoa = aguardando aceite; senão = enviado pelo próprio devedor.
 */
class SettlementConfirmationStateTest extends TestCase
{
    use DatabaseTransactions;

    private const DEBTOR = 10;

    private const RESPONSIBLE = 20;

    private const WHEN = '2026-08-20 10:00:00';

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function statusCases(): array
    {
        return [
            'proof sent by the debtor' => [['confirmed_by' => null], 'sent'],
            'confirmed_by equal to the debtor is still the debtor own proof' => [['confirmed_by' => self::DEBTOR], 'sent'],
            'proof sent by a responsible, not decided yet' => [['confirmed_by' => self::RESPONSIBLE], 'awaiting_acceptance'],
            'proof sent by a responsible and accepted' => [['confirmed_by' => self::RESPONSIBLE, 'accepted_at' => self::WHEN], 'accepted'],
            'proof sent by a responsible and refused' => [['confirmed_by' => self::RESPONSIBLE, 'refused_at' => self::WHEN], 'refused'],
            'refused wins over accepted' => [['confirmed_by' => self::RESPONSIBLE, 'accepted_at' => self::WHEN, 'refused_at' => self::WHEN], 'refused'],
            'a refused debtor proof is refused' => [['confirmed_by' => null, 'refused_at' => self::WHEN], 'refused'],
            'an accepted debtor proof is accepted' => [['confirmed_by' => null, 'accepted_at' => self::WHEN], 'accepted'],
        ];
    }

    /**
     * @dataProvider statusCases
     *
     * @param  array<string, mixed>  $attributes
     */
    public function test_status_for_every_combination(array $attributes, string $expected): void
    {
        $confirmation = (new SettlementConfirmation)->forceFill(['from_user_id' => self::DEBTOR] + $attributes);

        $this->assertSame($expected, $confirmation->status());
    }

    public function test_it_is_a_third_party_proof_only_when_someone_else_sent_it(): void
    {
        $own = (new SettlementConfirmation)->forceFill(['from_user_id' => self::DEBTOR, 'confirmed_by' => null]);
        $same = (new SettlementConfirmation)->forceFill(['from_user_id' => self::DEBTOR, 'confirmed_by' => self::DEBTOR]);
        $other = (new SettlementConfirmation)->forceFill(['from_user_id' => self::DEBTOR, 'confirmed_by' => self::RESPONSIBLE]);

        $this->assertFalse($own->isThirdParty());
        $this->assertFalse($same->isThirdParty());
        $this->assertTrue($other->isThirdParty());
    }

    public function test_the_columns_exist_and_are_nullable_timestamps(): void
    {
        $this->assertTrue(Schema::hasColumn('ex_settlement_confirmations', 'accepted_at'));
        $this->assertTrue(Schema::hasColumn('ex_settlement_confirmations', 'refused_at'));

        $confirmation = (new SettlementConfirmation)->forceFill(['accepted_at' => self::WHEN, 'refused_at' => self::WHEN]);
        $this->assertSame(self::WHEN, $confirmation->accepted_at->format('Y-m-d H:i:s'));
        $this->assertSame(self::WHEN, $confirmation->refused_at->format('Y-m-d H:i:s'));
    }

    /**
     * Uma linha por devedor diferente no mesmo ciclo (a chave única é o par e o ciclo).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function row(Group $group, User $debtor, User $creditor, array $overrides = []): SettlementConfirmation
    {
        return SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'amount' => 50,
            'proof_path' => 'comprovantes/'.$group->id.'/'.$debtor->id.'.png',
            'confirmed_at' => self::WHEN,
        ] + $overrides);
    }

    /**
     * @return array{0: Group, 1: User, 2: array<string, SettlementConfirmation>}
     */
    private function scenario(): array
    {
        $creditor = User::factory()->create();
        $responsible = User::factory()->create();
        $group = Group::create(['name' => 'Casa']);
        $group->members()->attach([$creditor->id, $responsible->id]);

        $rows = [];
        $make = function (string $label, array $overrides) use (&$rows, $group, $creditor) {
            $debtor = User::factory()->create();
            $group->members()->attach($debtor->id);
            $rows[$label] = $this->row($group, $debtor, $creditor, $overrides);
        };

        $make('own', []);
        $make('own_same_sender', []);
        $rows['own_same_sender']->update(['confirmed_by' => $rows['own_same_sender']->from_user_id]);
        $make('awaiting', ['confirmed_by' => $responsible->id]);
        $make('accepted', ['confirmed_by' => $responsible->id, 'accepted_at' => self::WHEN]);
        $make('refused', ['confirmed_by' => $responsible->id, 'refused_at' => self::WHEN]);
        $make('accepted_and_refused', ['confirmed_by' => $responsible->id, 'accepted_at' => self::WHEN, 'refused_at' => self::WHEN]);
        $make('own_refused', ['refused_at' => self::WHEN]);

        return [$group, $creditor, $rows];
    }

    public function test_counts_for_settlement_scope_selects_only_the_ones_that_count(): void
    {
        [$group, , $rows] = $this->scenario();

        $counted = SettlementConfirmation::where('group_id', $group->id)->countsForSettlement()->pluck('id')->sort()->values()->all();

        $expected = collect(['own', 'own_same_sender', 'accepted'])->map(fn (string $label) => $rows[$label]->id)->sort()->values()->all();
        $this->assertSame($expected, $counted);
    }

    public function test_the_scope_agrees_with_the_status_of_every_row(): void
    {
        [$group, , $rows] = $this->scenario();

        $counted = SettlementConfirmation::where('group_id', $group->id)->countsForSettlement()->pluck('id')->all();

        foreach ($rows as $label => $row) {
            $fresh = $row->fresh();
            $shouldCount = in_array($fresh->status(), [SettlementConfirmation::STATUS_SENT, SettlementConfirmation::STATUS_ACCEPTED], true);
            $this->assertSame($shouldCount, in_array($row->id, $counted, true), "linha '{$label}' (".$fresh->status().')');
        }
    }
}
