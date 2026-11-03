<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\SettlementConfirmation;
use App\Models\User;
use App\Services\CycleSummaryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsPurchaseScenarios;
use Tests\TestCase;

/**
 * `balances` e quem já saiu do grupo (item 086, R11 e CA-16 de
 * docs/feature/concluidas/202610/20261030-saida-e-remocao-de-membro/specify.md).
 *
 * Sair do grupo só tira a linha de `ex_groups_members`: a pessoa continua em
 * `ex_expenses_payers` e como credor, então segue sendo ponta de um acerto em
 * `settlements`. As telas de acerto do grupo resolvem o nome de cada ponta por
 * `balances`, que antes só nascia dos membros atuais e deixava o ex-membro como
 * "Desconhecido". Agora toda ponta de acerto tem entrada, e quem não é membro
 * entra só com o nome (sem foto) e a chave `formerMember`.
 */
class CycleSummaryServiceFormerMemberTest extends TestCase
{
    use BuildsPurchaseScenarios;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // closing_day nulo → competência = mês calendário; outubro é o ciclo vigente
        // e setembro (1 ciclo atrás) já fechou por data em 05/10.
        Carbon::setTestNow('2026-10-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{totals: array, expenses: array, balances: array, settlements: array} o resumo do mês que contém `$date` */
    private function summaryOf(Group $group, string $date = '2026-10-15'): array
    {
        ['start' => $start, 'end' => $end] = $this->cyclesFrom($group, $date, 1)[0];

        return app(CycleSummaryService::class)->compute($group->fresh(), $start, $end);
    }

    /** A entrada de `$user` em `$balances`, ou null. */
    private function entryOf(array $balances, User $user): ?array
    {
        foreach ($balances as $balance) {
            if ($balance['user_id'] === $user->id) {
                return $balance;
            }
        }

        return null;
    }

    /** @return list<int> */
    private function idsOf(array $balances): array
    {
        return array_column($balances, 'user_id');
    }

    /** Quantas consultas SQL `$run` dispara. */
    private function queriesOf(callable $run): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * Despesa À Vista de 90 paga por `$creditor` e dividida com `$debtor` e `$stays`;
     * depois `$debtor` sai do grupo.
     *
     * @return array{group: Group, creditor: User, debtor: User, stays: User}
     */
    private function groupWhereTheDebtorLeft(): array
    {
        ['group' => $group, 'users' => [$creditor, $debtor, $stays]] = $this->makeGroup(3);
        $this->makePurchase($group, $creditor, [$creditor, $debtor, $stays], 'IN_CASH', 90.00, '2026-10-05');

        $group->members()->detach($debtor->id);

        return compact('group', 'creditor', 'debtor', 'stays');
    }

    public function test_a_debtor_who_left_comes_in_balances_with_the_name_and_no_avatar(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor, 'stays' => $stays] = $this->groupWhereTheDebtorLeft();
        // Foto própria e do Google: nenhuma das duas pode sair depois da saída.
        $debtor->forceFill(['photo_path' => 'photos/debtor.jpg', 'avatar_url' => 'https://example.test/debtor.png'])->save();

        $balances = $this->summaryOf($group)['balances'];

        $this->assertEquals(
            ['user_id' => $debtor->id, 'name' => $debtor->name, 'avatarUrl' => null, 'balance' => -30.0, 'formerMember' => true],
            $this->entryOf($balances, $debtor)
        );
        $this->assertEquals(60.0, $this->entryOf($balances, $creditor)['balance']);
        $this->assertEquals(-30.0, $this->entryOf($balances, $stays)['balance']);
    }

