<?php

namespace Tests\Unit;

use App\Support\ClosingBreakdown;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Agregação "credor → devedores" do Relatório de Fechamento: valor **bruto por
 * despesa**, não o acerto líquido par-a-par de `settlements`. Classe pura: o
 * teste não sobe o framework nem toca em banco.
 * Ver docs/feature/20261006-relatorios-acompanhamento-fechamento/plan.md §2.2.
 */
class ClosingBreakdownTest extends TestCase
{
    private const ANA = 1;

    private const BRUNO = 2;

    private const CARLA = 3;

    /** @return Collection<int, array{id: int, name: string, avatarUrl: ?string}> */
    private function members(): Collection
    {
        return collect([
            ['id' => self::ANA, 'name' => 'Ana', 'avatarUrl' => 'https://x/ana.jpg'],
            ['id' => self::BRUNO, 'name' => 'Bruno', 'avatarUrl' => 'https://x/bruno.jpg'],
            ['id' => self::CARLA, 'name' => 'Carla', 'avatarUrl' => null],
        ]);
    }

    /** @return array{id: int, name: string, avatarUrl: ?string} */
    private function person(int $id): array
    {
        return $this->members()->firstWhere('id', $id);
    }

    /**
     * Uma despesa no formato de `summary.expenses` (ao vivo ou snapshot): paga
     * por $creditor, dividida entre $participants (ids). O `valuePerPerson` é
     * absurdo de propósito: o relatório recalcula a fatia a partir de `value`.
     *
     * @param  array<int, int>  $participants
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function expense(int $creditor, array $participants, float $value, array $overrides = []): array
    {
        $people = array_map(fn (int $id) => $this->person($id), $participants);

        return array_merge([
            'id' => 1,
            'description' => 'Despesa',
            'value' => $value,
            'valuePerPerson' => 999.99,
            'paid' => false,
            'bornPaid' => false,
            'payerName' => $this->person($creditor)['name'],
            'userPayerId' => $creditor,
            'participants' => array_column($people, 'name'),
            'participantDetails' => $people,
        ], $overrides);
    }

    /** @return array<int, array{id: ?int, name: string, avatarUrl: ?string, amount: float}> */
    private function debtorsOf(array $result, int $creditorId): array
    {
        foreach ($result['creditors'] as $row) {
            if ($row['creditor']['id'] === $creditorId) {
                return $row['debtors'];
            }
        }

        $this->fail("Credor {$creditorId} não aparece no resultado.");
    }

    public function test_two_creditors_cross_each_other_in_gross_terms(): void
    {
        // A pagou 100 e B pagou 40, ambas divididas igualmente entre os dois.
        $result = ClosingBreakdown::build([
            $this->expense(self::ANA, [self::ANA, self::BRUNO], 100),
            $this->expense(self::BRUNO, [self::ANA, self::BRUNO], 40),
        ], $this->members());

        $this->assertCount(2, $result['creditors']);

        // Ordenado por quanto adiantou: Ana (100) antes de Bruno (40).
        $ana = $result['creditors'][0];
        $this->assertSame(['id' => self::ANA, 'name' => 'Ana', 'avatarUrl' => 'https://x/ana.jpg'], $ana['creditor']);
        $this->assertSame(100.0, $ana['advanced_total']);
        $this->assertSame([
            ['id' => self::BRUNO, 'name' => 'Bruno', 'avatarUrl' => 'https://x/bruno.jpg', 'amount' => 50.0],
        ], $ana['debtors']);

        $bruno = $result['creditors'][1];
        $this->assertSame(self::BRUNO, $bruno['creditor']['id']);
        $this->assertSame(40.0, $bruno['advanced_total']);
        $this->assertSame([
            ['id' => self::ANA, 'name' => 'Ana', 'avatarUrl' => 'https://x/ana.jpg', 'amount' => 20.0],
        ], $bruno['debtors']);
    }

