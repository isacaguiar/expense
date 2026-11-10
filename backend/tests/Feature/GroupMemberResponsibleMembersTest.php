<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMemberResponsible;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsPurchaseScenarios;
use Tests\TestCase;

/**
 * O vínculo "quem paga por quem" na lista de membros e na saída do grupo (feature
 * 20261109-responsavel-do-devedor, plan §4): `GET /members` traz `responsibles` por membro (R13), e
 * sair ou ser removido apaga os vínculos da pessoa nas duas direções (R12).
 */
class GroupMemberResponsibleMembersTest extends TestCase
{
    use BuildsPurchaseScenarios;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    /** Grupo com exatamente estes membros; `$creator` (se vier) é o criador. */
    private function groupWith(array $members, ?User $creator = null): Group
    {
        $group = Group::create(['name' => 'Casa', 'created_by' => $creator?->id]);
        $group->members()->attach(array_map(fn (User $user) => $user->id, $members));

        return $group;
    }

    /** `$responsible` passa a poder pagar por `$debtor` no grupo (a linha direto, sem o service). */
    private function link(Group $group, User $debtor, User $responsible): void
    {
        GroupMemberResponsible::create([
            'group_id' => $group->id,
            'user_id' => $debtor->id,
            'responsible_user_id' => $responsible->id,
        ]);
    }

    /**
     * Pares "devedor => responsável" gravados no grupo, ordenados, como `"devedor>responsável"`.
     *
     * @return list<string>
     */
    private function links(Group $group): array
    {
        return GroupMemberResponsible::where('group_id', $group->id)
            ->orderBy('user_id')->orderBy('responsible_user_id')
            ->get()
            ->map(fn (GroupMemberResponsible $row) => $row->user_id.'>'.$row->responsible_user_id)
            ->all();
    }

    private function listMembers(User $caller, Group $group): TestResponse
    {
        return $this->withToken($this->tokenFor($caller))->getJson("/api/groups/{$group->id}/members");
    }

    /** `$caller` tira `$target` do grupo (sai, se for ele mesmo, ou remove, se for o criador). */
    private function removeMember(User $caller, Group $group, User $target): TestResponse
    {
        return $this->withToken($this->tokenFor($caller))->deleteJson("/api/groups/{$group->id}/members/{$target->id}");
    }

    private function entryOf(TestResponse $response, User $member): array
    {
        return collect($response->json())->firstWhere('id', $member->id);
    }

    // --- R13: GET /members -------------------------------------------------

    public function test_members_list_includes_the_responsibles_with_id_and_name_only(): void
    {
        $debtor = User::factory()->create();
        $ana = User::factory()->create(['name' => 'Ana', 'pix' => 'chave-secreta-da-ana']);
        $group = $this->groupWith([$debtor, $ana]);
        $this->link($group, $debtor, $ana);

        $response = $this->listMembers($debtor, $group)->assertOk();

        $responsibles = $this->entryOf($response, $debtor)['responsibles'];
        $this->assertSame([['id' => $ana->id, 'name' => 'Ana']], $responsibles);
        $this->assertSame(['id', 'name'], array_keys($responsibles[0]), 'nunca e-mail, Pix ou contato do responsável');
    }

    public function test_members_without_responsibles_have_an_empty_list(): void
    {
        [$first, $second] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$first, $second]);

        $response = $this->listMembers($first, $group)->assertOk();

        $this->assertSame([], $this->entryOf($response, $first)['responsibles']);
        $this->assertSame([], $this->entryOf($response, $second)['responsibles']);
    }

    public function test_a_debtor_with_two_responsibles_lists_both(): void
    {
        $debtor = User::factory()->create();
        $bruno = User::factory()->create(['name' => 'Bruno']);
        $ana = User::factory()->create(['name' => 'Ana']);
        $group = $this->groupWith([$debtor, $bruno, $ana]);
        $this->link($group, $debtor, $bruno);
        $this->link($group, $debtor, $ana);

        $response = $this->listMembers($ana, $group)->assertOk();

        $this->assertSame(
            [['id' => $ana->id, 'name' => 'Ana'], ['id' => $bruno->id, 'name' => 'Bruno']],
            $this->entryOf($response, $debtor)['responsibles'],
            'qualquer membro vê os vínculos, ordenados por nome'
        );
        $this->assertSame([], $this->entryOf($response, $ana)['responsibles']);
    }

    // --- R12: saída e remoção apagam os vínculos --------------------------

    public function test_leaving_clears_the_links_where_the_user_is_the_debtor(): void
    {
        [$leaver, $first, $second, $other] = User::factory()->count(4)->create()->all();
        $group = $this->groupWith([$leaver, $first, $second, $other]);
        $this->link($group, $leaver, $first);
        $this->link($group, $leaver, $second);
        $this->link($group, $other, $first);

        $this->removeMember($leaver, $group, $leaver)->assertOk();

        $this->assertSame([$other->id.'>'.$first->id], $this->links($group), 'só o vínculo alheio fica');
    }

    public function test_leaving_clears_the_links_where_the_user_is_a_responsible(): void
    {
        [$leaver, $stays, $debtor, $other] = User::factory()->count(4)->create()->all();
        $group = $this->groupWith([$leaver, $stays, $debtor, $other]);
        $this->link($group, $debtor, $leaver);
        $this->link($group, $debtor, $stays);
        $this->link($group, $other, $leaver);

        $this->removeMember($leaver, $group, $leaver)->assertOk();

        $this->assertSame(
            [$debtor->id.'>'.$stays->id],
            $this->links($group),
            'o dependente e os outros responsáveis continuam; só os vínculos de quem saiu somem'
        );
    }

    public function test_creator_removing_a_member_clears_their_links(): void
    {
        [$creator, $removed, $first, $second] = User::factory()->count(4)->create()->all();
        $group = $this->groupWith([$creator, $removed, $first, $second], $creator);
        $this->link($group, $removed, $first);   // $removed é devedor
        $this->link($group, $second, $removed);  // $removed é responsável
        $this->link($group, $second, $first);    // não envolve $removed

        $this->removeMember($creator, $group, $removed)->assertOk();

        $this->assertSame([$second->id.'>'.$first->id], $this->links($group));
    }

    public function test_blocked_exit_keeps_the_links(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        ['group' => $group, 'users' => [$creditor, $debtor]] = $this->makeGroup(2);
        $this->makePurchase($group, $creditor, [$creditor, $debtor], 'IN_INSTALLMENTS', 200.00, '2026-12-10', 2);
        $this->link($group, $creditor, $debtor);
        $this->link($group, $debtor, $creditor);
        $before = $this->links($group);

        $this->removeMember($creditor, $group, $creditor)->assertStatus(422)->assertJsonCount(1, 'blocking');

        $this->assertSame($before, $this->links($group), 'o 422 de bloqueio não apaga nada');
        $this->assertTrue($group->members()->where('user_id', $creditor->id)->exists());
    }

    public function test_last_member_leaving_clears_the_links(): void
    {
        [$last, $gone] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$last]);
        // Resto de um estado antigo: o responsável já não é membro, mas a linha ainda existe.
        $this->link($group, $last, $gone);

        $this->removeMember($last, $group, $last)->assertOk();

        $this->assertTrue($group->fresh()->deleted, 'o último membro que sai deixa o grupo excluído');
        $this->assertSame([], $this->links($group));
    }
}