    public function test_a_creditor_who_left_comes_in_balances_too(): void
    {
        ['group' => $group, 'users' => [$creditor, $first, $second]] = $this->makeGroup(3);
        $this->makePurchase($group, $creditor, [$creditor, $first, $second], 'IN_CASH', 90.00, '2026-10-05');
        $group->members()->detach($creditor->id);

        $balances = $this->summaryOf($group)['balances'];

        $this->assertEquals(
            ['user_id' => $creditor->id, 'name' => $creditor->name, 'avatarUrl' => null, 'balance' => 60.0, 'formerMember' => true],
            $this->entryOf($balances, $creditor)
        );
        $this->assertEquals(-30.0, $this->entryOf($balances, $first)['balance']);
        $this->assertEquals(-30.0, $this->entryOf($balances, $second)['balance']);
    }

    public function test_a_former_member_who_is_only_the_creditor_comes_in_balances(): void
    {
        ['group' => $group, 'users' => [$former, $first, $second]] = $this->makeGroup(3);
        // O credor não precisa estar entre os participantes: só `user_payer_id` o prende à despesa.
        $this->makePurchase($group, $former, [$first, $second], 'IN_CASH', 60.00, '2026-10-05');
        $group->members()->detach($former->id);

        $balances = $this->summaryOf($group)['balances'];

        $this->assertEquals(
            ['user_id' => $former->id, 'name' => $former->name, 'avatarUrl' => null, 'balance' => 60.0, 'formerMember' => true],
            $this->entryOf($balances, $former)
        );
    }

    public function test_every_party_of_a_settlement_has_an_entry_in_balances(): void
    {
        ['group' => $group, 'users' => [$a, $b, $c, $d]] = $this->makeGroup(4);
        // B deve a A e a C; D deve a A e a C. B e C saem do grupo: um devedor e um credor.
        $this->makePurchase($group, $a, [$a, $b, $d], 'IN_CASH', 90.00, '2026-10-05');
        $this->makePurchase($group, $c, [$c, $d, $b], 'IN_CASH', 60.00, '2026-10-06');
        $group->members()->detach([$b->id, $c->id]);

        $summary = $this->summaryOf($group);

        $this->assertNotEmpty($summary['settlements']);
        $ids = $this->idsOf($summary['balances']);
        foreach ($summary['settlements'] as $settlement) {
            $this->assertContains($settlement['from_user_id'], $ids, 'devedor do acerto sem entrada em balances');
            $this->assertContains($settlement['to_user_id'], $ids, 'credor do acerto sem entrada em balances');
        }
    }

    public function test_members_keep_their_entries_and_the_former_member_comes_after_them(): void
    {
        ['group' => $group, 'users' => [$creditor, $debtor, $stays]] = $this->makeGroup(3);
        $this->makePurchase($group, $creditor, [$creditor, $debtor, $stays], 'IN_CASH', 90.00, '2026-10-05');
        $before = $this->summaryOf($group)['balances'];

        $group->members()->detach($debtor->id);
        $after = $this->summaryOf($group)['balances'];

        // Os membros: mesmas chaves, mesmos números, mesma ordem, e sem a chave nova.
        $this->assertSame([$creditor->id, $stays->id, $debtor->id], $this->idsOf($after));
        $this->assertSame($this->entryOf($before, $creditor), $this->entryOf($after, $creditor));
        $this->assertSame($this->entryOf($before, $stays), $this->entryOf($after, $stays));
        $this->assertArrayNotHasKey('formerMember', $this->entryOf($after, $creditor));
        $this->assertArrayNotHasKey('formerMember', $this->entryOf($after, $stays));
        // O ex-membro conserva o saldo que tinha como membro.
        $this->assertEquals($this->entryOf($before, $debtor)['balance'], $this->entryOf($after, $debtor)['balance']);
    }

    public function test_without_a_former_member_balances_are_exactly_what_they_were(): void
    {
        ['group' => $group, 'users' => $users] = $this->makeGroup(3);
        $this->makePurchase($group, $users[0], $users, 'IN_CASH', 90.00, '2026-10-05');

        $balances = $this->summaryOf($group)['balances'];

        $this->assertCount(3, $balances);
        foreach ($balances as $balance) {
            $this->assertSame(['user_id', 'name', 'avatarUrl', 'balance'], array_keys($balance));
        }
    }