    public function test_an_entry_born_paid_is_neither_advanced_nor_owed(): void
    {
        $result = ClosingBreakdown::build([
            $this->expense(self::ANA, [self::ANA, self::BRUNO], 100),
            $this->expense(self::ANA, [self::ANA, self::BRUNO, self::CARLA], 300, ['bornPaid' => true]),
        ], $this->members());

        $this->assertSame(100.0, $result['creditors'][0]['advanced_total']);
        $this->assertSame(50.0, $this->debtorsOf($result, self::ANA)[0]['amount']);
        $this->assertCount(1, $this->debtorsOf($result, self::ANA), 'Carla só participava da parcela nascida quitada.');
    }

    public function test_only_born_paid_entries_leave_no_creditor(): void
    {
        $result = ClosingBreakdown::build([
            $this->expense(self::ANA, [self::ANA, self::BRUNO], 100, ['bornPaid' => true]),
        ], $this->members());

        $this->assertSame(['creditors' => []], $result);
    }

    public function test_the_creditor_is_resolved_by_payer_name_when_the_snapshot_has_no_user_payer_id(): void
    {
        // Snapshot antigo: sem `userPayerId` nem `bornPaid`.
        $old = $this->expense(self::BRUNO, [self::ANA, self::BRUNO], 80);
        unset($old['userPayerId'], $old['bornPaid']);

        $result = ClosingBreakdown::build([$old], $this->members());

        $this->assertSame(
            ['id' => self::BRUNO, 'name' => 'Bruno', 'avatarUrl' => 'https://x/bruno.jpg'],
            $result['creditors'][0]['creditor']
        );
        $this->assertSame(40.0, $this->debtorsOf($result, self::BRUNO)[0]['amount']);
    }

    public function test_the_creditor_is_never_listed_as_his_own_debtor(): void
    {
        $result = ClosingBreakdown::build([
            $this->expense(self::ANA, [self::ANA, self::BRUNO, self::CARLA], 90),
        ], $this->members());

        $ids = array_column($this->debtorsOf($result, self::ANA), 'id');

        $this->assertSame([self::BRUNO, self::CARLA], $ids);
        $this->assertSame(30.0, $this->debtorsOf($result, self::ANA)[0]['amount']);
    }

    public function test_a_creditor_who_does_not_take_part_in_the_split_makes_everyone_else_a_debtor(): void
    {
        $result = ClosingBreakdown::build([
            $this->expense(self::ANA, [self::BRUNO, self::CARLA], 100),
        ], $this->members());

        $this->assertSame(100.0, $result['creditors'][0]['advanced_total']);
        $this->assertSame([50.0, 50.0], array_column($this->debtorsOf($result, self::ANA), 'amount'));
    }

    public function test_the_slice_comes_from_the_value_and_ignores_value_per_person(): void
    {
        // valuePerPerson = 999.99 em toda despesa de teste: se fosse usado, o resultado seria absurdo.
        $result = ClosingBreakdown::build([
            $this->expense(self::ANA, [self::ANA, self::BRUNO], 100),
        ], $this->members());

        $this->assertSame(50.0, $this->debtorsOf($result, self::ANA)[0]['amount']);
    }

    public function test_slices_are_summed_without_rounding_and_rounded_once_at_the_end(): void
    {
        // 100 / 3 = 33,333...: arredondar cada fatia daria 33,33 + 33,33 = 66,66.
        // Somando sem arredondar, 66,666... fecha em 66,67.
        $result = ClosingBreakdown::build([
            $this->expense(self::ANA, [self::ANA, self::BRUNO, self::CARLA], 100),
            $this->expense(self::ANA, [self::ANA, self::BRUNO, self::CARLA], 100, ['id' => 2]),
        ], $this->members());

        $this->assertSame([66.67, 66.67], array_column($this->debtorsOf($result, self::ANA), 'amount'));
        $this->assertSame(200.0, $result['creditors'][0]['advanced_total']);
    }

