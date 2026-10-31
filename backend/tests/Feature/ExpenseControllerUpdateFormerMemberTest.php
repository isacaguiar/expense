<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\BuildsPurchaseScenarios;
use Tests\TestCase;

/**
 * ExpenseController::update com quem já saiu do grupo (item 086, R6 e CA-5 de
 * docs/feature/20261030-saida-e-remocao-de-membro/specify.md).
 *
 * Sair do grupo só tira a linha de `ex_groups_members`; a pessoa continua em
 * `ex_expenses_payers` e pode continuar como credor. Editar a despesa
 * reenviando esses ids não pode virar 422: `update()` aceita membros do grupo
 * mais quem JÁ está naquela despesa. Quem nunca esteve nela e não é membro
 * continua recusado, e o `store()` não muda.
 *
 * O credor é a exceção (R14, TASK-563): `user_payer_id` só aceita um membro ou o
 * credor atual. Participante que saiu segue aceito em `payers`, mas o dono não
 * pode torná-lo credor de parcela em aberto, o que a D3-B proíbe.
 */
class ExpenseControllerUpdateFormerMemberTest extends TestCase
{
    use BuildsPurchaseScenarios;
    use DatabaseTransactions;

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

    private function update(User $editor, Expense $expense, array $payload)
    {
        return $this->withToken(auth('api')->login($editor))
            ->putJson("/api/expenses/{$expense->id}", $payload);
    }

    /**
     * Despesa À Vista do `$editor` (criador e credor), dividida com `$former` e `$stays`;
     * depois `$former` sai do grupo.
     *
     * @return array{group: Group, editor: User, former: User, stays: User, expense: Expense}
     */
    private function expenseWithAFormerMember(): array
    {
        ['group' => $group, 'users' => [$editor, $former, $stays]] = $this->makeGroup(3);
        $expense = $this->makePurchase($group, $editor, [$editor, $former, $stays], 'IN_CASH', 90.00, '2026-10-05');

        $group->members()->detach($former->id);

        return compact('group', 'editor', 'former', 'stays', 'expense');
    }

    private function payerIds(Expense $expense): array
    {
        return $expense->payers()->pluck('ex_users.id')->sort()->values()->all();
    }

    public function test_resending_a_former_member_among_the_participants_keeps_them(): void
    {
        ['editor' => $editor, 'former' => $former, 'stays' => $stays, 'expense' => $expense] = $this->expenseWithAFormerMember();

        $this->update($editor, $expense, ['description' => 'Editada', 'payers' => [$editor->id, $former->id, $stays->id]])
            ->assertStatus(200)
            ->assertJsonPath('description', 'Editada');

        $this->assertSame(collect([$editor->id, $former->id, $stays->id])->sort()->values()->all(), $this->payerIds($expense));
    }

    public function test_resending_a_former_member_as_the_creditor_keeps_them(): void
    {
        ['group' => $group, 'users' => [$editor, $former, $stays]] = $this->makeGroup(3);
        // O ex-membro é o credor; quem edita é o criador da despesa.
        $expense = $this->makePurchase($group, $former, [$former, $editor, $stays], 'IN_CASH', 90.00, '2026-10-05');
        $expense->update(['user_creator_id' => $editor->id]);
        $group->members()->detach($former->id);

        $this->update($editor, $expense, ['user_payer_id' => $former->id, 'payers' => [$former->id, $editor->id]])
            ->assertStatus(200)
            ->assertJsonPath('user_payer_id', $former->id);

        $this->assertSame($former->id, $expense->fresh()->user_payer_id);
        $this->assertSame(collect([$former->id, $editor->id])->sort()->values()->all(), $this->payerIds($expense));
    }

    public function test_a_former_member_who_is_only_the_creditor_is_kept(): void
    {
        ['group' => $group, 'users' => [$editor, $former, $stays]] = $this->makeGroup(3);
        // O credor não precisa estar entre os participantes: só `user_payer_id` o prende à despesa.
        $expense = $this->makePurchase($group, $former, [$editor, $stays], 'IN_CASH', 60.00, '2026-10-05');
        $expense->update(['user_creator_id' => $editor->id]);
        $group->members()->detach($former->id);
        $this->assertNotContains($former->id, $this->payerIds($expense));

        $this->update($editor, $expense, ['description' => 'Editada', 'user_payer_id' => $former->id, 'payers' => [$editor->id, $stays->id]])
            ->assertStatus(200)
            ->assertJsonPath('user_payer_id', $former->id);

        $this->assertSame($former->id, $expense->fresh()->user_payer_id);
    }

