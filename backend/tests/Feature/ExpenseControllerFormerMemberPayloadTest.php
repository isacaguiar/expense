<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsPurchaseScenarios;
use Tests\TestCase;

/**
 * Contato de quem saiu do grupo nas respostas de despesa (item 086, R12 e CA-18
 * de docs/feature/concluidas/202610/20261030-saida-e-remocao-de-membro/specify.md).
 *
 * Sair do grupo só tira a linha de `ex_groups_members`; a pessoa continua em
 * `ex_expenses_payers` e como credora (`user_payer_id`). `GET /expenses/{id}` e a
 * resposta do `PUT` serializam `payers` com o `User` inteiro, e `gross-debts`
 * devolve e-mail e Pix do credor: sem tratamento, os membros atuais seguiriam
 * recebendo o contato de quem não é mais do grupo (achado A1 da TASK-556).
 *
 * Quem saiu aparece só com `id` e `name`; os membros mantêm o JSON de sempre,
 * porque precisam ver o contato uns dos outros.
 */
class ExpenseControllerFormerMemberPayloadTest extends TestCase
{
    use BuildsPurchaseScenarios;
    use DatabaseTransactions;

    private const FORMER_EMAIL = 'saiu@example.test';

    private const FORMER_PIX = 'pix-de-quem-saiu@example.test';

    private const FORMER_WHATSAPP = '5511911112222';

