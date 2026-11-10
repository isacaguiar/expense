<?php

namespace Tests\Feature;

use App\Exceptions\MemberResponsibleRefused;
use App\Models\Group;
use App\Models\GroupMemberResponsible;
use App\Models\User;
use App\Services\MemberResponsibleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Regras do vínculo "quem paga por quem" (feature 20261109-responsavel-do-devedor, plan §2):
 * por grupo, cada devedor tem nenhum, um ou vários responsáveis, e a lista é sempre
 * substituída inteira (specify R1, R3, R4, R12).
 */
class MemberResponsibleServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): MemberResponsibleService
    {
        return app(MemberResponsibleService::class);
    }

    /** Grupo com exatamente estes membros. */
    private function groupWith(User ...$members): Group
    {
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach(array_map(fn (User $user) => $user->id, $members));

        return $group;
    }

    /**
     * Ids dos responsáveis gravados para o devedor no grupo, em ordem crescente.
     *
     * @return list<int>
     */
    private function responsibleIdsOf(Group $group, User $debtor): array
    {
        return GroupMemberResponsible::where('group_id', $group->id)
            ->where('user_id', $debtor->id)
            ->orderBy('responsible_user_id')
            ->pluck('responsible_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function test_replace_creates_the_links_for_a_list_of_responsibles(): void
    {
        [$debtor, $first, $second] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith($debtor, $first, $second);

        $result = $this->service()->replace($group, $debtor->id, [$first->id, $second->id]);

        $this->assertSame([$first->id, $second->id], $result['added']);
        $this->assertSame([], $result['removed']);
        $this->assertSame([$first->id, $second->id], $this->responsibleIdsOf($group, $debtor));
    }

    public function test_replace_keeps_the_row_of_who_stays_and_reports_added_and_removed(): void
    {
        [$debtor, $leaving, $staying, $entering] = User::factory()->count(4)->create()->all();
        $group = $this->groupWith($debtor, $leaving, $staying, $entering);
        $this->service()->replace($group, $debtor->id, [$leaving->id, $staying->id]);
        $stayingRowId = GroupMemberResponsible::where('group_id', $group->id)
            ->where('responsible_user_id', $staying->id)->value('id');

        $result = $this->service()->replace($group, $debtor->id, [$staying->id, $entering->id]);

        $this->assertSame([$entering->id], $result['added']);
        $this->assertSame([$leaving->id], $result['removed']);
        $this->assertSame(
            $stayingRowId,
            GroupMemberResponsible::where('group_id', $group->id)->where('responsible_user_id', $staying->id)->value('id'),
            'quem continua na lista não pode ter a linha recriada'
        );
        $this->assertEqualsCanonicalizing([$staying->id, $entering->id], $this->responsibleIdsOf($group, $debtor));
    }

    public function test_replace_with_an_empty_list_removes_every_link_of_the_debtor(): void
    {
        [$debtor, $other, $first, $second] = User::factory()->count(4)->create()->all();
        $group = $this->groupWith($debtor, $other, $first, $second);
        $this->service()->replace($group, $debtor->id, [$first->id, $second->id]);
        $this->service()->replace($group, $other->id, [$first->id]);

        $result = $this->service()->replace($group, $debtor->id, []);

        $this->assertSame([], $result['added']);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $result['removed']);
        $this->assertSame([], $this->responsibleIdsOf($group, $debtor));
        $this->assertSame([$first->id], $this->responsibleIdsOf($group, $other), 'o vínculo de outro devedor fica');
    }

    public function test_replace_with_the_same_list_changes_nothing(): void
    {
        [$debtor, $first, $second] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith($debtor, $first, $second);
        $this->service()->replace($group, $debtor->id, [$first->id, $second->id]);
        $rowIds = GroupMemberResponsible::where('group_id', $group->id)->orderBy('id')->pluck('id')->all();

        $result = $this->service()->replace($group, $debtor->id, [$second->id, $first->id]);

        $this->assertSame([], $result['added']);
        $this->assertSame([], $result['removed']);
        $this->assertSame($rowIds, GroupMemberResponsible::where('group_id', $group->id)->orderBy('id')->pluck('id')->all());
    }

    public function test_replace_refuses_the_debtor_as_their_own_responsible(): void
    {
        [$debtor, $other] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith($debtor, $other);

        try {
            $this->service()->replace($group, $debtor->id, [$other->id, $debtor->id]);
            $this->fail('devia recusar o próprio devedor na lista');
        } catch (MemberResponsibleRefused $e) {
            $this->assertSame('Você não pode ser quem paga por você mesmo.', $e->getMessage());
        }

        $this->assertSame([], $this->responsibleIdsOf($group, $debtor));
    }

    public function test_replace_refuses_a_repeated_id(): void
    {
        [$debtor, $other] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith($debtor, $other);

        try {
            $this->service()->replace($group, $debtor->id, [$other->id, $other->id]);
            $this->fail('devia recusar id repetido');
        } catch (MemberResponsibleRefused $e) {
            $this->assertSame('A lista não pode repetir ninguém.', $e->getMessage());
        }

        $this->assertSame([], $this->responsibleIdsOf($group, $debtor));
    }

    public function test_replace_refuses_a_responsible_who_is_not_a_member(): void
    {
        [$debtor, $outsider] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith($debtor);

        try {
            $this->service()->replace($group, $debtor->id, [$outsider->id]);
            $this->fail('devia recusar quem não é membro');
        } catch (MemberResponsibleRefused $e) {
            $this->assertSame('Escolha só membros deste grupo.', $e->getMessage());
        }

        $this->assertSame([], $this->responsibleIdsOf($group, $debtor));
    }

    public function test_replace_refuses_when_the_debtor_is_not_a_member(): void
    {
        [$debtor, $member] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith($member);

        $this->expectException(MemberResponsibleRefused::class);

        $this->service()->replace($group, $debtor->id, [$member->id]);
    }

    public function test_replace_changes_nothing_when_any_id_is_refused(): void
    {
        [$debtor, $kept, $newcomer, $outsider] = User::factory()->count(4)->create()->all();
        $group = $this->groupWith($debtor, $kept, $newcomer);
        $this->service()->replace($group, $debtor->id, [$kept->id]);

        try {
            $this->service()->replace($group, $debtor->id, [$newcomer->id, $outsider->id]);
            $this->fail('devia recusar a lista com um não membro');
        } catch (MemberResponsibleRefused) {
            // esperado
        }

        $this->assertSame([$kept->id], $this->responsibleIdsOf($group, $debtor), 'a lista antiga continua igual');
    }

    public function test_replace_aborts_with_404_when_the_group_is_deleted(): void
    {
        [$debtor, $other] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith($debtor, $other);
        $group->update(['deleted' => true]);

        try {
            $this->service()->replace($group, $debtor->id, [$other->id]);
            $this->fail('devia abortar com 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame([], $this->responsibleIdsOf($group, $debtor));
    }

    public function test_clear_for_deletes_links_in_both_directions_and_returns_the_count(): void
    {
        [$user, $responsible, $dependent, $third] = User::factory()->count(4)->create()->all();
        $group = $this->groupWith($user, $responsible, $dependent, $third);
        $otherGroup = $this->groupWith($user, $responsible);
        $this->service()->replace($group, $user->id, [$responsible->id]);       // $user é devedor
        $this->service()->replace($group, $dependent->id, [$user->id]);          // $user é responsável
        $this->service()->replace($group, $third->id, [$responsible->id]);       // não envolve $user
        $this->service()->replace($otherGroup, $user->id, [$responsible->id]);   // outro grupo

        $cleared = $this->service()->clearFor($group->id, $user->id);

        $this->assertSame(2, $cleared);
        $this->assertSame([], $this->responsibleIdsOf($group, $user));
        $this->assertSame([], $this->responsibleIdsOf($group, $dependent));
        $this->assertSame([$responsible->id], $this->responsibleIdsOf($group, $third), 'vínculo alheio fica');
        $this->assertSame([$responsible->id], $this->responsibleIdsOf($otherGroup, $user), 'outro grupo fica');
    }

    public function test_can_pay_for_requires_the_exact_group_debtor_and_responsible(): void
    {
        [$debtor, $responsible, $stranger] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith($debtor, $responsible, $stranger);
        $otherGroup = $this->groupWith($debtor, $responsible, $stranger);
        $this->service()->replace($group, $debtor->id, [$responsible->id]);

        $this->assertTrue($this->service()->canPayFor($group->id, $responsible->id, $debtor->id));
        $this->assertFalse($this->service()->canPayFor($otherGroup->id, $responsible->id, $debtor->id), 'outro grupo');
        $this->assertFalse($this->service()->canPayFor($group->id, $stranger->id, $debtor->id), 'outro responsável');
        $this->assertFalse($this->service()->canPayFor($group->id, $responsible->id, $stranger->id), 'outro devedor');
        $this->assertFalse($this->service()->canPayFor($group->id, $debtor->id, $responsible->id), 'papéis trocados');
    }

    public function test_responsibles_by_debtor_returns_a_name_sorted_list_with_only_id_and_name(): void
    {
        $debtor = User::factory()->create();
        $other = User::factory()->create();
        $zeca = User::factory()->create(['name' => 'Zeca']);
        $ana = User::factory()->create(['name' => 'Ana']);
        $group = $this->groupWith($debtor, $other, $zeca, $ana);
        $this->service()->replace($group, $debtor->id, [$zeca->id, $ana->id]);
        $this->service()->replace($group, $other->id, [$zeca->id]);

        $byDebtor = $this->service()->responsiblesByDebtor($group->id);

        $this->assertSame(
            [['id' => $ana->id, 'name' => 'Ana'], ['id' => $zeca->id, 'name' => 'Zeca']],
            $byDebtor->get($debtor->id)
        );
        $this->assertSame([['id' => $zeca->id, 'name' => 'Zeca']], $byDebtor->get($other->id));
        $this->assertNull($byDebtor->get($ana->id), 'quem não tem responsável não aparece na coleção');
    }

    public function test_the_same_responsible_can_serve_several_debtors(): void
    {
        [$responsible, $first, $second] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith($responsible, $first, $second);

        $this->service()->replace($group, $first->id, [$responsible->id]);
        $this->service()->replace($group, $second->id, [$responsible->id]);

        $this->assertTrue($this->service()->canPayFor($group->id, $responsible->id, $first->id));
        $this->assertTrue($this->service()->canPayFor($group->id, $responsible->id, $second->id));
    }

    public function test_the_unique_index_rejects_a_duplicate_link(): void
    {
        [$debtor, $responsible] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith($debtor, $responsible);
        $link = ['group_id' => $group->id, 'user_id' => $debtor->id, 'responsible_user_id' => $responsible->id];
        GroupMemberResponsible::create($link);

        $this->expectException(QueryException::class);

        GroupMemberResponsible::create($link);
    }
}