    public function test_a_former_member_with_no_debt_in_the_cycle_does_not_appear(): void
    {
        ['group' => $group, 'users' => [$member, $other, $lastSeptember, $paidAlone]] = $this->makeGroup(4);
        // Só aparece em setembro: no ciclo de outubro não é ponta de acerto nenhum.
        $this->makePurchase($group, $member, [$member, $lastSeptember], 'IN_CASH', 50.00, '2026-09-10');
        // Em outubro paga uma despesa só dele: ninguém lhe deve, ele não deve a ninguém.
        $this->makePurchase($group, $paidAlone, [$paidAlone], 'IN_CASH', 40.00, '2026-10-07');
        $this->makePurchase($group, $member, [$member, $other], 'IN_CASH', 20.00, '2026-10-08');
        $group->members()->detach([$lastSeptember->id, $paidAlone->id]);

        $ids = $this->idsOf($this->summaryOf($group)['balances']);

        $this->assertSame([$member->id, $other->id], $ids);
    }

    public function test_a_former_member_whose_only_entry_in_the_cycle_is_born_paid_does_not_appear(): void
    {
        ['group' => $group, 'users' => [$creditor, $debtor]] = $this->makeGroup(2);
        // Parcelada em 2 vezes desde setembro: a parcela de setembro nasce quitada (histórico, não dívida).
        $this->makePurchase($group, $creditor, [$creditor, $debtor], 'IN_INSTALLMENTS', 100.00, '2026-09-10', 2);
        $group->members()->detach($debtor->id);

        $september = $this->summaryOf($group, '2026-09-15');

        $this->assertSame([], $september['settlements']);
        $this->assertSame([$creditor->id], $this->idsOf($september['balances']));
    }

    public function test_a_former_member_costs_no_extra_query(): void
    {
        ['group' => $group, 'users' => [$creditor, $debtor, $stays]] = $this->makeGroup(3);
        $this->makePurchase($group, $creditor, [$creditor, $debtor, $stays], 'IN_CASH', 90.00, '2026-10-05');
        $whileMember = $this->queriesOf(fn () => $this->summaryOf($group));

        $group->members()->detach($debtor->id);
        $afterLeaving = $this->queriesOf(fn () => $this->summaryOf($group));

        // O nome sai das relações `payer` e `payers`, que `entries()` já carrega.
        $this->assertSame($whileMember, $afterLeaving, "no grupo: {$whileMember} consultas; depois de sair: {$afterLeaving}");
    }

    public function test_the_summary_endpoint_returns_the_former_member_in_balances(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->groupWhereTheDebtorLeft();

        $response = $this->withToken(auth('api')->login($creditor))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=0")
            ->assertOk();

        $response->assertJsonCount(3, 'balances')
            ->assertJsonPath('balances.2.user_id', $debtor->id)
            ->assertJsonPath('balances.2.name', $debtor->name)
            ->assertJsonPath('balances.2.avatarUrl', null)
            ->assertJsonPath('balances.2.formerMember', true)
            ->assertJsonMissingPath('balances.0.formerMember')
            ->assertJsonMissingPath('balances.1.formerMember');
        $this->assertContains($debtor->id, array_column($response->json('settlements'), 'from_user_id'));
    }

