<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Participation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GroupControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    private function createExpense(Group $group, User $creator, string $datePayment): Expense
    {
        return Expense::create([
            'create_date' => now(),
            'date_payment' => $datePayment,
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $creator->id,
            'user_payer_id' => $creator->id,
            'deleted' => false,
        ]);
    }

    public function test_member_can_view_group(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups/'.$group->id);

        $response->assertStatus(200)->assertJsonFragment(['id' => $group->id]);
    }

    public function test_show_includes_creator_email(): void
    {
        $creator = User::factory()->create(['email' => 'criador@example.com']);
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'created_by' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id]);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups/'.$group->id);

        $response->assertStatus(200)->assertJsonFragment(['creator' => ['id' => $creator->id, 'email' => 'criador@example.com']]);
    }

    public function test_index_includes_creator_email(): void
    {
        $creator = User::factory()->create(['email' => 'criador2@example.com']);
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'created_by' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id]);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups');

        $response->assertStatus(200)->assertJsonFragment(['creator' => ['id' => $creator->id, 'email' => 'criador2@example.com']]);
    }

    public function test_index_includes_members_list(): void
    {
        $member = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
        $other = User::factory()->create(['name' => 'Bruno', 'email' => 'bruno@example.com']);
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$member->id, $other->id]);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups');

        $response->assertStatus(200)->assertJsonFragment(['id' => $member->id, 'email' => 'ana@example.com']);
        $response->assertJsonFragment(['id' => $other->id, 'email' => 'bruno@example.com']);
    }

    public function test_index_includes_expenses_max_date_payment(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo com despesas']);
        $group->members()->attach($member->id);
        $this->createExpense($group, $member, '2026-01-10');
        $this->createExpense($group, $member, '2026-03-20');

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups');

        $response->assertStatus(200)->assertJsonFragment(['expenses_max_date_payment' => '2026-03-20']);
    }

    public function test_index_expenses_max_date_payment_is_null_without_expenses(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo sem despesas']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups');

        $response->assertStatus(200)->assertJsonFragment(['id' => $group->id, 'expenses_max_date_payment' => null]);
    }

    public function test_index_includes_cycle_snapshots_exists_true_when_group_has_closed_cycle(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo com fechamento']);
        $group->members()->attach($member->id);

        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'totals' => [],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => now(),
        ]);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups');

        $response->assertStatus(200)->assertJsonFragment(['id' => $group->id, 'cycle_snapshots_exists' => true]);
    }

    public function test_index_includes_cycle_snapshots_exists_false_without_closed_cycle(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo sem fechamento']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups');

        $response->assertStatus(200)->assertJsonFragment(['id' => $group->id, 'cycle_snapshots_exists' => false]);
    }

    public function test_non_member_cannot_view_group(): void
    {
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);

        $response = $this->withToken($this->tokenFor($outsider))
            ->getJson('/api/groups/'.$group->id);

        $response->assertStatus(404);
    }

    public function test_member_can_update_group(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Nome antigo']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->putJson('/api/groups/'.$group->id, ['name' => 'Nome novo']);

        $response->assertStatus(200)->assertJsonFragment(['name' => 'Nome novo']);
        $this->assertDatabaseHas('ex_groups', ['id' => $group->id, 'name' => 'Nome novo']);
    }

    public function test_non_member_cannot_update_group(): void
    {
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Nome antigo']);

        $response = $this->withToken($this->tokenFor($outsider))
            ->putJson('/api/groups/'.$group->id, ['name' => 'Nome novo']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('ex_groups', ['id' => $group->id, 'name' => 'Nome antigo']);
    }

    public function test_member_can_delete_group_without_closed_cycle_physically(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $expense = $this->createExpense($group, $member, '2026-08-10');
        $expense->payers()->attach($member->id);
        $quota = $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);
        Participation::create(['state' => 'PENDING', 'group_id' => $group->id, 'quota_id' => $quota->id]);

        $response = $this->withToken($this->tokenFor($member))
            ->deleteJson('/api/groups/'.$group->id);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('ex_groups', ['id' => $group->id]);
        $this->assertDatabaseMissing('ex_groups_members', ['group_id' => $group->id]);
        $this->assertDatabaseMissing('ex_expenses', ['id' => $expense->id]);
        $this->assertDatabaseMissing('ex_quotas', ['id' => $quota->id]);
        $this->assertDatabaseMissing('ex_expenses_payers', ['expense_id' => $expense->id]);
        $this->assertDatabaseMissing('ex_participations', ['quota_id' => $quota->id]);
    }

    public function test_member_can_delete_group_with_closed_cycle_logically_preserving_history(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $expense = $this->createExpense($group, $member, '2026-08-10');
        $expense->payers()->attach($member->id);
        $quota = $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => 100]);
        Participation::create(['state' => 'PENDING', 'group_id' => $group->id, 'quota_id' => $quota->id]);

        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'totals' => [],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => now(),
        ]);

        $response = $this->withToken($this->tokenFor($member))
            ->deleteJson('/api/groups/'.$group->id);

        $response->assertStatus(200);
        $this->assertDatabaseHas('ex_groups', ['id' => $group->id, 'deleted' => true]);
        $this->assertDatabaseHas('ex_groups_members', ['group_id' => $group->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('ex_expenses', ['id' => $expense->id]);
        $this->assertDatabaseHas('ex_quotas', ['id' => $quota->id]);
        $this->assertDatabaseHas('ex_expenses_payers', ['expense_id' => $expense->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('ex_participations', ['quota_id' => $quota->id]);
        $this->assertDatabaseHas('ex_group_cycle_snapshots', ['group_id' => $group->id]);
    }

    public function test_non_member_cannot_delete_group(): void
    {
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);

        $response = $this->withToken($this->tokenFor($outsider))
            ->deleteJson('/api/groups/'.$group->id);

        $response->assertStatus(404);
        $this->assertDatabaseHas('ex_groups', ['id' => $group->id, 'deleted' => false]);
    }

    public function test_store_persists_closing_day(): void
    {
        $member = User::factory()->create();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups', ['name' => 'Grupo com fechamento', 'closing_day' => 10]);

        $response->assertStatus(201)->assertJsonFragment(['closing_day' => 10]);
        $this->assertDatabaseHas('ex_groups', ['name' => 'Grupo com fechamento', 'closing_day' => 10]);
    }

    public function test_store_without_closing_day_defaults_to_null(): void
    {
        $member = User::factory()->create();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups', ['name' => 'Grupo sem fechamento']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('ex_groups', ['name' => 'Grupo sem fechamento', 'closing_day' => null]);
    }

    public function test_store_rejects_invalid_closing_day(): void
    {
        $member = User::factory()->create();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups', ['name' => 'Grupo inválido', 'closing_day' => 32]);

        $response->assertStatus(422);
    }

    public function test_store_sets_created_by_to_authenticated_user(): void
    {
        $member = User::factory()->create();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups', ['name' => 'Grupo com criador']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('ex_groups', ['name' => 'Grupo com criador', 'created_by' => $member->id]);
    }

    public function test_store_blocks_fourth_group_created_by_same_user(): void
    {
        $member = User::factory()->create();
        $token = $this->tokenFor($member);

        foreach (range(1, 3) as $i) {
            $this->withToken($token)
                ->postJson('/api/groups', ['name' => "Grupo {$i}"])
                ->assertStatus(201);
        }

        $response = $this->withToken($token)
            ->postJson('/api/groups', ['name' => 'Grupo 4']);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('ex_groups', ['name' => 'Grupo 4']);
    }

    public function test_store_ignores_deleted_groups_when_counting_limit(): void
    {
        $member = User::factory()->create();
        $token = $this->tokenFor($member);

        foreach (range(1, 3) as $i) {
            $this->withToken($token)
                ->postJson('/api/groups', ['name' => "Grupo excluido {$i}"])
                ->assertStatus(201);
        }

        Group::where('created_by', $member->id)->update(['deleted' => true]);

        $response = $this->withToken($token)
            ->postJson('/api/groups', ['name' => 'Grupo novo apos exclusao']);

        $response->assertStatus(201);
    }

    /**
     * Item 086, D4-A (CA-8): o limite conta os grupos criados de que a pessoa ainda
     * é membro. Sair de um grupo libera a vaga, mas o grupo segue com `created_by`.
     *
     * @return array{0: User, 1: string, 2: array<int, int>} criador, token, ids dos 3 grupos
     */
    private function creatorWithThreeGroups(): array
    {
        $creator = User::factory()->create();
        $token = $this->tokenFor($creator);

        $ids = [];
        foreach (range(1, 3) as $i) {
            $ids[] = $this->withToken($token)
                ->postJson('/api/groups', ['name' => "Grupo {$i}"])
                ->assertStatus(201)
                ->json('id');
        }

        return [$creator, $token, $ids];
    }

    public function test_store_does_not_count_a_group_the_creator_has_left(): void
    {
        [$creator, $token, $ids] = $this->creatorWithThreeGroups();
        // Outra pessoa no primeiro grupo: ele continua existindo (e com created_by) depois da saída.
        $other = User::factory()->create();
        Group::find($ids[0])->members()->attach($other->id);

        $this->withToken($token)
            ->deleteJson("/api/groups/{$ids[0]}/members/{$creator->id}")
            ->assertStatus(200);
        $this->assertDatabaseHas('ex_groups', ['id' => $ids[0], 'created_by' => $creator->id, 'deleted' => 0]);

        $this->withToken($token)->postJson('/api/groups', ['name' => 'Grupo 4'])->assertStatus(201);

        // A vaga liberada foi uma só: os 2 grupos que ele integra mais o novo já são 3.
        $this->withToken($token)->postJson('/api/groups', ['name' => 'Grupo 5'])->assertStatus(422);
        $this->assertDatabaseMissing('ex_groups', ['name' => 'Grupo 5']);
    }

    public function test_store_still_counts_a_group_the_creator_belongs_to_when_someone_else_leaves(): void
    {
        [$creator, $token, $ids] = $this->creatorWithThreeGroups();
        $other = User::factory()->create();
        Group::find($ids[0])->members()->attach($other->id);

        $this->withToken($this->tokenFor($other))
            ->deleteJson("/api/groups/{$ids[0]}/members/{$other->id}")
            ->assertStatus(200);

        $this->withToken($token)->postJson('/api/groups', ['name' => 'Grupo 4'])->assertStatus(422);
        $this->assertDatabaseMissing('ex_groups', ['name' => 'Grupo 4']);
    }

    public function test_member_can_update_closing_day(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'closing_day' => 5]);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->putJson('/api/groups/'.$group->id, ['name' => 'Grupo de teste', 'closing_day' => 15]);

        $response->assertStatus(200)->assertJsonFragment(['closing_day' => 15]);
        $this->assertDatabaseHas('ex_groups', ['id' => $group->id, 'closing_day' => 15]);
    }

    // ---- Item 086, R12 (CA-20, TASK-564): `creator` de quem saiu não segue saindo ----

    private const CREATOR_EMAIL = 'criador-que-saiu@example.test';

    /**
     * Grupo criado por alguém que depois saiu: `created_by` fica (autoriza a remoção
     * de membros, D2), mas a pessoa já não está em `ex_groups_members`.
     *
     * @return array{0: Group, 1: User, 2: User} grupo, criador (fora do grupo), membro que ficou
     */
    private function groupWhoseCreatorLeft(string $email = self::CREATOR_EMAIL): array
    {
        $creator = User::factory()->create(['email' => $email]);
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo do criador que saiu', 'created_by' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id]);
        $group->members()->detach($creator->id);

        return [$group, $creator, $member];
    }

    /** @return array<string, mixed> o grupo `$group` dentro da lista devolvida por GET /groups */
    private function indexEntryOf(Group $group, TestResponse $response): array
    {
        $entry = collect($response->json())->firstWhere('id', $group->id);
        $this->assertNotNull($entry, 'o grupo deveria estar na lista');

        return $entry;
    }

    public function test_index_hides_the_creator_who_left_the_group(): void
    {
        [$group, $creator, $member] = $this->groupWhoseCreatorLeft();

        $response = $this->withToken($this->tokenFor($member))->getJson('/api/groups')->assertStatus(200);
        $entry = $this->indexEntryOf($group, $response);

        $this->assertArrayHasKey('creator', $entry);
        $this->assertNull($entry['creator']);
        $this->assertSame($creator->id, $entry['created_by']);
        $this->assertStringNotContainsString(self::CREATOR_EMAIL, $response->getContent());
    }

    public function test_show_hides_the_creator_who_left_the_group(): void
    {
        [$group, $creator, $member] = $this->groupWhoseCreatorLeft();

        $response = $this->withToken($this->tokenFor($member))->getJson('/api/groups/'.$group->id)->assertStatus(200);

        $this->assertArrayHasKey('creator', $response->json());
        $this->assertNull($response->json('creator'));
        $this->assertSame($creator->id, $response->json('created_by'));
        $this->assertStringNotContainsString(self::CREATOR_EMAIL, $response->getContent());
    }

    public function test_index_decides_the_creator_group_by_group(): void
    {
        // A mesma pessoa criou os dois grupos; saiu de um e continua no outro.
        [$left, $creator, $member] = $this->groupWhoseCreatorLeft();
        $stayed = Group::create(['name' => 'Grupo em que o criador ficou', 'created_by' => $creator->id]);
        $stayed->members()->attach([$creator->id, $member->id]);

        $response = $this->withToken($this->tokenFor($member))->getJson('/api/groups')->assertStatus(200);

        $this->assertNull($this->indexEntryOf($left, $response)['creator']);
        $this->assertSame(
            ['id' => $creator->id, 'email' => self::CREATOR_EMAIL],
            $this->indexEntryOf($stayed, $response)['creator']
        );
    }

    public function test_show_decides_the_creator_by_this_group_only(): void
    {
        // Saiu deste grupo, mas segue em outro: o e-mail continua fora de *este* grupo.
        [$left, $creator, $member] = $this->groupWhoseCreatorLeft();
        $other = Group::create(['name' => 'Outro grupo do criador', 'created_by' => $creator->id]);
        $other->members()->attach([$creator->id, $member->id]);

        $response = $this->withToken($this->tokenFor($member))->getJson('/api/groups/'.$left->id)->assertStatus(200);

        $this->assertNull($response->json('creator'));
        $this->assertStringNotContainsString(self::CREATOR_EMAIL, $response->getContent());
    }

    public function test_creator_who_left_and_was_added_back_is_shown_again(): void
    {
        [$group, $creator, $member] = $this->groupWhoseCreatorLeft();
        $group->members()->attach($creator->id);

        $this->withToken($this->tokenFor($member))->getJson('/api/groups/'.$group->id)
            ->assertStatus(200)
            ->assertJsonFragment(['creator' => ['id' => $creator->id, 'email' => self::CREATOR_EMAIL]]);

        $response = $this->withToken($this->tokenFor($member))->getJson('/api/groups')->assertStatus(200);
        $this->assertSame(
            ['id' => $creator->id, 'email' => self::CREATOR_EMAIL],
            $this->indexEntryOf($group, $response)['creator']
        );
    }

    public function test_group_without_created_by_keeps_a_null_creator(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo sem criador registrado']);
        $group->members()->attach($member->id);
        $token = $this->tokenFor($member);

        $index = $this->withToken($token)->getJson('/api/groups')->assertStatus(200);
        $entry = $this->indexEntryOf($group, $index);
        $this->assertArrayHasKey('creator', $entry);
        $this->assertNull($entry['creator']);
        $this->assertNull($entry['created_by']);

        $show = $this->withToken($token)->getJson('/api/groups/'.$group->id)->assertStatus(200);
        $this->assertArrayHasKey('creator', $show->json());
        $this->assertNull($show->json('creator'));
    }

    /** @return array<int, string> o SQL de cada consulta que `$run` dispara */
    private function groupQueriesOf(callable $run): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $run();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $queries;
    }

    public function test_index_runs_the_same_number_of_queries_whether_the_creators_left_or_not(): void
    {
        // Quem lista vê 3 grupos de criadores que ficaram, 1 de criador que saiu e 3 de criadores que saíram.
        $stayedViewer = User::factory()->create();
        $oneLeftViewer = User::factory()->create();
        $threeLeftViewer = User::factory()->create();

        foreach (range(1, 3) as $i) {
            $creator = User::factory()->create();
            $group = Group::create(['name' => "Criador ficou {$i}", 'created_by' => $creator->id]);
            $group->members()->attach([$creator->id, $stayedViewer->id]);
        }
        foreach ([[$oneLeftViewer, 1], [$threeLeftViewer, 3]] as [$viewer, $groups]) {
            foreach (range(1, $groups) as $i) {
                $creator = User::factory()->create();
                $group = Group::create(['name' => "Criador saiu {$i}", 'created_by' => $creator->id]);
                $group->members()->attach([$creator->id, $viewer->id]);
                $group->members()->detach($creator->id);
            }
        }

        $index = fn (User $viewer) => count($this->groupQueriesOf(
            fn () => $this->withToken($this->tokenFor($viewer))->getJson('/api/groups')->assertStatus(200)
        ));
        $index($stayedViewer); // aquece o que é cacheado na primeira requisição

        $baseline = $index($stayedViewer);
        $this->assertSame($baseline, $index($oneLeftViewer));
        $this->assertSame($baseline, $index($threeLeftViewer));
    }

    public function test_show_runs_the_same_number_of_queries_whether_the_creator_left_or_not(): void
    {
        [$leftGroup, , $member] = $this->groupWhoseCreatorLeft();
        $creator = User::factory()->create();
        $stayedGroup = Group::create(['name' => 'Criador ficou', 'created_by' => $creator->id]);
        $stayedGroup->members()->attach([$creator->id, $member->id]);

        $token = $this->tokenFor($member);
        $show = fn (Group $g) => $this->groupQueriesOf(
            fn () => $this->withToken($token)->getJson('/api/groups/'.$g->id)->assertStatus(200)
        );
        $membershipReads = fn (array $queries) => count(array_filter($queries, fn (string $sql) => str_contains($sql, 'ex_groups_members')));
        $show($stayedGroup); // aquece

        $this->assertSame(count($show($stayedGroup)), count($show($leftGroup)));

        // Sem criador registrado não há e-mail a proteger: a leitura de membros que checa o criador não roda.
        $noCreatorGroup = Group::create(['name' => 'Sem criador registrado']);
        $noCreatorGroup->members()->attach($member->id);
        $this->assertSame(
            $membershipReads($show($stayedGroup)) - 1,
            $membershipReads($show($noCreatorGroup))
        );
    }
}