    public function test_advanced_total_is_the_whole_value_of_the_creditors_expenses(): void
    {
        $result = ClosingBreakdown::build([
            $this->expense(self::ANA, [self::ANA, self::BRUNO], 100),
            $this->expense(self::ANA, [self::ANA, self::CARLA], 50, ['id' => 2]),
            $this->expense(self::ANA, [self::ANA, self::BRUNO], 30, ['id' => 3, 'bornPaid' => true]),
        ], $this->members());

        $this->assertSame(150.0, $result['creditors'][0]['advanced_total']);
    }

    public function test_the_same_pair_accumulates_across_expenses(): void
    {
        $result = ClosingBreakdown::build([
            $this->expense(self::ANA, [self::ANA, self::BRUNO], 100),
            $this->expense(self::ANA, [self::ANA, self::BRUNO], 60, ['id' => 2]),
        ], $this->members());

        $this->assertCount(1, $this->debtorsOf($result, self::ANA));
        $this->assertSame(80.0, $this->debtorsOf($result, self::ANA)[0]['amount']);
    }

    public function test_creditors_are_ordered_by_advanced_total_and_debtors_by_amount_descending(): void
    {
        $result = ClosingBreakdown::build([
            $this->expense(self::CARLA, [self::CARLA, self::ANA], 20),
            $this->expense(self::BRUNO, [self::BRUNO, self::ANA], 300, ['id' => 2]),
            $this->expense(self::ANA, [self::ANA, self::BRUNO, self::CARLA], 90, ['id' => 4]),
            $this->expense(self::ANA, [self::ANA, self::CARLA], 100, ['id' => 5]),
        ], $this->members());

        $this->assertSame(
            [self::BRUNO, self::ANA, self::CARLA],
            array_map(fn (array $row) => $row['creditor']['id'], $result['creditors'])
        );

        // Ana: Carla deve 30 + 50 = 80 e Bruno deve 30; a maior dívida vem primeiro.
        $this->assertSame(
            [[self::CARLA, 80.0], [self::BRUNO, 30.0]],
            array_map(fn (array $debtor) => [$debtor['id'], $debtor['amount']], $this->debtorsOf($result, self::ANA))
        );
    }

    public function test_an_old_snapshot_without_participant_details_resolves_ids_and_avatars_by_member_name(): void
    {
        $old = $this->expense(self::ANA, [self::ANA, self::BRUNO, self::CARLA], 90);
        unset($old['participantDetails'], $old['userPayerId']);

        $result = ClosingBreakdown::build([$old], $this->members());

        $this->assertSame(
            [
                ['id' => self::BRUNO, 'name' => 'Bruno', 'avatarUrl' => 'https://x/bruno.jpg', 'amount' => 30.0],
                ['id' => self::CARLA, 'name' => 'Carla', 'avatarUrl' => null, 'amount' => 30.0],
            ],
            $this->debtorsOf($result, self::ANA)
        );
    }

    public function test_a_participant_who_matches_no_member_becomes_a_debtor_without_id(): void
    {
        // Ex-membro: só o nome sobrou no snapshot.
        $old = $this->expense(self::ANA, [self::ANA, self::BRUNO], 90);
        unset($old['participantDetails']);
        $old['participants'] = ['Ana', 'Bruno', 'Fantasma'];

        $result = ClosingBreakdown::build([$old], $this->members());

        $this->assertSame(
            ['id' => null, 'name' => 'Fantasma', 'avatarUrl' => null, 'amount' => 30.0],
            $this->debtorsOf($result, self::ANA)[1]
        );
    }

    public function test_a_creditor_without_debtors_is_still_listed(): void
    {
        // Pagou uma despesa só dele: adiantou, mas ninguém lhe deve.
        $result = ClosingBreakdown::build([
            $this->expense(self::ANA, [self::ANA], 70),
        ], $this->members());

        $this->assertSame(70.0, $result['creditors'][0]['advanced_total']);
        $this->assertSame([], $result['creditors'][0]['debtors']);
    }

    public function test_an_empty_input_returns_no_creditors(): void
    {
        $this->assertSame(['creditors' => []], ClosingBreakdown::build([], $this->members()));
    }
}
