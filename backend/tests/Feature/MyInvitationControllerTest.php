<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsGroupInvitations;
use Tests\Concerns\CapturesTransactionQueries;
use Tests\TestCase;

/**
 * Convites recebidos pelo usuário autenticado (feature 20261103-convite-pendente-no-grupo,
 * plan §3 e §5). Sempre escopado ao próprio usuário, como o `NotificationController`; quem
 * tem convite ainda não é membro, então nada do grupo além do nome pode sair daqui (R10).
 */
class MyInvitationControllerTest extends TestCase
{
    use BuildsGroupInvitations;
    use CapturesTransactionQueries;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Notification::flushEventListeners();

        parent::tearDown();
    }

    private function as(User $user, string $method, string $url, array $payload = []): TestResponse
    {
        return $this->withToken(auth('api')->login($user))->json($method, $url, $payload);
    }

    /** @return array{0: Group, 1: User} o grupo e quem convida (único membro) */
    private function groupWithInviter(string $name = 'Casa'): array
    {
        $inviter = User::factory()->create();
        $group = Group::create(['name' => $name]);
        $group->members()->attach($inviter->id);

        return [$group, $inviter];
    }

    // --- GET /api/me/invitations -----------------------------------------

    public function test_listing_requires_authentication(): void
    {
        $this->getJson('/api/me/invitations')->assertStatus(401);
    }

    public function test_an_empty_list_is_an_empty_array(): void
    {
        $user = User::factory()->create();

        $this->as($user, 'GET', '/api/me/invitations')->assertOk()->assertExactJson([]);
    }

    public function test_it_lists_only_the_invitations_of_the_authenticated_user(): void
    {
        [$groupA, $inviterA] = $this->groupWithInviter('Casa');
        [$groupB, $inviterB] = $this->groupWithInviter('Viagem');
        $ana = User::factory()->create();
        $bruno = User::factory()->create();
        $mine = $this->makeInvitation($groupA, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviterA->id]);
        $this->makeInvitation($groupB, $bruno, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviterB->id]);

        $response = $this->as($ana, 'GET', '/api/me/invitations')->assertOk();

        $this->assertSame([$mine->id], collect($response->json())->pluck('id')->all());
    }

    public function test_only_open_invitations_are_listed(): void
    {
        $ana = User::factory()->create();
        [$groupPending] = $this->groupWithInviter('Pendente');
        [$groupExpired] = $this->groupWithInviter('Vencido');
        [$groupAccepted] = $this->groupWithInviter('Aceito');
        [$groupDeclined] = $this->groupWithInviter('Recusado');
        [$groupCancelled] = $this->groupWithInviter('Cancelado');
        $pending = $this->makeInvitation($groupPending, $ana);
        $this->makeExpiredInvitation($groupExpired, $ana);
        $this->makeInvitation($groupAccepted, $ana, GroupInvitation::STATUS_ACCEPTED);
        $this->makeInvitation($groupDeclined, $ana, GroupInvitation::STATUS_DECLINED);
        $this->makeInvitation($groupCancelled, $ana, GroupInvitation::STATUS_CANCELLED);

        $response = $this->as($ana, 'GET', '/api/me/invitations')->assertOk();

        $this->assertSame([$pending->id], collect($response->json())->pluck('id')->all());
    }

    public function test_an_invitation_that_expires_this_very_second_is_not_listed(): void
    {
        Carbon::setTestNow('2026-11-03 12:00:00');
        $ana = User::factory()->create();
        [$groupNow] = $this->groupWithInviter('Vence agora');
        [$groupLater] = $this->groupWithInviter('Vence em 1s');
        $this->makeInvitation($groupNow, $ana, GroupInvitation::STATUS_PENDING, ['expires_at' => '2026-11-03 12:00:00']);
        $later = $this->makeInvitation($groupLater, $ana, GroupInvitation::STATUS_PENDING, ['expires_at' => '2026-11-03 12:00:01']);

        $response = $this->as($ana, 'GET', '/api/me/invitations')->assertOk();

        $this->assertSame([$later->id], collect($response->json())->pluck('id')->all());
    }

    public function test_invitations_to_a_deleted_group_are_not_listed(): void
    {
        $ana = User::factory()->create();
        [$deleted] = $this->groupWithInviter('Excluído');
        [$alive] = $this->groupWithInviter('Vivo');
        $deleted->update(['deleted' => true]);
        $this->makeInvitation($deleted, $ana);
        $kept = $this->makeInvitation($alive, $ana);

        $response = $this->as($ana, 'GET', '/api/me/invitations')->assertOk();

        $this->assertSame([$kept->id], collect($response->json())->pluck('id')->all());
    }

    public function test_the_payload_names_the_group_and_who_invited(): void
    {
        $inviter = User::factory()->create(['name' => 'Convidante']);
        $group = Group::create(['name' => 'Casa']);
        $group->members()->attach($inviter->id);
        $ana = User::factory()->create();
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, [
            'invited_by' => $inviter->id,
            'expires_at' => '2026-11-10 12:00:00',
        ]);

        $item = $this->as($ana, 'GET', '/api/me/invitations')->assertOk()->json(0);

        $this->assertSame($invitation->id, $item['id']);
        $this->assertSame(['id' => $group->id, 'name' => 'Casa'], $item['group']);
        $this->assertSame(['id' => $inviter->id, 'name' => 'Convidante'], $item['invited_by']);
        $this->assertSame('2026-11-10T12:00:00.000000Z', $item['expires_at']);
    }

    public function test_an_invitation_whose_inviter_no_longer_exists_has_a_null_inviter(): void
    {
        [$group] = $this->groupWithInviter();
        $ana = User::factory()->create();
        $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => null]);

        $item = $this->as($ana, 'GET', '/api/me/invitations')->assertOk()->json(0);

        $this->assertNull($item['invited_by']);
    }

    /**
     * R10 e CA-7: quem ainda não é membro vê o nome do grupo e o nome de quem convidou, e
     * mais nada: nem e-mail de ninguém, nem a lista de membros, nem despesas ou saldos.
     */
    public function test_nothing_but_the_agreed_keys_leaves_for_someone_who_is_not_a_member_yet(): void
    {
        $inviter = User::factory()->create(['name' => 'Convidante']);
        $colleague = User::factory()->create(['name' => 'Colega do grupo']);
        $group = Group::create(['name' => 'Casa', 'description' => 'Contas secretas da casa']);
        $group->members()->attach([$inviter->id, $colleague->id]);
        $ana = User::factory()->create();
        $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);

        $response = $this->as($ana, 'GET', '/api/me/invitations')->assertOk();

        $item = $response->json(0);
        $this->assertSame(['id', 'group', 'invited_by', 'expires_at'], array_keys($item));
        $this->assertSame(['id', 'name'], array_keys($item['group']));
        $this->assertSame(['id', 'name'], array_keys($item['invited_by']));
        $body = $response->getContent();
        foreach ([$inviter->email, $colleague->email, $colleague->name, 'Contas secretas da casa', '"members"', '"email"'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_the_most_recently_invited_or_renewed_comes_first(): void
    {
        $ana = User::factory()->create();
        [$groupA] = $this->groupWithInviter('A');
        [$groupB] = $this->groupWithInviter('B');
        [$groupC] = $this->groupWithInviter('C');

        Carbon::setTestNow('2026-11-03 10:00:00');
        $a = $this->makeInvitation($groupA, $ana);
        Carbon::setTestNow('2026-11-03 11:00:00');
        $b = $this->makeInvitation($groupB, $ana);
        // O convite A é renovado depois: continua sendo a mesma linha (id e created_at antigos).
        Carbon::setTestNow('2026-11-03 12:00:00');
        $a->update(['expires_at' => now()->addDays(GroupInvitation::VALIDITY_DAYS)]);
        Carbon::setTestNow('2026-11-03 13:00:00');
        $c = $this->makeInvitation($groupC, $ana);

        $response = $this->as($ana, 'GET', '/api/me/invitations')->assertOk();

        $this->assertSame([$c->id, $a->id, $b->id], collect($response->json())->pluck('id')->all());
    }

    public function test_invitations_made_in_the_same_second_come_with_the_latest_id_first(): void
    {
        Carbon::setTestNow('2026-11-03 10:00:00');
        $ana = User::factory()->create();
        [$groupA] = $this->groupWithInviter('A');
        [$groupB] = $this->groupWithInviter('B');
        $first = $this->makeInvitation($groupA, $ana);
        $second = $this->makeInvitation($groupB, $ana);

        $response = $this->as($ana, 'GET', '/api/me/invitations')->assertOk();

        $this->assertSame([$second->id, $first->id], collect($response->json())->pluck('id')->all());
    }

    // --- POST /api/me/invitations/{id}/accept ----------------------------

    private function accept(User $user, int|string $invitationId): TestResponse
    {
        return $this->as($user, 'POST', "/api/me/invitations/{$invitationId}/accept");
    }

    /** O que um aceite indevido não pode mexer: vínculos, convites (com `updated_at`) e avisos. */
    private function membershipsInvitationsAndNotices(): array
    {
        return [
            DB::table('ex_groups_members')->orderBy('group_id')->orderBy('user_id')->get()->all(),
            DB::table('ex_group_invitations')->orderBy('id')->get()->all(),
            Notification::count(),
        ];
    }

    /**
     * O id da URL de um convite que `$ana` não pode aceitar nem recusar, de cada tipo (usado
     * pelo aceite e pela recusa). `$bruno` é a outra pessoa; `'deleted_group'` exclui o grupo
     * (o aceite dá 404; a recusa não consulta o grupo e continua valendo).
     */
    private function unavailableTarget(string $kind, Group $group, User $ana, User $bruno, User $inviter): int|string
    {
        return match ($kind) {
            'other' => $this->makeInvitation($group, $bruno, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id])->id,
            'expired' => $this->makeExpiredInvitation($group, $ana, $inviter)->id,
            GroupInvitation::STATUS_ACCEPTED,
            GroupInvitation::STATUS_DECLINED,
            GroupInvitation::STATUS_CANCELLED => $this->makeInvitation($group, $ana, $kind, ['invited_by' => $inviter->id])->id,
            'deleted_group' => (function () use ($group, $ana, $inviter) {
                $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);
                $group->update(['deleted' => true]);

                return $invitation->id;
            })(),
            'missing' => 999999999,
            'suffix' => $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id])->id.'abc',
        };
    }

    public function test_accepting_requires_authentication(): void
    {
        $this->postJson('/api/me/invitations/1/accept')->assertStatus(401);
    }

    public function test_accepting_makes_the_invitee_a_member_and_closes_the_invitation(): void
    {
        [$group, $inviter] = $this->groupWithInviter('Casa');
        $ana = User::factory()->create();
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);

        // Antes de aceitar o grupo não existe para ela (R10).
        $this->as($ana, 'GET', "/api/groups/{$group->id}/members")->assertStatus(404);

        $response = $this->accept($ana, $invitation->id);

        $response->assertOk()->assertExactJson([
            'message' => 'Você entrou no grupo.',
            'group' => ['id' => $group->id, 'name' => 'Casa'],
        ]);
        $this->assertTrue($group->members()->where('user_id', $ana->id)->exists());
        $fresh = $invitation->fresh();
        $this->assertSame(GroupInvitation::STATUS_ACCEPTED, $fresh->status);
        $this->assertNotNull($fresh->responded_at);

        $groups = $this->as($ana, 'GET', '/api/groups')->assertOk()->json();
        $this->assertSame([$group->id], collect($groups)->pluck('id')->all());
        $this->as($ana, 'GET', '/api/me/invitations')->assertOk()->assertExactJson([]);
        $members = $this->as($ana, 'GET', "/api/groups/{$group->id}/members")->assertOk()->json();
        $this->assertEqualsCanonicalizing([$inviter->id, $ana->id], collect($members)->pluck('id')->all());
    }

    /**
     * Quem tem vários convites aceita o grupo do convite que escolheu, e só ele. A busca do
     * grupo precisa do `id` do convite, não só do dono: sem o `id` ela devolveria o grupo do
     * primeiro convite da pessoa e a levaria para o grupo errado.
     */
    public function test_accepting_one_of_several_invitations_joins_that_group_only(): void
    {
        [$groupA, $inviterA] = $this->groupWithInviter('A');
        [$groupB, $inviterB] = $this->groupWithInviter('B');
        $ana = User::factory()->create();
        $first = $this->makeInvitation($groupA, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviterA->id]);
        $second = $this->makeInvitation($groupB, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviterB->id]);

        $this->accept($ana, $second->id)->assertOk()->assertJsonPath('group.id', $groupB->id);

        $this->assertTrue($groupB->members()->where('user_id', $ana->id)->exists());
        $this->assertFalse($groupA->members()->where('user_id', $ana->id)->exists());
        $this->assertSame(GroupInvitation::STATUS_ACCEPTED, $second->fresh()->status);
        $this->assertSame(GroupInvitation::STATUS_PENDING, $first->fresh()->status);
        $listed = $this->as($ana, 'GET', '/api/me/invitations')->assertOk()->json();
        $this->assertSame([$first->id], collect($listed)->pluck('id')->all());
    }

    public function test_accepting_notifies_the_inviter_with_the_group_and_nobody_else(): void
    {
        [$group, $inviter] = $this->groupWithInviter('Casa');
        $ana = User::factory()->create();
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);

        $this->accept($ana, $invitation->id)->assertOk();

        $rows = Notification::all();
        $this->assertCount(1, $rows);
        $this->assertSame('group_invitation_accepted', $rows->first()->type);
        $this->assertSame($inviter->id, $rows->first()->user_id);
        $this->assertSame($group->id, $rows->first()->group_id);
        $this->assertSame($ana->name, $rows->first()->data['actorName']);
        $this->assertSame($group->id, $rows->first()->data['groupId']);
        $this->assertSame('Casa', $rows->first()->data['groupName']);
    }

    /** @return array<string, array{0: string}> */
    public static function unavailableInvitations(): array
    {
        return [
            'convite de outra pessoa' => ['other'],
            'convite vencido' => ['expired'],
            'convite já aceito' => [GroupInvitation::STATUS_ACCEPTED],
            'convite recusado' => [GroupInvitation::STATUS_DECLINED],
            'convite cancelado' => [GroupInvitation::STATUS_CANCELLED],
            'grupo excluído' => ['deleted_group'],
            'id inexistente' => ['missing'],
            'id do convite seguido de texto' => ['suffix'],
        ];
    }

    /**
     * R7: tudo o que não dá para aceitar responde o **mesmo** 404, com o mesmo corpo, e não
     * mexe em nada. O id "5abc" existe de propósito: sem a checagem de dígitos o MySQL
     * compararia como 5 e aceitaria o convite.
     *
     * @dataProvider unavailableInvitations
     */
    public function test_an_unavailable_invitation_gives_the_same_404_and_changes_nothing(string $kind): void
    {
        // Em modo debug o corpo do erro traz `file`, `line` e `trace`, que diferem por
        // construção; produção roda com APP_DEBUG=false, então o teste fixa o mesmo aqui.
        config(['app.debug' => false]);

        [$group, $inviter] = $this->groupWithInviter('Casa');
        $ana = User::factory()->create();
        $bruno = User::factory()->create();

        $target = $this->unavailableTarget($kind, $group, $ana, $bruno, $inviter);
        $baseline = $this->accept($ana, 999999999)->assertStatus(404);
        $before = $this->membershipsInvitationsAndNotices();

        $response = $this->accept($ana, $target);

        $response->assertStatus(404);
        $this->assertSame($baseline->getContent(), $response->getContent());
        $this->assertEquals($before, $this->membershipsInvitationsAndNotices());
    }

    public function test_someone_who_is_already_a_member_gets_404_and_no_second_membership(): void
    {
        [$group, $inviter] = $this->groupWithInviter('Casa');
        $ana = User::factory()->create();
        $group->members()->attach($ana->id);
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);
        $before = $this->membershipsInvitationsAndNotices();

        $this->accept($ana, $invitation->id)->assertStatus(404);

        $this->assertEquals($before, $this->membershipsInvitationsAndNotices());
        $this->assertSame(1, $group->members()->where('user_id', $ana->id)->count());
        $this->assertSame(GroupInvitation::STATUS_PENDING, $invitation->fresh()->status);
    }

    public function test_accepting_twice_in_a_row_adds_one_membership_and_the_second_is_404(): void
    {
        [$group, $inviter] = $this->groupWithInviter('Casa');
        $ana = User::factory()->create();
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);

        $this->accept($ana, $invitation->id)->assertOk();
        $this->accept($ana, $invitation->id)->assertStatus(404);

        $this->assertSame(1, DB::table('ex_groups_members')->where('group_id', $group->id)->where('user_id', $ana->id)->count());
        $this->assertSame(1, Notification::where('type', 'group_invitation_accepted')->count());
    }

    /** @return array<string, array{0: string}> */
    public static function invitersWhoCannotBeNotified(): array
    {
        return [
            'quem convidou já saiu do grupo' => ['left'],
            'quem convidou não existe mais' => ['none'],
            'quem convidou é o próprio convidado' => ['self'],
        ];
    }

    /**
     * @dataProvider invitersWhoCannotBeNotified
     */
    public function test_the_invitee_still_joins_when_there_is_nobody_to_notify(string $case): void
    {
        [$group, $inviter] = $this->groupWithInviter('Casa');
        $ana = User::factory()->create();
        $stay = User::factory()->create();
        $group->members()->attach($stay->id);
        if ($case === 'left') {
            // Quem saiu não pode abrir o grupo: o aviso (com groupId) levaria a um 404.
            $group->members()->detach($inviter->id);
        }
        $invitedBy = match ($case) {
            'left' => $inviter->id,
            'none' => null,
            'self' => $ana->id,
        };
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $invitedBy]);

        $this->accept($ana, $invitation->id)->assertOk();

        $this->assertTrue($group->members()->where('user_id', $ana->id)->exists());
        $this->assertSame(0, Notification::count());
    }

    public function test_a_notifier_failure_does_not_break_the_acceptance(): void
    {
        Log::spy();
        [$group, $inviter] = $this->groupWithInviter('Casa');
        $ana = User::factory()->create();
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);
        Notification::creating(function () {
            throw new \RuntimeException('falha simulada ao gravar a notificação');
        });

        $this->accept($ana, $invitation->id)->assertOk();

        $this->assertTrue($group->members()->where('user_id', $ana->id)->exists());
        $this->assertSame(GroupInvitation::STATUS_ACCEPTED, $invitation->fresh()->status);
        $this->assertSame(0, Notification::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => ($context['type'] ?? null) === 'group_invitation_accepted')
            ->once();
    }

    /**
     * F2/CA-11: duas requisições simultâneas não cabem no PHPUnit (uma conexão só), então o
     * lock é provado pelo que ele deixa no SQL: trava o grupo e depois o convite, nessa
     * ordem, a mesma de `GroupInvitationService::invite` (ordem diferente dá deadlock).
     */
    public function test_accepting_locks_the_group_first_and_then_the_invitation(): void
    {
        [$group, $inviter] = $this->groupWithInviter('Casa');
        $ana = User::factory()->create();
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);
        $token = auth('api')->login($ana);

        DB::enableQueryLog();
        $this->withToken($token)->postJson("/api/me/invitations/{$invitation->id}/accept")->assertOk();
        $locks = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql) => str_contains($sql, ' for update'))
            ->values();
        DB::disableQueryLog();

        $this->assertCount(2, $locks);
        $this->assertStringContainsString('`ex_groups`', $locks[0]);
        $this->assertStringContainsString('`ex_group_invitations`', $locks[1]);
    }

    /**
     * A2 (revisão de segurança, TASK-576): em REPEATABLE READ a primeira leitura consistente de
     * uma transação abre o snapshot. Se a busca do convite rodasse dentro da transação, o
     * snapshot abriria antes de esperar o lock do grupo, e as leituras de "quem convidou ainda é
     * membro?" veriam o estado de antes da espera (um ex-membro seria avisado). Por isso a busca
     * roda antes, e a primeira consulta da transação é o próprio lock do grupo.
     */
    public function test_accepting_reads_nothing_inside_its_transaction_before_the_group_lock(): void
    {
        [$group, $inviter] = $this->groupWithInviter('Casa');
        $ana = User::factory()->create();
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);
        $token = auth('api')->login($ana);

        $queries = $this->queriesAfterTheTransactionOpens(
            fn () => $this->withToken($token)->postJson("/api/me/invitations/{$invitation->id}/accept")->assertOk()
        );

        $this->assertStringContainsString('`ex_groups`', $queries[0]);
        $this->assertStringContainsString(' for update', $queries[0]);
    }

    public function test_accepting_is_not_blocked_by_the_limit_of_created_groups(): void
    {
        $ana = User::factory()->create();
        foreach (['A', 'B', 'C'] as $name) {
            $own = Group::create(['name' => $name, 'created_by' => $ana->id]);
            $own->members()->attach($ana->id);
        }
        [$group, $inviter] = $this->groupWithInviter('Casa');
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);

        $this->accept($ana, $invitation->id)->assertOk();

        $this->assertTrue($group->members()->where('user_id', $ana->id)->exists());
    }

    // --- POST /api/me/invitations/{id}/decline ---------------------------

    private function decline(User $user, int|string $invitationId): TestResponse
    {
        return $this->as($user, 'POST', "/api/me/invitations/{$invitationId}/decline");
    }

    public function test_declining_requires_authentication(): void
    {
        $this->postJson('/api/me/invitations/1/decline')->assertStatus(401);
    }

    /** CA-5 e D6-A: recusar fecha o convite, não cria vínculo e não avisa ninguém. */
    public function test_declining_closes_the_invitation_without_a_membership_and_tells_nobody(): void
    {
        [$group, $inviter] = $this->groupWithInviter('Casa');
        $ana = User::factory()->create();
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);
        $pivotBefore = DB::table('ex_groups_members')->count();

        $response = $this->decline($ana, $invitation->id);

        $response->assertOk()->assertExactJson(['message' => 'Convite recusado.']);
        $fresh = $invitation->fresh();
        $this->assertSame(GroupInvitation::STATUS_DECLINED, $fresh->status);
        $this->assertNotNull($fresh->responded_at);
        $this->assertSame($pivotBefore, DB::table('ex_groups_members')->count());
        $this->assertFalse($group->members()->where('user_id', $ana->id)->exists());
        $this->assertSame(0, Notification::count());
        $this->as($ana, 'GET', '/api/me/invitations')->assertOk()->assertExactJson([]);
        $this->as($ana, 'GET', "/api/groups/{$group->id}/members")->assertStatus(404);
    }

    /**
     * Mesmos cenários do aceite, menos o do grupo excluído: recusar não consulta o grupo.
     *
     * @return array<string, array{0: string}>
     */
    public static function unavailableInvitationsToDecline(): array
    {
        $scenarios = self::unavailableInvitations();
        unset($scenarios['grupo excluído']);

        return $scenarios;
    }

    /**
     * R8: o que não dá para recusar responde o **mesmo** 404, com o mesmo corpo, e não mexe em
     * nada (inclusive o convite de outra pessoa, que continua pendente).
     *
     * @dataProvider unavailableInvitationsToDecline
     */
    public function test_an_unavailable_invitation_cannot_be_declined_and_changes_nothing(string $kind): void
    {
        // Em modo debug o corpo do erro traz `file`, `line` e `trace`; produção roda com APP_DEBUG=false.
        config(['app.debug' => false]);

        [$group, $inviter] = $this->groupWithInviter('Casa');
        $ana = User::factory()->create();
        $bruno = User::factory()->create();
        $target = $this->unavailableTarget($kind, $group, $ana, $bruno, $inviter);
        $baseline = $this->decline($ana, 999999999)->assertStatus(404);
        $before = $this->membershipsInvitationsAndNotices();

        $response = $this->decline($ana, $target);

        $response->assertStatus(404);
        $this->assertSame($baseline->getContent(), $response->getContent());
        $this->assertEquals($before, $this->membershipsInvitationsAndNotices());
    }

    /**
     * Quem tem vários convites responde um de cada vez: recusar o segundo não pode fechar o
     * primeiro (o `UPDATE` precisa do `id`, não só do dono).
     */
    public function test_declining_one_of_several_invitations_leaves_the_others_pending(): void
    {
        [$groupA, $inviterA] = $this->groupWithInviter('A');
        [$groupB, $inviterB] = $this->groupWithInviter('B');
        $ana = User::factory()->create();
        $first = $this->makeInvitation($groupA, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviterA->id]);
        $second = $this->makeInvitation($groupB, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviterB->id]);

        $this->decline($ana, $second->id)->assertOk();

        $this->assertSame(GroupInvitation::STATUS_PENDING, $first->fresh()->status);
        $this->assertSame(GroupInvitation::STATUS_DECLINED, $second->fresh()->status);
        $listed = $this->as($ana, 'GET', '/api/me/invitations')->assertOk()->json();
        $this->assertSame([$first->id], collect($listed)->pluck('id')->all());
    }

    public function test_declining_twice_in_a_row_the_second_is_404(): void
    {
        [$group, $inviter] = $this->groupWithInviter('Casa');
        $ana = User::factory()->create();
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);

        $this->decline($ana, $invitation->id)->assertOk();
        $this->decline($ana, $invitation->id)->assertStatus(404);

        $this->assertSame(GroupInvitation::STATUS_DECLINED, $invitation->fresh()->status);
    }

    public function test_a_declined_invitation_can_no_longer_be_accepted(): void
    {
        [$group, $inviter] = $this->groupWithInviter('Casa');
        $ana = User::factory()->create();
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);

        $this->decline($ana, $invitation->id)->assertOk();
        $this->accept($ana, $invitation->id)->assertStatus(404);

        $this->assertFalse($group->members()->where('user_id', $ana->id)->exists());
    }

    /**
     * O plan §3 não consulta o grupo ao recusar: a linha é do próprio convidado e não revela
     * nada. Este caso registra a escolha (o aceite, ao contrário, dá 404 com o grupo excluído).
     */
    public function test_declining_an_invitation_to_a_deleted_group_still_works(): void
    {
        [$group, $inviter] = $this->groupWithInviter('Casa');
        $ana = User::factory()->create();
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);
        $group->update(['deleted' => true]);

        $this->decline($ana, $invitation->id)->assertOk();

        $this->assertSame(GroupInvitation::STATUS_DECLINED, $invitation->fresh()->status);
    }

    /** D9-A e R13: quem recusou pode ser convidado de novo; o convite volta a `pending`. */
    public function test_after_declining_the_same_person_can_be_invited_again(): void
    {
        [$group, $inviter] = $this->groupWithInviter('Casa');
        $ana = User::factory()->create();
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);
        $this->decline($ana, $invitation->id)->assertOk();

        $this->as($inviter, 'POST', "/api/groups/{$group->id}/members", ['email' => $ana->email])
            ->assertStatus(201)
            ->assertJsonPath('pending', true);

        $this->assertSame(1, GroupInvitation::where('group_id', $group->id)->where('user_id', $ana->id)->count());
        $reopened = $invitation->fresh();
        $this->assertSame(GroupInvitation::STATUS_PENDING, $reopened->status);
        $this->assertNull($reopened->responded_at);
        $listed = $this->as($ana, 'GET', '/api/me/invitations')->assertOk()->json();
        $this->assertSame([$invitation->id], collect($listed)->pluck('id')->all());
    }
}