    public function test_a_sealed_cycle_keeps_the_former_member_in_the_snapshot(): void
    {
        ['group' => $group, 'users' => [$creditor, $debtor]] = $this->makeGroup(2);
        $expense = $this->makePurchase($group, $creditor, [$creditor, $debtor], 'IN_CASH', 100.00, '2026-09-10');
        $this->markPaid($expense);
        SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-09-01',
            'cycle_end' => '2026-09-30',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'amount' => 50,
            'proof_path' => 'comprovantes/'.$group->id.'/x.jpg',
            'confirmed_at' => now(),
        ]);
        $group->members()->detach($debtor->id);

        // Setembro, quitado e ainda não selado: ler o resumo sela.
        $this->withToken(auth('api')->login($creditor))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1")
            ->assertOk()
            ->assertJsonPath('cycle.settled', true);

        $snapshot = GroupCycleSnapshot::where('group_id', $group->id)->latest('id')->firstOrFail();
        $entry = $this->entryOf($snapshot->balances, $debtor);
        $this->assertNotNull($entry, 'o ex-devedor não foi para o snapshot');
        $this->assertTrue($entry['formerMember']);
        $this->assertNull($entry['avatarUrl']);
    }

    // ---- Item 086, R13 (CA-21, TASK-565): a foto de quem saiu não sai ----

    /** Foto própria (URL assinada) e do Google: nenhuma das duas pode sair depois da saída. */
    private function withPhoto(User $user): User
    {
        $user->forceFill([
            'photo_path' => "photos/{$user->id}.jpg",
            'avatar_url' => "https://example.test/{$user->id}.png",
        ])->save();

        return $user;
    }

    /** A entrada de `$user` em `participantDetails` da despesa, ou null. */
    private function participantOf(array $expense, User $user): ?array
    {
        foreach ($expense['participantDetails'] as $detail) {
            if ($detail['id'] === $user->id) {
                return $detail;
            }
        }

        return null;
    }

    public function test_a_participant_who_left_keeps_the_id_and_name_but_loses_the_avatar_in_the_expenses(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->groupWhereTheDebtorLeft();
        $this->withPhoto($debtor);

        $expense = $this->summaryOf($group)['expenses'][0];

        $this->assertSame(
            ['id' => $debtor->id, 'name' => $debtor->name, 'avatarUrl' => null],
            $this->participantOf($expense, $debtor)
        );
        $this->assertSame($creditor->id, $expense['userPayerId']);
    }

    public function test_a_creditor_who_left_keeps_the_id_and_name_but_loses_the_avatar_in_the_expenses(): void
    {
        ['group' => $group, 'users' => [$creditor, $first, $second]] = $this->makeGroup(3);
        $this->withPhoto($creditor);
        $this->makePurchase($group, $creditor, [$creditor, $first, $second], 'IN_CASH', 90.00, '2026-10-05');
        $group->members()->detach($creditor->id);

        $expense = $this->summaryOf($group)['expenses'][0];

        $this->assertNull($expense['payerAvatarUrl']);
        $this->assertSame($creditor->id, $expense['userPayerId']);
        $this->assertSame($creditor->name, $expense['payerName']);
        $this->assertSame(
            ['id' => $creditor->id, 'name' => $creditor->name, 'avatarUrl' => null],
            $this->participantOf($expense, $creditor)
        );
    }

    public function test_a_participant_who_left_this_group_loses_the_avatar_even_if_still_in_another_group(): void
    {
        ['group' => $group, 'debtor' => $debtor] = $this->groupWhereTheDebtorLeft();
        $this->withPhoto($debtor);
        $other = Group::create(['name' => 'Outro grupo']);
        $other->members()->attach($debtor->id);

        $expense = $this->summaryOf($group)['expenses'][0];

        $this->assertNull($this->participantOf($expense, $debtor)['avatarUrl']);
    }

    public function test_members_keep_their_avatars_in_the_expenses(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'stays' => $stays] = $this->groupWhereTheDebtorLeft();
        $this->withPhoto($creditor);
        $stays->forceFill(['avatar_url' => 'https://example.test/stays.png'])->save();

        $expense = $this->summaryOf($group)['expenses'][0];
        $creditorAvatar = User::find($creditor->id)->avatar_url;

        $this->assertNotNull($creditorAvatar);
        $this->assertSame($creditorAvatar, $expense['payerAvatarUrl']);
        $this->assertSame($creditorAvatar, $this->participantOf($expense, $creditor)['avatarUrl']);
        $this->assertSame('https://example.test/stays.png', $this->participantOf($expense, $stays)['avatarUrl']);
    }

    public function test_hiding_the_avatars_costs_no_extra_query(): void
    {
        // Uma despesa com um ex-membro, contra três despesas com quatro ex-membros entre seis participantes.
        ['group' => $small, 'users' => $smallUsers] = $this->makeGroup(3);
        $this->makePurchase($small, $smallUsers[0], $smallUsers, 'IN_CASH', 90.00, '2026-10-05');
        $small->members()->detach($smallUsers[1]->id);

        ['group' => $large, 'users' => $largeUsers] = $this->makeGroup(6);
        foreach (['2026-10-03', '2026-10-05', '2026-10-07'] as $i => $date) {
            $this->makePurchase($large, $largeUsers[$i], $largeUsers, 'IN_CASH', 120.00, $date);
        }
        $large->members()->detach(array_map(fn (User $u) => $u->id, array_slice($largeUsers, 2, 4)));

        $this->summaryOf($small); // aquece o que é cacheado na primeira chamada
        $this->assertSame(
            $this->queriesOf(fn () => $this->summaryOf($small)),
            $this->queriesOf(fn () => $this->summaryOf($large))
        );
    }

    public function test_a_sealed_cycle_carries_no_avatar_of_who_had_left_but_keeps_the_members(): void
    {
        ['group' => $group, 'users' => [$creditor, $debtor]] = $this->makeGroup(2);
        $creditor->forceFill(['avatar_url' => 'https://example.test/creditor.png'])->save();
        $this->withPhoto($debtor);
        $expense = $this->makePurchase($group, $creditor, [$creditor, $debtor], 'IN_CASH', 100.00, '2026-09-10');
        $this->markPaid($expense);
        SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-09-01',
            'cycle_end' => '2026-09-30',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'amount' => 50,
            'proof_path' => 'comprovantes/'.$group->id.'/x.jpg',
            'confirmed_at' => now(),
        ]);
        $group->members()->detach($debtor->id);

        $this->withToken(auth('api')->login($creditor))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1")
            ->assertOk()
            ->assertJsonPath('cycle.settled', true);

        $stored = GroupCycleSnapshot::where('group_id', $group->id)->latest('id')->firstOrFail()->expenses[0];
        $this->assertSame('https://example.test/creditor.png', $stored['payerAvatarUrl']);
        $this->assertSame('https://example.test/creditor.png', $this->participantOf($stored, $creditor)['avatarUrl']);
        $this->assertSame(
            ['id' => $debtor->id, 'name' => $debtor->name, 'avatarUrl' => null],
            $this->participantOf($stored, $debtor)
        );
    }

    public function test_the_summary_and_closing_report_endpoints_hide_the_avatar_of_who_left_and_keep_the_members(): void
    {
        ['group' => $group, 'users' => [$creditor, $debtor, $stays]] = $this->makeGroup(3);
        $creditor->forceFill(['avatar_url' => 'https://example.test/creditor.png'])->save();
        $this->withPhoto($debtor);
        $this->makePurchase($group, $creditor, [$creditor, $debtor, $stays], 'IN_CASH', 90.00, '2026-09-10');
        $group->members()->detach($debtor->id);
        $token = auth('api')->login($stays);

        $summary = $this->withToken($token)
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=1")
            ->assertOk();
        $expense = $summary->json('expenses.0');
        $this->assertSame('https://example.test/creditor.png', $expense['payerAvatarUrl']);
        $this->assertNull($this->participantOf($expense, $debtor)['avatarUrl']);
        $this->assertSame($debtor->id, $this->participantOf($expense, $debtor)['id']);

        $report = $this->withToken($token)
            ->getJson("/api/groups/{$group->id}/expenses/closing-report?cycle_start=2026-09-01")
            ->assertOk();
        $this->assertSame('https://example.test/creditor.png', $report->json('creditors.0.creditor.avatarUrl'));
        $debtorRow = collect($report->json('creditors.0.debtors'))->firstWhere('id', $debtor->id);
        $this->assertNotNull($debtorRow, 'o ex-devedor perdeu o id no relatório');
        $this->assertNull($debtorRow['avatarUrl']);
        $this->assertSame($debtor->name, $debtorRow['name']);
    }
}
