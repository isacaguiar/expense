<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsPurchaseScenarios;
use Tests\TestCase;

class GroupMemberControllerTest extends TestCase
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

    public function test_member_can_add_member_to_group(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $existingUser = User::factory()->create();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups/'.$group->id.'/members', ['email' => $existingUser->email]);

        $response->assertStatus(201);
        $this->assertTrue($group->members()->where('user_id', $existingUser->id)->exists());
    }

    public function test_non_member_cannot_add_member_to_group(): void
    {
        Mail::fake();

        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $newMemberEmail = 'novo.membro.'.uniqid().'@example.com';

        $response = $this->withToken($this->tokenFor($outsider))
            ->postJson('/api/groups/'.$group->id.'/members', ['email' => $newMemberEmail]);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('ex_users', ['email' => $newMemberEmail]);
        $this->assertSame(0, $group->members()->count());
        Mail::assertNothingSent();
    }

    public function test_cannot_add_member_to_deleted_group(): void
    {
        Mail::fake();

        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'deleted' => true]);
        $group->members()->attach($member->id);
        $newMemberEmail = 'novo.membro.'.uniqid().'@example.com';

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups/'.$group->id.'/members', ['email' => $newMemberEmail]);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('ex_users', ['email' => $newMemberEmail]);
        $this->assertSame(1, $group->members()->count());
        Mail::assertNothingSent();
    }

    // --- GET /api/groups/{groupId}/members (item 085: só quem é membro lê a lista) ---

    public function test_non_member_cannot_list_members(): void
    {
        $member = User::factory()->create([
            'pix' => 'pix-do-membro@example.com',
            'whatsapp' => '5511999990001',
        ]);
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($outsider))
            ->getJson('/api/groups/'.$group->id.'/members');

        $response->assertStatus(404);

        // O corpo da resposta não pode carregar nenhum dado do membro.
        $body = $response->getContent();
        $this->assertStringNotContainsString($member->email, $body);
        $this->assertStringNotContainsString('pix-do-membro@example.com', $body);
        $this->assertStringNotContainsString('5511999990001', $body);
    }

    public function test_member_can_list_members(): void
    {
        $member = User::factory()->create();
        $colleague = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$member->id, $colleague->id]);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups/'.$group->id.'/members');

        $response->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonFragment(['id' => $member->id, 'email' => $member->email])
            ->assertJsonFragment(['id' => $colleague->id, 'email' => $colleague->email]);
    }

    public function test_unauthenticated_cannot_list_members(): void
    {
        $group = Group::create(['name' => 'Grupo de teste']);

        $this->getJson('/api/groups/'.$group->id.'/members')->assertStatus(401);
    }

    // TASK-477 (R2 do specify): para quem não é membro, "o grupo não existe" e "o grupo
    // existe, mas não é seu" têm de ser indistinguíveis, no status e no corpo. Senão a
    // checagem de membro continuaria confirmando a existência do grupo.
    public function test_unknown_group_and_foreign_group_are_indistinguishable(): void
    {
        // Em modo debug o corpo do erro traz `file`, `line` e `trace`, que diferem por
        // construção; o phpunit.xml não fixa APP_DEBUG, então o teste fixa aqui.
        config(['app.debug' => false]);

        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $foreignGroup = Group::create(['name' => 'Grupo alheio']);
        $foreignGroup->members()->attach($member->id);
        $unknownId = Group::max('id') + 1000;
        $token = $this->tokenFor($outsider);

        $unknown = $this->withToken($token)->getJson('/api/groups/'.$unknownId.'/members');
        $foreign = $this->withToken($token)->getJson('/api/groups/'.$foreignGroup->id.'/members');

        $this->assertSame(404, $unknown->getStatusCode());
        $this->assertSame($unknown->getStatusCode(), $foreign->getStatusCode());
        $this->assertSame(
            $unknown->getContent(),
            $foreign->getContent(),
            'o corpo do 404 revela se o grupo existe'
        );
    }

    // TASK-478 (R7 do specify, decisão D1 = B): a leitura de grupo excluído logicamente
    // segue como as demais leituras (`show`, `indexByGroup`, `summary`): o membro recebe
    // 200. Só a escrita (`store`) responde 404 a grupo excluído.
    public function test_member_of_deleted_group_still_lists_members(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo excluído', 'deleted' => true]);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups/'.$group->id.'/members');

        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonFragment(['id' => $member->id, 'email' => $member->email]);
    }

    // TASK-478 (R3 e R8): o payload de quem é membro não muda. Trava o que as telas
    // leem e o que NUNCA pode sair (`$hidden` do User). Não é um snapshot exato: uma
    // coluna nova em ex_users não deve quebrar este teste.
    public function test_member_payload_keeps_the_fields_the_web_reads(): void
    {
        $member = User::factory()->create([
            'pix' => 'pix-do-membro@example.com',
            'whatsapp' => '5511999990001',
            'google_id' => 'google-id-do-membro',
            'photo_path' => 'avatars/foto-do-membro.jpg',
        ]);
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups/'.$group->id.'/members');

        $response->assertStatus(200)
            ->assertJsonStructure(['*' => ['id', 'name', 'email', 'pix', 'whatsapp', 'avatar_url']])
            ->assertJsonFragment(['pix' => 'pix-do-membro@example.com', 'whatsapp' => '5511999990001']);

        $item = $response->json('0');
        foreach (['password', 'remember_token', 'google_id', 'photo_path'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $item, "o campo {$hidden} não pode sair na lista de membros");
        }
    }

    // --- DELETE /api/groups/{groupId}/members/{userId} (item 086: sair do grupo e remover membro) ---
    //
    // Regras em docs/feature/concluidas/202610/20261030-saida-e-remocao-de-membro/specify.md (R1 a R9, D2, D3-B, D5).
    // "Agora" fixo em outubro/2026 e closing_day nulo (competência = mês calendário).

    private function deleteMember(User $actor, Group $group, int|string|null $targetId = null)
    {
        return $this->withToken($this->tokenFor($actor))
            ->deleteJson('/api/groups/'.$group->id.'/members/'.($targetId ?? $actor->id));
    }

    /**
     * Grupo com `$members` membros em que o primeiro (`users[0]`) é o criador.
     *
     * @return array{group: Group, users: list<User>}
     */
    private function makeOwnedGroup(int $members): array
    {
        $scenario = $this->makeGroup($members);
        $scenario['group']->update(['created_by' => $scenario['users'][0]->id]);

        return $scenario;
    }

    /** @return array<string, int> contagens que a saída de um membro NUNCA pode mexer (R3) */
    private function historyCounts(): array
    {
        return [
            'ex_expenses' => DB::table('ex_expenses')->count(),
            'ex_quotas' => DB::table('ex_quotas')->count(),
            'ex_expenses_payers' => DB::table('ex_expenses_payers')->count(),
            'ex_group_cycle_snapshots' => DB::table('ex_group_cycle_snapshots')->count(),
            'ex_groups' => DB::table('ex_groups')->count(),
        ];
    }

    private function visibleGroupIds(User $user): array
    {
        $token = $this->tokenFor($user);

        $listed = collect($this->withToken($token)->getJson('/api/groups')->assertOk()->json())->pluck('id')->all();
        $overview = collect($this->withToken($token)->getJson('/api/me/overview')->assertOk()->json('groups'))
            ->pluck('group.id')->all();

        $this->assertSame($listed, $overview, 'GET /groups e GET /me/overview têm de enxergar os mesmos grupos');

        return $listed;
    }

    // CA-1
    public function test_a_member_can_leave_and_the_group_history_stays_intact(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');

        ['group' => $group, 'users' => [$creditor, $leaver, $other]] = $this->makeGroup(3);
        $this->makePurchase($group, $creditor, [$creditor, $leaver, $other], 'IN_INSTALLMENTS', 300.00, '2026-10-10', 3);
        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-09-01',
            'cycle_end' => '2026-09-30',
            'totals' => ['total' => 100, 'paid' => 100, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'settlements' => [],
            'settled_at' => now(),
        ]);

        $this->assertContains($group->id, $this->visibleGroupIds($leaver));
        $before = $this->historyCounts();

        $this->deleteMember($leaver, $group)
            ->assertStatus(200)
            ->assertExactJson(['message' => 'Você saiu do grupo.']);

        $this->assertDatabaseMissing('ex_groups_members', ['group_id' => $group->id, 'user_id' => $leaver->id]);
        $this->assertSame(2, $group->members()->count());
        $this->assertSame($before, $this->historyCounts());
        $this->assertNotContains($group->id, $this->visibleGroupIds($leaver));
        // quem ficou continua vendo o grupo e a dívida
        $this->assertContains($group->id, $this->visibleGroupIds($creditor));
    }

    // CA-2 (R1): grupo inexistente, alheio e excluído saem do MESMO abort(404).
    public function test_leaving_answers_the_same_404_for_an_unknown_foreign_and_deleted_group(): void
    {
        // Em modo debug o corpo do erro traz `file`, `line` e `trace`, que diferem por construção.
        config(['app.debug' => false]);

        $caller = User::factory()->create();
        $other = User::factory()->create();
        $foreign = Group::create(['name' => 'Grupo alheio']);
        $foreign->members()->attach($other->id);
        $deleted = Group::create(['name' => 'Grupo excluído', 'deleted' => true]);
        $deleted->members()->attach([$caller->id, $other->id]);
        $unknownId = Group::max('id') + 1000;

        $unknown = $this->withToken($this->tokenFor($caller))->deleteJson("/api/groups/{$unknownId}/members/{$caller->id}");
        $notMember = $this->deleteMember($caller, $foreign);
        // Mirando um membro REAL do grupo alheio: o 404 tem de vir de "você não é membro",
        // não de "o alvo não é membro" (senão o chamador de fora receberia 403 e saberia que o grupo existe).
        $notMemberTargetingAMember = $this->deleteMember($caller, $foreign, $other->id);
        $deletedGroup = $this->deleteMember($caller, $deleted);

        $this->assertSame(404, $unknown->getStatusCode());
        foreach ([$notMember, $notMemberTargetingAMember, $deletedGroup] as $response) {
            $this->assertSame(404, $response->getStatusCode());
            $this->assertSame($unknown->getContent(), $response->getContent(), 'o corpo do 404 revela se o grupo existe');
        }

        // nada mudou
        $this->assertTrue($deleted->members()->where('user_id', $caller->id)->exists());
        $this->assertTrue($foreign->members()->where('user_id', $other->id)->exists());
    }

    // CA-2 (R2): alvo que não é membro, antes mesmo de falar em permissão (404, não 403).
    public function test_removing_someone_who_is_not_a_member_returns_404(): void
    {
        ['group' => $group, 'users' => [$member, $colleague]] = $this->makeGroup(2);
        $outsider = User::factory()->create();

        $this->deleteMember($member, $group, $outsider->id)->assertStatus(404);
        $this->deleteMember($member, $group, 'abc')->assertStatus(404);
        // "5abc" não é o id 5: um `(int)` solto o trataria como a própria saída do usuário 5.
        $this->deleteMember($member, $group, $member->id.'abc')->assertStatus(404);
        $this->deleteMember($member, $group, User::max('id') + 1000)->assertStatus(404);

        $this->assertSame(2, $group->members()->count());
        $this->assertTrue($group->members()->where('user_id', $colleague->id)->exists());
    }

    // CA-2 (F7): repetir o DELETE de quem já saiu.
    public function test_repeating_the_delete_returns_404(): void
    {
        ['group' => $group, 'users' => [$leaver]] = $this->makeGroup(2);

        $this->deleteMember($leaver, $group)->assertStatus(200);
        $this->deleteMember($leaver, $group)->assertStatus(404);
    }

    public function test_unauthenticated_cannot_delete_a_member(): void
    {
        ['group' => $group, 'users' => [$member]] = $this->makeGroup(1);

        $this->deleteJson('/api/groups/'.$group->id.'/members/'.$member->id)->assertStatus(401);

        $this->assertTrue($group->members()->where('user_id', $member->id)->exists());
    }

    // CA-3 (D2-A)
    public function test_the_creator_can_remove_another_member(): void
    {
        ['group' => $group, 'users' => [$creator, $target, $bystander]] = $this->makeOwnedGroup(3);
        $before = $this->historyCounts();

        $this->deleteMember($creator, $group, $target->id)
            ->assertStatus(200)
            ->assertExactJson(['message' => 'Membro removido do grupo.']);

        $this->assertFalse($group->members()->where('user_id', $target->id)->exists());
        $this->assertTrue($group->members()->where('user_id', $bystander->id)->exists());
        $this->assertSame($before, $this->historyCounts());
        $this->assertNotContains($group->id, $this->visibleGroupIds($target));
    }

    public function test_a_regular_member_cannot_remove_another_member(): void
    {
        ['group' => $group, 'users' => [, $regular, $target]] = $this->makeOwnedGroup(3);

        $this->deleteMember($regular, $group, $target->id)->assertStatus(403);

        $this->assertSame(3, $group->members()->count());
    }

    public function test_a_group_without_a_creator_only_allows_leaving_by_yourself(): void
    {
        // Grupos antigos têm `created_by` nulo (§2.7): ninguém é "o criador".
        ['group' => $group, 'users' => [$first, $second]] = $this->makeGroup(2);
        $this->assertNull($group->created_by);

        $this->deleteMember($first, $group, $second->id)->assertStatus(403);
        $this->assertSame(2, $group->members()->count());

        $this->deleteMember($first, $group)->assertStatus(200);
        $this->assertSame(1, $group->members()->count());
    }

    public function test_a_creator_who_already_left_cannot_remove_anyone(): void
    {
        ['group' => $group, 'users' => [$creator, $second, $third]] = $this->makeOwnedGroup(3);

        $this->deleteMember($creator, $group)->assertStatus(200);
        // Já não é membro: o grupo "não existe" para ele (404), em vez de um 403 que confirmaria o grupo.
        $this->deleteMember($creator, $group, $second->id)->assertStatus(404);

        $this->assertTrue($group->members()->where('user_id', $second->id)->exists());
        $this->assertTrue($group->members()->where('user_id', $third->id)->exists());
    }

    // D4-A: o criador pode sair; o grupo segue existindo para quem ficou.
    public function test_the_creator_can_leave_their_own_group(): void
    {
        ['group' => $group, 'users' => [$creator, $second]] = $this->makeOwnedGroup(2);

        $this->deleteMember($creator, $group)->assertStatus(200);

        $this->assertFalse($group->fresh()->deleted);
        $this->assertContains($group->id, $this->visibleGroupIds($second));
    }

    // CA-4 (D3-B): quem só deve parcela sai livre; o credor continua vendo a dívida e confirmando.
    public function test_a_debtor_with_unpaid_installments_can_leave(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');

        ['group' => $group, 'users' => [$creditor, $debtor]] = $this->makeGroup(2);
        $expense = $this->makePurchase($group, $creditor, [$creditor, $debtor], 'IN_INSTALLMENTS', 200.00, '2026-12-10', 2);
        $before = $this->historyCounts();

        $this->deleteMember($debtor, $group)->assertStatus(200);

        $this->assertSame($before, $this->historyCounts());
        $this->assertTrue($expense->payers()->where('user_id', $debtor->id)->exists(), 'o pivô da despesa não é tocado');
    }

    public function test_a_creditor_with_unpaid_installments_of_others_cannot_leave(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');

        ['group' => $group, 'users' => [$creditor, $debtor]] = $this->makeGroup(2);
        $expense = $this->makePurchase($group, $creditor, [$creditor, $debtor], 'IN_INSTALLMENTS', 200.00, '2026-12-10', 2);
        $before = $this->historyCounts();

        $response = $this->deleteMember($creditor, $group)->assertStatus(422);

        $this->assertStringStartsWith('Você ainda tem', $response->json('message'));
        $response->assertJsonCount(1, 'blocking')
            ->assertJsonPath('blocking.0.expense_id', $expense->id)
            ->assertJsonPath('blocking.0.description', 'Compra de teste')
            ->assertJsonPath('blocking.0.reason', 'creditor_open_quotas');
        $this->assertTrue($group->members()->where('user_id', $creditor->id)->exists());
        $this->assertFalse($group->fresh()->deleted);
        $this->assertSame($before, $this->historyCounts());
    }

    public function test_a_participant_of_an_active_fixed_expense_cannot_leave(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');

        ['group' => $group, 'users' => [$creditor, $participant]] = $this->makeGroup(2);
        $expense = $this->makePurchase($group, $creditor, [$creditor, $participant], 'FIXED', 100.00, '2026-10-05');

        $this->deleteMember($participant, $group)
            ->assertStatus(422)
            ->assertJsonPath('blocking.0.expense_id', $expense->id)
            ->assertJsonPath('blocking.0.reason', 'active_fixed');

        $this->assertTrue($group->members()->where('user_id', $participant->id)->exists());
    }

    public function test_the_creditor_of_an_active_fixed_expense_with_other_participants_cannot_leave(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');

        ['group' => $group, 'users' => [$creditor, $participant]] = $this->makeGroup(2);
        $expense = $this->makePurchase($group, $creditor, [$creditor, $participant], 'FIXED', 100.00, '2026-10-05');

        $this->deleteMember($creditor, $group)
            ->assertStatus(422)
            ->assertJsonCount(1, 'blocking')
            ->assertJsonPath('blocking.0.expense_id', $expense->id)
            ->assertJsonPath('blocking.0.reason', 'active_fixed');
    }

    public function test_a_participant_of_a_fixed_expense_that_was_stopped_can_leave(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');

        ['group' => $group, 'users' => [$creditor, $participant]] = $this->makeGroup(2);
        $expense = $this->makePurchase($group, $creditor, [$creditor, $participant], 'FIXED', 100.00, '2026-10-05');
        $this->markPaid($expense);
        // `stopRecurrence` grava o dia 1 do mês de corte: a fixa já não entra no ciclo de outubro.
        $expense->update(['fixed_recurrence_ends_at' => '2026-10-01']);

        $this->deleteMember($participant, $group)->assertStatus(200);
    }

    // CA-4: o bloqueio vale igual quando é o criador quem remove, e então nada muda nem se notifica.
    public function test_the_creator_removing_a_blocked_member_also_gets_a_422_and_nothing_changes(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');

        ['group' => $group, 'users' => [$creator, $creditor, $debtor]] = $this->makeOwnedGroup(3);
        $this->makePurchase($group, $creditor, [$creditor, $debtor], 'IN_INSTALLMENTS', 200.00, '2026-12-10', 2);
        $before = $this->historyCounts();

        $response = $this->deleteMember($creator, $group, $creditor->id)->assertStatus(422);

        $this->assertStringStartsWith('Este membro ainda tem', $response->json('message'));
        $response->assertJsonCount(1, 'blocking');
        $this->assertTrue($group->members()->where('user_id', $creditor->id)->exists());
        $this->assertSame($before, $this->historyCounts());
        $this->assertSame(0, Notification::where('type', 'group_member_removed')->count());
    }

    // CA-7 (R8)
    public function test_a_removed_member_is_notified_but_one_who_leaves_by_choice_is_not(): void
    {
        ['group' => $group, 'users' => [$creator, $removed, $leaver]] = $this->makeOwnedGroup(3);
        $creator->update(['name' => 'Carla Criadora']);

        $this->deleteMember($creator, $group, $removed->id)->assertStatus(200);

        $rows = Notification::where('type', 'group_member_removed')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($removed->id, $rows->first()->user_id);
        $this->assertNull($rows->first()->group_id);
        $this->assertSame(['actorName' => 'Carla Criadora', 'groupName' => $group->name], $rows->first()->data);

        $this->deleteMember($leaver, $group)->assertStatus(200);

        $this->assertSame(1, Notification::where('type', 'group_member_removed')->count());
        $this->assertSame(0, Notification::where('user_id', $leaver->id)->count());
        $this->assertSame(0, Notification::where('user_id', $creator->id)->count());
    }

    // CA-7 (F5): o aviso é best effort, e a remoção já foi feita.
    public function test_a_notification_failure_does_not_stop_the_removal(): void
    {
        ['group' => $group, 'users' => [$creator, $removed]] = $this->makeOwnedGroup(2);

        Event::listen('eloquent.creating: '.Notification::class, fn () => throw new \RuntimeException('notificação fora do ar'));

        $this->deleteMember($creator, $group, $removed->id)
            ->assertStatus(200)
            ->assertExactJson(['message' => 'Membro removido do grupo.']);

        $this->assertFalse($group->members()->where('user_id', $removed->id)->exists());
    }

    // F6: o que o `destroy` checou antes do lock é conferido de novo depois dele, porque outra
    // requisição pode ter mudado o grupo enquanto esta esperava. A 1ª leitura de `Group` é a de
    // fora do lock e a 2ª é a de dentro; a mudança é feita entre as duas, como faria a outra requisição.
    private function afterTheLock(callable $change): void
    {
        $reads = 0;

        Event::listen('eloquent.retrieved: '.Group::class, function () use (&$reads, $change) {
            if (++$reads === 2) {
                $change();
            }
        });
    }

    public function test_a_target_who_left_while_the_request_waited_for_the_lock_gets_404(): void
    {
        ['group' => $group, 'users' => [$creator, $target]] = $this->makeOwnedGroup(2);
        $this->afterTheLock(fn () => $group->members()->detach($target->id));

        $this->deleteMember($creator, $group, $target->id)->assertStatus(404);

        $this->assertFalse($group->fresh()->deleted);
        $this->assertSame(0, Notification::where('type', 'group_member_removed')->count());
    }

    public function test_a_caller_removed_while_the_request_waited_for_the_lock_gets_404(): void
    {
        ['group' => $group, 'users' => [$creator, $target]] = $this->makeOwnedGroup(2);
        $this->afterTheLock(fn () => $group->members()->detach($creator->id));

        $this->deleteMember($creator, $group, $target->id)->assertStatus(404);

        $this->assertTrue($group->members()->where('user_id', $target->id)->exists());
        $this->assertSame(0, Notification::where('type', 'group_member_removed')->count());
    }

    // Dois processos de verdade não cabem num teste só; o que dá para provar é que a linha do grupo
    // é travada (`FOR UPDATE`) antes de a linha do pivô ser apagada.
    public function test_the_group_row_is_locked_for_update_before_the_membership_changes(): void
    {
        ['group' => $group, 'users' => [$first]] = $this->makeGroup(2);

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = strtolower($query->sql);
        });

        $this->deleteMember($first, $group)->assertStatus(200);

        $lockedAt = array_key_first(array_filter(
            $statements,
            fn (string $sql) => str_contains($sql, 'from `ex_groups`') && str_contains($sql, 'for update')
        ));
        $detachedAt = array_key_first(array_filter(
            $statements,
            fn (string $sql) => str_starts_with($sql, 'delete from `ex_groups_members`')
        ));

        $this->assertNotNull($lockedAt, 'a linha do grupo não foi travada com FOR UPDATE');
        $this->assertNotNull($detachedAt, 'a linha do pivô não foi apagada');
        $this->assertLessThan($detachedAt, $lockedAt);
    }

    // CA-9 (D5-A): exclusão lógica, nunca DELETE físico (Constitution §5.2).
    public function test_the_last_member_leaving_marks_the_group_as_deleted_without_deleting_rows(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');

        ['group' => $group, 'users' => [$only]] = $this->makeGroup(1);
        // Sozinho na despesa: não é credor "de outra pessoa", então a saída não é bloqueada.
        $this->makePurchase($group, $only, [$only], 'IN_INSTALLMENTS', 100.00, '2026-12-10', 2);
        $before = $this->historyCounts();

        $this->deleteMember($only, $group)->assertStatus(200);

        $this->assertSame(0, $group->members()->count());
        $this->assertTrue($group->fresh()->deleted);
        $this->assertSame($before, $this->historyCounts());
    }

    public function test_two_last_members_leaving_in_sequence_mark_the_group_as_deleted_once(): void
    {
        ['group' => $group, 'users' => [$first, $second]] = $this->makeGroup(2);
        $before = $this->historyCounts();

        $this->deleteMember($first, $group)->assertStatus(200);
        $this->assertFalse($group->fresh()->deleted, 'ainda há um membro');

        $this->deleteMember($second, $group)->assertStatus(200);
        $this->assertTrue($group->fresh()->deleted);

        $this->assertSame($before, $this->historyCounts());
        // com o grupo já excluído, repetir o DELETE é 404 (F7), não um segundo "marcar como excluído"
        $this->deleteMember($second, $group)->assertStatus(404);
    }
}