    protected function setUp(): void
    {
        parent::setUp();

        // closing_day nulo → competência = mês calendário; despesa de 2026-10-05 está no ciclo aberto.
        Carbon::setTestNow('2026-10-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function showAs(User $viewer, Expense $expense)
    {
        return $this->withToken(auth('api')->login($viewer))->getJson("/api/expenses/{$expense->id}");
    }

    private function updateAs(User $editor, Expense $expense, array $payload)
    {
        return $this->withToken(auth('api')->login($editor))->putJson("/api/expenses/{$expense->id}", $payload);
    }

    private function grossDebtsAs(User $viewer, Group $group)
    {
        return $this->withToken(auth('api')->login($viewer))->getJson("/api/groups/{$group->id}/expenses/gross-debts");
    }

    /** Dá contato completo e foto própria a `$user`, para o teste enxergar o vazamento. */
    private function withFullContact(User $user, string $email, string $pix, string $whatsapp): User
    {
        $user->forceFill([
            'email' => $email,
            'pix' => $pix,
            'whatsapp' => $whatsapp,
            'notify_whatsapp' => true,
            'avatar_url' => 'https://example.test/'.$user->id.'.png',
            'photo_path' => 'photos/'.$user->id.'.jpg',
        ])->save();

        return $user;
    }

    /** Um usuário que já saiu do grupo (e continua nas despesas), com contato completo. */
    private function formerMemberOf(Group $group): User
    {
        $former = $this->withFullContact(User::factory()->create(), self::FORMER_EMAIL, self::FORMER_PIX, self::FORMER_WHATSAPP);
        $group->members()->attach($former->id);

        return $former;
    }

    /**
     * Despesa À Vista de 90 do `$editor` (criador e credor), dividida com `$former` e `$stays`;
     * depois `$former` sai do grupo.
     *
     * @return array{group: Group, editor: User, former: User, stays: User, expense: Expense}
     */
    private function expenseWithAFormerMember(): array
    {
        ['group' => $group, 'users' => [$editor, $stays]] = $this->makeGroup(2);
        $this->withFullContact($stays, 'fica@example.test', 'pix-de-quem-fica@example.test', '5511933334444');
        $former = $this->formerMemberOf($group);

        $expense = $this->makePurchase($group, $editor, [$editor, $former, $stays], 'IN_CASH', 90.00, '2026-10-05');
        $group->members()->detach($former->id);

        return compact('group', 'editor', 'former', 'stays', 'expense');
    }

    /** `payers` da resposta, indexados pelo id do participante. */
    private function payersById($response): array
    {
        return collect($response->json('payers'))->keyBy('id')->all();
    }

    /** Como o `User` de um membro é serializado dentro de `payers` desde sempre (modelo inteiro + pivô). */
    private function expectedMemberPayload(Expense $expense, User $member): array
    {
        return $expense->fresh()->load('payers')->payers->keyBy('id')->get($member->id)->toArray();
    }

    private function assertNoContactOfTheFormerMember($response): void
    {
        $body = $response->getContent();

        foreach ([self::FORMER_EMAIL, self::FORMER_PIX, self::FORMER_WHATSAPP] as $contact) {
            $this->assertStringNotContainsString($contact, $body, 'O contato de quem saiu não pode sair na resposta.');
        }
    }

    public function test_show_returns_only_id_and_name_for_a_participant_who_left(): void
    {
        ['editor' => $editor, 'former' => $former, 'expense' => $expense] = $this->expenseWithAFormerMember();

        $response = $this->showAs($editor, $expense)->assertStatus(200);

        $payer = $this->payersById($response)[$former->id];
        $this->assertSame(['id', 'name'], collect($payer)->keys()->sort()->values()->all());
        $this->assertSame($former->id, $payer['id']);
        $this->assertSame($former->name, $payer['name']);
        $this->assertNoContactOfTheFormerMember($response);
    }

    public function test_show_keeps_the_whole_payload_of_the_participants_who_are_members(): void
    {
        ['editor' => $editor, 'stays' => $stays, 'expense' => $expense] = $this->expenseWithAFormerMember();

        $payers = $this->payersById($this->showAs($editor, $expense)->assertStatus(200));

        foreach ([$editor, $stays] as $member) {
            $this->assertEquals($this->expectedMemberPayload($expense, $member), $payers[$member->id]);
        }
        $this->assertSame('fica@example.test', $payers[$stays->id]['email']);
        $this->assertSame('pix-de-quem-fica@example.test', $payers[$stays->id]['pix']);
        $this->assertSame('5511933334444', $payers[$stays->id]['whatsapp']);
    }

    public function test_show_and_update_hide_a_participant_who_left_even_if_still_a_member_of_another_group(): void
    {
        ['editor' => $editor, 'former' => $former, 'expense' => $expense] = $this->expenseWithAFormerMember();
        // Sair deste grupo não tira a pessoa do resto: o que vale é a lista de membros DESTE grupo.
        Group::create(['name' => 'Outro grupo'])->members()->attach($former->id);

        $show = $this->showAs($editor, $expense)->assertStatus(200);
        $update = $this->updateAs($editor, $expense, ['description' => 'Editada'])->assertStatus(200);

        foreach ([$show, $update] as $response) {
            $this->assertSame(['id', 'name'], collect($this->payersById($response)[$former->id])->keys()->sort()->values()->all());
            $this->assertNoContactOfTheFormerMember($response);
        }
    }

    public function test_show_of_an_expense_without_former_members_is_unchanged(): void
    {
        ['group' => $group, 'users' => [$editor, $other]] = $this->makeGroup(2);
        $expense = $this->makePurchase($group, $editor, [$editor, $other], 'IN_CASH', 50.00, '2026-10-05');

        $payers = $this->payersById($this->showAs($editor, $expense)->assertStatus(200));

        $this->assertCount(2, $payers);
        foreach ([$editor, $other] as $member) {
            $this->assertEquals($this->expectedMemberPayload($expense, $member), $payers[$member->id]);
        }
    }

    public function test_update_response_returns_only_id_and_name_for_a_participant_who_left(): void
    {
        ['editor' => $editor, 'former' => $former, 'stays' => $stays, 'expense' => $expense] = $this->expenseWithAFormerMember();

        $response = $this->updateAs($editor, $expense, ['description' => 'Editada'])
            ->assertStatus(200)
            ->assertJsonPath('description', 'Editada');

        $payers = $this->payersById($response);
        $this->assertSame(['id', 'name'], collect($payers[$former->id])->keys()->sort()->values()->all());
        $this->assertSame($former->name, $payers[$former->id]['name']);
        $this->assertNoContactOfTheFormerMember($response);

        // Quem é membro segue com o JSON de sempre, e quem saiu continua na despesa.
        $this->assertEquals($this->expectedMemberPayload($expense, $stays), $payers[$stays->id]);
        $this->assertContains($former->id, $expense->payers()->pluck('ex_users.id')->all());
    }

    public function test_update_that_resends_the_participant_who_left_still_hides_the_contact(): void
    {
        ['editor' => $editor, 'former' => $former, 'stays' => $stays, 'expense' => $expense] = $this->expenseWithAFormerMember();

        $response = $this->updateAs($editor, $expense, ['payers' => [$editor->id, $former->id, $stays->id]])->assertStatus(200);

        $this->assertSame(['id', 'name'], collect($this->payersById($response)[$former->id])->keys()->sort()->values()->all());
        $this->assertNoContactOfTheFormerMember($response);
    }

    public function test_gross_debts_nulls_email_and_pix_of_a_creditor_who_left_and_keeps_those_of_a_member(): void
    {
        ['group' => $group, 'users' => [$editor, $stays]] = $this->makeGroup(2);
        $this->withFullContact($stays, 'fica@example.test', 'pix-de-quem-fica@example.test', '5511933334444');
        $former = $this->formerMemberOf($group);

        // O ex-membro é o credor de uma despesa em aberto; `$stays` é credor de outra, em que `$former` também deve.
        $this->makePurchase($group, $former, [$former, $editor, $stays], 'IN_CASH', 90.00, '2026-10-05');
        $this->makePurchase($group, $stays, [$stays, $editor, $former], 'IN_CASH', 60.00, '2026-10-06');
        $group->members()->detach($former->id);

        $response = $this->grossDebtsAs($editor, $group)->assertStatus(200);

        $creditors = collect($response->json('creditors'))->keyBy('creditor.id');
        $this->assertCount(2, $creditors);

        $gone = $creditors[$former->id]['creditor'];
        $this->assertSame(['email', 'id', 'name', 'pix'], collect($gone)->keys()->sort()->values()->all());
        $this->assertSame($former->id, $gone['id']);
        $this->assertSame($former->name, $gone['name']);
        $this->assertNull($gone['email']);
        $this->assertNull($gone['pix']);

        $kept = $creditors[$stays->id]['creditor'];
        $this->assertSame('fica@example.test', $kept['email']);
        $this->assertSame('pix-de-quem-fica@example.test', $kept['pix']);

        // Devedor que saiu já aparecia só com id, nome e valor: segue assim.
        $formerAsDebtor = collect($creditors[$stays->id]['debtors'])->firstWhere('id', $former->id);
        $this->assertSame(['amount', 'id', 'name'], collect($formerAsDebtor)->keys()->sort()->values()->all());
        $this->assertNoContactOfTheFormerMember($response);
    }

    public function test_gross_debts_nulls_the_contact_of_a_creditor_who_left_even_if_still_a_member_of_another_group(): void
    {
        ['group' => $group, 'users' => [$editor]] = $this->makeGroup(1);
        $former = $this->formerMemberOf($group);
        $this->makePurchase($group, $former, [$former, $editor], 'IN_CASH', 40.00, '2026-10-05');
        $group->members()->detach($former->id);
        Group::create(['name' => 'Outro grupo'])->members()->attach($former->id);

        $node = $this->grossDebtsAs($editor, $group)->assertStatus(200)->json('creditors.0.creditor');

        $this->assertSame($former->id, $node['id']);
        $this->assertNull($node['email']);
        $this->assertNull($node['pix']);
    }

    public function test_gross_debts_keeps_email_and_pix_of_a_creditor_who_is_a_member(): void
    {
        ['group' => $group, 'users' => [$creditor, $debtor]] = $this->makeGroup(2);
        $this->withFullContact($creditor, 'credor@example.test', 'pix-do-credor@example.test', '5511955556666');
        $this->makePurchase($group, $creditor, [$creditor, $debtor], 'IN_CASH', 80.00, '2026-10-05');

        $node = $this->grossDebtsAs($debtor, $group)->assertStatus(200)->json('creditors.0.creditor');

        $this->assertSame(
            ['id' => $creditor->id, 'name' => $creditor->name, 'email' => 'credor@example.test', 'pix' => 'pix-do-credor@example.test'],
            $node
        );
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

    public function test_show_and_update_run_the_same_number_of_queries_with_one_and_with_three_who_left(): void
    {
        // Uma despesa com um ex-membro e outra, no mesmo grupo, com três.
        ['group' => $group, 'users' => [$editor]] = $this->makeGroup(1);
        $oneLeft = $this->formerMemberOf($group);
        $threeLeft = [
            $this->withFullContact(User::factory()->create(), 'a@example.test', 'pix-a@example.test', '5511900000001'),
            $this->withFullContact(User::factory()->create(), 'b@example.test', 'pix-b@example.test', '5511900000002'),
            $this->withFullContact(User::factory()->create(), 'c@example.test', 'pix-c@example.test', '5511900000003'),
        ];
        $group->members()->attach(array_map(fn (User $u) => $u->id, $threeLeft));

        $withOne = $this->makePurchase($group, $editor, [$editor, $oneLeft], 'IN_CASH', 30.00, '2026-10-05');
        $withThree = $this->makePurchase($group, $editor, [$editor, ...$threeLeft], 'IN_CASH', 30.00, '2026-10-06');
        $group->members()->detach(array_map(fn (User $u) => $u->id, [$oneLeft, ...$threeLeft]));

        // Aquece o que é cacheado na primeira requisição, fora da contagem.
        $this->showAs($editor, $withOne)->assertStatus(200);
        $token = auth('api')->login($editor);

        $show = fn (Expense $e) => $this->queriesOf(fn () => $this->withToken($token)->getJson("/api/expenses/{$e->id}")->assertStatus(200));
        $update = fn (Expense $e) => $this->queriesOf(fn () => $this->withToken($token)->putJson("/api/expenses/{$e->id}", ['description' => 'Editada'])->assertStatus(200));

        $this->assertSame($show($withOne), $show($withThree));
        $this->assertSame($update($withOne), $update($withThree));
    }

    public function test_gross_debts_runs_the_same_number_of_queries_with_one_and_with_three_creditors_who_left(): void
    {
        ['group' => $withOneGroup, 'users' => [$viewerOne]] = $this->makeGroup(1);
        $formerOne = $this->formerMemberOf($withOneGroup);
        $this->makePurchase($withOneGroup, $formerOne, [$formerOne, $viewerOne], 'IN_CASH', 30.00, '2026-10-05');
        $withOneGroup->members()->detach($formerOne->id);

        ['group' => $withThreeGroup, 'users' => [$viewerThree]] = $this->makeGroup(1);
        foreach ([5, 6, 7] as $day) {
            $former = $this->withFullContact(User::factory()->create(), "g{$day}@example.test", "pix-g{$day}@example.test", "551190000000{$day}");
            $withThreeGroup->members()->attach($former->id);
            $this->makePurchase($withThreeGroup, $former, [$former, $viewerThree], 'IN_CASH', 30.00, "2026-10-0{$day}");
            $withThreeGroup->members()->detach($former->id);
        }

        $this->grossDebtsAs($viewerOne, $withOneGroup)->assertStatus(200);

        $one = $this->queriesOf(fn () => $this->grossDebtsAs($viewerOne, $withOneGroup)->assertStatus(200)->assertJsonCount(1, 'creditors'));
        $three = $this->queriesOf(fn () => $this->grossDebtsAs($viewerThree, $withThreeGroup)->assertStatus(200)->assertJsonCount(3, 'creditors'));

        $this->assertSame($one, $three);
    }
}