    public function test_someone_who_was_never_on_the_expense_and_is_not_a_member_is_still_refused(): void
    {
        ['editor' => $editor, 'stays' => $stays, 'expense' => $expense] = $this->expenseWithAFormerMember();
        // Sem relação com a despesa nem com o grupo, mas membro de OUTRO grupo.
        $outsider = User::factory()->create();
        Group::create(['name' => 'Outro grupo'])->members()->attach($outsider->id);
        $before = $this->payerIds($expense);

        $this->update($editor, $expense, ['payers' => [$editor->id, $outsider->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payers.1']);

        $this->update($editor, $expense, ['user_payer_id' => $outsider->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_payer_id']);

        $this->assertSame($before, $this->payerIds($expense));
        $this->assertSame($editor->id, $expense->fresh()->user_payer_id);
    }

    public function test_a_former_member_of_another_expense_is_not_accepted_here(): void
    {
        ['group' => $group, 'editor' => $editor, 'former' => $former, 'stays' => $stays] = $this->expenseWithAFormerMember();
        // O ex-membro nunca participou desta outra despesa do mesmo grupo.
        $other = $this->makePurchase($group, $editor, [$editor, $stays], 'IN_CASH', 40.00, '2026-10-06');

        $this->update($editor, $other, ['payers' => [$editor->id, $former->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payers.1']);

        $this->assertSame(collect([$editor->id, $stays->id])->sort()->values()->all(), $this->payerIds($other));
    }

    public function test_a_former_member_dropped_from_the_expense_cannot_be_added_back(): void
    {
        ['editor' => $editor, 'former' => $former, 'stays' => $stays, 'expense' => $expense] = $this->expenseWithAFormerMember();

        // Tirar o ex-membro da despesa é permitido...
        $this->update($editor, $expense, ['payers' => [$editor->id, $stays->id]])->assertStatus(200);
        $this->assertSame(collect([$editor->id, $stays->id])->sort()->values()->all(), $this->payerIds($expense));

        // ...mas depois disso ele não "já estava na despesa": volta a valer a regra de membro.
        $this->update($editor, $expense, ['payers' => [$editor->id, $stays->id, $former->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payers.2']);
    }

    public function test_a_current_member_can_still_be_added_to_the_expense(): void
    {
        ['group' => $group, 'editor' => $editor, 'former' => $former, 'stays' => $stays, 'expense' => $expense] = $this->expenseWithAFormerMember();
        $newcomer = User::factory()->create();
        $group->members()->attach($newcomer->id);

        $this->update($editor, $expense, ['payers' => [$editor->id, $former->id, $stays->id, $newcomer->id]])
            ->assertStatus(200);

        $this->assertContains($newcomer->id, $this->payerIds($expense));
    }

    /** @return list<int> */
    private function quotaIds(Expense $expense): array
    {
        return $expense->quotas()->pluck('id')->sort()->values()->all();
    }

    public function test_a_participant_who_left_cannot_be_made_the_creditor(): void
    {
        ['editor' => $editor, 'former' => $former, 'expense' => $expense] = $this->expenseWithAFormerMember();
        $payersBefore = $this->payerIds($expense);
        $quotasBefore = $this->quotaIds($expense);

        // Manda junto `description` e os participantes: nada pode ser gravado pela metade.
        $this->update($editor, $expense, ['description' => 'Editada', 'user_payer_id' => $former->id, 'payers' => [$editor->id, $former->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_payer_id'])
            ->assertJsonMissingValidationErrors(['payers.0', 'payers.1']);

        // E sozinho, sem mais nada no corpo.
        $this->update($editor, $expense, ['user_payer_id' => $former->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_payer_id']);

        $fresh = $expense->fresh();
        $this->assertSame($editor->id, $fresh->user_payer_id);
        $this->assertSame('Compra de teste', $fresh->description);
        $this->assertSame($payersBefore, $this->payerIds($expense));
        $this->assertSame($quotasBefore, $this->quotaIds($expense));
    }

    public function test_the_creditor_who_left_can_be_resent_without_touching_the_participants(): void
    {
        ['group' => $group, 'users' => [$editor, $former, $stays]] = $this->makeGroup(3);
        $expense = $this->makePurchase($group, $former, [$former, $editor, $stays], 'IN_CASH', 90.00, '2026-10-05');
        $expense->update(['user_creator_id' => $editor->id]);
        $group->members()->detach($former->id);

        $this->update($editor, $expense, ['user_payer_id' => $former->id])
            ->assertStatus(200)
            ->assertJsonPath('user_payer_id', $former->id);

        $this->assertSame($former->id, $expense->fresh()->user_payer_id);
    }

    public function test_the_creditor_can_be_changed_to_a_member_while_a_participant_who_left_stays(): void
    {
        ['editor' => $editor, 'former' => $former, 'stays' => $stays, 'expense' => $expense] = $this->expenseWithAFormerMember();

        $this->update($editor, $expense, ['user_payer_id' => $stays->id, 'payers' => [$editor->id, $former->id, $stays->id]])
            ->assertStatus(200)
            ->assertJsonPath('user_payer_id', $stays->id);

        $this->assertSame($stays->id, $expense->fresh()->user_payer_id);
        $this->assertContains($former->id, $this->payerIds($expense));
    }

    public function test_a_creditor_who_left_and_was_replaced_cannot_become_the_creditor_again(): void
    {
        ['group' => $group, 'users' => [$editor, $former, $stays]] = $this->makeGroup(3);
        $expense = $this->makePurchase($group, $former, [$former, $editor, $stays], 'IN_CASH', 90.00, '2026-10-05');
        $expense->update(['user_creator_id' => $editor->id]);
        $group->members()->detach($former->id);

        // Trocar para um membro é permitido...
        $this->update($editor, $expense, ['user_payer_id' => $stays->id])->assertStatus(200);
        $this->assertSame($stays->id, $expense->fresh()->user_payer_id);

        // ...mas depois disso ele não é "o credor atual": volta a valer a regra de membro.
        $this->update($editor, $expense, ['user_payer_id' => $former->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_payer_id']);

        $this->assertSame($stays->id, $expense->fresh()->user_payer_id);
    }

    public function test_a_participant_who_left_is_refused_as_the_creditor_even_if_still_a_member_of_another_group(): void
    {
        ['editor' => $editor, 'former' => $former, 'expense' => $expense] = $this->expenseWithAFormerMember();
        // Sair deste grupo não tira a pessoa do resto: o que vale é a lista de membros DESTE grupo.
        Group::create(['name' => 'Outro grupo'])->members()->attach($former->id);

        $this->update($editor, $expense, ['user_payer_id' => $former->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_payer_id']);

        $this->assertSame($editor->id, $expense->fresh()->user_payer_id);
    }

    public function test_the_creditor_who_left_can_also_be_added_as_a_participant(): void
    {
        ['group' => $group, 'users' => [$editor, $former, $stays]] = $this->makeGroup(3);
        // O credor não está entre os participantes, só em `user_payer_id`.
        $expense = $this->makePurchase($group, $former, [$editor, $stays], 'IN_CASH', 60.00, '2026-10-05');
        $expense->update(['user_creator_id' => $editor->id]);
        $group->members()->detach($former->id);

        $this->update($editor, $expense, ['payers' => [$editor->id, $stays->id, $former->id]])
            ->assertStatus(200);

        $this->assertContains($former->id, $this->payerIds($expense));
        $this->assertSame($former->id, $expense->fresh()->user_payer_id);
    }

    public function test_a_malformed_participant_is_a_422_not_a_server_error(): void
    {
        ['editor' => $editor, 'expense' => $expense] = $this->expenseWithAFormerMember();

        $this->update($editor, $expense, ['payers' => [[$editor->id]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payers.0']);

        $this->update($editor, $expense, ['user_payer_id' => [$editor->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_payer_id']);
    }
}
