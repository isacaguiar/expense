<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMemberResponsible;
use App\Models\User;
use App\Services\MemberResponsibleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `ex_group_member_responsibles.created_by` (feature 20261110-quem-paga-se-declara-e-credor-recusa,
 * plan §1, D3): registra quem criou o vínculo, o devedor (pelo `replace`) ou o próprio responsável
 * (pela declaração, TASK-604). Nula para os vínculos anteriores à coluna, que são do devedor.
 */
class MemberResponsibleCreatedByTest extends TestCase
{
    use DatabaseTransactions;

    private function groupWith(User ...$members): Group
    {
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach(array_map(fn (User $user) => $user->id, $members));

        return $group;
    }

    public function test_replace_stamps_created_by_with_the_debtor(): void
    {
        [$debtor, $first, $second] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith($debtor, $first, $second);

        app(MemberResponsibleService::class)->replace($group, $debtor->id, [$first->id, $second->id]);

        $rows = GroupMemberResponsible::where('group_id', $group->id)->where('user_id', $debtor->id)->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame($debtor->id, (int) $row->created_by);
        }
    }

    public function test_replace_keeps_created_by_of_the_links_that_stay(): void
    {
        [$debtor, $declared, $entering] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith($debtor, $declared, $entering);
        // Um vínculo que o próprio responsável criou (a declaração da TASK-604).
        GroupMemberResponsible::create([
            'group_id' => $group->id,
            'user_id' => $debtor->id,
            'responsible_user_id' => $declared->id,
            'created_by' => $declared->id,
        ]);

        app(MemberResponsibleService::class)->replace($group, $debtor->id, [$declared->id, $entering->id]);

        $stayed = GroupMemberResponsible::where('group_id', $group->id)->where('responsible_user_id', $declared->id)->first();
        $entered = GroupMemberResponsible::where('group_id', $group->id)->where('responsible_user_id', $entering->id)->first();
        $this->assertSame($declared->id, (int) $stayed->created_by, 'quem continua na lista não tem a autoria trocada');
        $this->assertSame($debtor->id, (int) $entered->created_by, 'quem entra pelo devedor leva o devedor como autor');
    }

    public function test_a_link_created_without_created_by_keeps_it_null(): void
    {
        [$debtor, $responsible] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith($debtor, $responsible);

        $link = GroupMemberResponsible::create([
            'group_id' => $group->id,
            'user_id' => $debtor->id,
            'responsible_user_id' => $responsible->id,
        ]);

        // Olha o banco, não o atributo do model: um atributo que não existe também lê como null.
        $this->assertTrue(Schema::hasColumn('ex_group_member_responsibles', 'created_by'), 'a coluna existe');
        $this->assertNull(DB::table('ex_group_member_responsibles')->where('id', $link->id)->value('created_by'));
    }

    public function test_created_by_is_nulled_when_that_user_is_deleted(): void
    {
        [$debtor, $responsible, $author] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith($debtor, $responsible, $author);
        $link = GroupMemberResponsible::create([
            'group_id' => $group->id,
            'user_id' => $debtor->id,
            'responsible_user_id' => $responsible->id,
            'created_by' => $author->id,
        ]);

        // Pré-condição lida do banco: a autoria foi gravada (sem a coluna, ou fora do `$fillable`, não foi).
        $this->assertSame($author->id, (int) DB::table('ex_group_member_responsibles')->where('id', $link->id)->value('created_by'));

        // Exclusão física direta: o que se quer provar é o `nullOnDelete` da chave estrangeira.
        DB::table('ex_users')->where('id', $author->id)->delete();

        $this->assertNotNull(GroupMemberResponsible::find($link->id), 'o vínculo continua');
        $this->assertNull(DB::table('ex_group_member_responsibles')->where('id', $link->id)->value('created_by'));
    }
}
