<?php

namespace Tests\Feature;

use App\Exceptions\MemberResponsibleRefused;
use App\Models\Group;
use App\Models\GroupMemberResponsible;
use App\Models\User;
use App\Services\MemberResponsibleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * `MemberResponsibleService::declare` direto, sem passar pela rota (feature
 * 20261110-quem-paga-se-declara-e-credor-recusa, TASK-604): as defesas do service que o controller já
 * cobre por outro caminho (quem chama ser membro, o grupo excluído) e o lock do grupo, que os testes da
 * rota não enxergam.
 */
class MemberResponsibleDeclareTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): MemberResponsibleService
    {
        return app(MemberResponsibleService::class);
    }

    private function groupWith(array $members): Group
    {
        $group = Group::create(['name' => 'Casa']);
        $group->members()->attach(array_map(fn (User $user) => $user->id, $members));

        return $group;
    }

    public function test_it_returns_who_entered_and_who_left(): void
    {
        [$me, $first, $second, $third] = User::factory()->count(4)->create()->all();
        $group = $this->groupWith([$me, $first, $second, $third]);

        $this->assertSame(
            ['added' => [$first->id, $second->id], 'removed' => []],
            $this->service()->declare($group, $me->id, [$first->id, $second->id])
        );
        $this->assertSame(
            ['added' => [$third->id], 'removed' => [$first->id]],
            $this->service()->declare($group, $me->id, [$second->id, $third->id])
        );
        $this->assertSame(
            ['added' => [], 'removed' => [$second->id, $third->id]],
            $this->service()->declare($group, $me->id, [])
        );
    }

    public function test_a_caller_who_is_not_a_member_is_refused_and_nothing_is_written(): void
    {
        [$outsider, $member] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$member]);

        try {
            $this->service()->declare($group, $outsider->id, [$member->id]);
            $this->fail('quem não é membro não pode se declarar');
        } catch (MemberResponsibleRefused $e) {
            $this->assertSame('Quem escolhe precisa ser membro deste grupo.', $e->getMessage());
        }

        $this->assertSame(0, GroupMemberResponsible::where('group_id', $group->id)->count());
    }

    public function test_a_deleted_group_aborts_with_404(): void
    {
        [$me, $debtor] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$me, $debtor]);
        $group->update(['deleted' => true]);

        try {
            $this->service()->declare($group, $me->id, [$debtor->id]);
            $this->fail('o grupo excluído não aceita declaração');
        } catch (HttpException|HttpResponseException $e) {
            $this->assertSame(404, $e instanceof HttpException ? $e->getStatusCode() : $e->getResponse()->getStatusCode());
        }

        $this->assertSame(0, GroupMemberResponsible::where('group_id', $group->id)->count());
    }

    public function test_the_group_is_read_with_a_lock(): void
    {
        [$me, $debtor] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$me, $debtor]);

        DB::enableQueryLog();
        $this->service()->declare($group, $me->id, [$debtor->id]);
        $queries = collect(DB::getQueryLog())->pluck('query');

        $this->assertTrue(
            $queries->contains(fn ($q) => str_contains($q, 'for update') && str_contains($q, $group->getTable())),
            'o grupo é lido com for update, para serializar a declaração contra a saída de membros'
        );
    }
}
