<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsGroupInvitations;
use Tests\TestCase;

/**
 * Convites de um grupo, vistos por quem é membro dele (feature
 * 20261103-convite-pendente-no-grupo, plan §4): a lista de pendentes e, na TASK-574, o
 * cancelamento. Quem tem convite ainda não é membro e, para estas rotas, é um estranho.
 */
class GroupInvitationControllerTest extends TestCase
{
    use BuildsGroupInvitations;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        GroupInvitation::flushEventListeners();

        parent::tearDown();
    }

    private function as(User $user, string $method, string $url, array $payload = []): TestResponse
    {
        return $this->withToken(auth('api')->login($user))->json($method, $url, $payload);
    }

    /** @return array{0: Group, 1: User} o grupo "Casa" e o único membro */
    private function groupWithMember(string $name = 'Casa'): array
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => $name]);
        $group->members()->attach($member->id);

        return [$group, $member];
    }

    private function list(User $user, int|string $groupId): TestResponse
    {
        return $this->as($user, 'GET', "/api/groups/{$groupId}/invitations");
    }

    // --- GET /api/groups/{groupId}/invitations ---------------------------

    public function test_listing_requires_authentication(): void
    {
        $this->getJson('/api/groups/1/invitations')->assertStatus(401);
    }

    public function test_an_empty_list_is_an_empty_array(): void
    {
        [$group, $member] = $this->groupWithMember();

        $this->list($member, $group->id)->assertOk()->assertExactJson([]);
    }

    public function test_a_member_sees_the_pending_invitations_of_the_group_in_id_order(): void
    {
        [$group, $member] = $this->groupWithMember();
        $ana = User::factory()->create();
        $bruno = User::factory()->create();
        $first = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $member->id]);
        $second = $this->makeInvitation($group, $bruno, GroupInvitation::STATUS_PENDING, ['invited_by' => $member->id]);

        $response = $this->list($member, $group->id)->assertOk();

        $this->assertSame([$first->id, $second->id], collect($response->json())->pluck('id')->all());
        $this->assertSame([$ana->email, $bruno->email], collect($response->json())->pluck('email')->all());
    }

    public function test_any_member_sees_them_not_only_the_one_who_invited(): void
    {
        [$group, $inviter] = $this->groupWithMember();
        $other = User::factory()->create();
        $group->members()->attach($other->id);
        $ana = User::factory()->create();
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);

        $response = $this->list($other, $group->id)->assertOk();

        $this->assertSame([$invitation->id], collect($response->json())->pluck('id')->all());
    }

    public function test_only_open_invitations_are_listed(): void
    {
        [$group, $member] = $this->groupWithMember();
        $pending = $this->makeInvitation($group, User::factory()->create());
        $this->makeExpiredInvitation($group, User::factory()->create(), $member);
        $this->makeInvitation($group, User::factory()->create(), GroupInvitation::STATUS_ACCEPTED);
        $this->makeInvitation($group, User::factory()->create(), GroupInvitation::STATUS_DECLINED);
        $this->makeInvitation($group, User::factory()->create(), GroupInvitation::STATUS_CANCELLED);

        $response = $this->list($member, $group->id)->assertOk();

        $this->assertSame([$pending->id], collect($response->json())->pluck('id')->all());
    }

    public function test_invitations_of_another_group_do_not_appear(): void
    {
        [$group, $member] = $this->groupWithMember('Casa');
        [$otherGroup] = $this->groupWithMember('Viagem');
        $mine = $this->makeInvitation($group, User::factory()->create());
        $this->makeInvitation($otherGroup, User::factory()->create());

        $response = $this->list($member, $group->id)->assertOk();

        $this->assertSame([$mine->id], collect($response->json())->pluck('id')->all());
    }

    public function test_the_payload_has_exactly_the_agreed_keys(): void
    {
        // Relógio fixo: o convite vence em 2026-11-10 12:00 (7 dias depois de 11-03) e o teste não
        // pode depender do dia em que roda; o `tearDown` desfaz.
        Carbon::setTestNow('2026-11-03 12:00:00');
        $inviter = User::factory()->create(['name' => 'Convidante']);
        $group = Group::create(['name' => 'Casa']);
        $group->members()->attach($inviter->id);
        $ana = User::factory()->create(['name' => 'Ana Convidada']);
        $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, [
            'invited_by' => $inviter->id,
            'expires_at' => '2026-11-10 12:00:00',
        ]);

        $response = $this->list($inviter, $group->id)->assertOk();

        $item = $response->json(0);
        $this->assertSame(['id', 'email', 'invited_by', 'expires_at'], array_keys($item));
        $this->assertSame($ana->email, $item['email']);
        $this->assertSame(['id' => $inviter->id, 'name' => 'Convidante'], $item['invited_by']);
        $this->assertSame('2026-11-10T12:00:00.000000Z', $item['expires_at']);
        // O e-mail de quem convidou e o nome do convidado não saem (só o e-mail que o membro digitou).
        $body = $response->getContent();
        $this->assertStringNotContainsString($inviter->email, $body);
        $this->assertStringNotContainsString('Ana Convidada', $body);
    }

    public function test_an_invitation_whose_inviter_no_longer_exists_has_a_null_inviter(): void
    {
        [$group, $member] = $this->groupWithMember();
        $this->makeInvitation($group, User::factory()->create(), GroupInvitation::STATUS_PENDING, ['invited_by' => null]);

        $item = $this->list($member, $group->id)->assertOk()->json(0);

        $this->assertNull($item['invited_by']);
    }

    // --- Quem não é membro leva 404 ---------------------------------------

    /** @return array<string, array{0: string}> */
    public static function requestersWhoCannotSeeTheInvitations(): array
    {
        return [
            'grupo inexistente' => ['missing'],
            'grupo excluído' => ['deleted'],
            'membro de outro grupo' => ['outsider'],
            'convidado que ainda não aceitou' => ['pending_invitee'],
            'ex-membro' => ['former_member'],
            'id do grupo seguido de texto' => ['suffix'],
        ];
    }

    /**
     * Para estas rotas o convidado pendente é um estranho (R10): o mesmo 404, com o mesmo
     * corpo de um grupo que não existe, em vez de 403, para não confirmar o grupo.
     *
     * @dataProvider requestersWhoCannotSeeTheInvitations
     */
    public function test_whoever_is_not_a_member_gets_the_same_404_as_for_a_missing_group(string $kind): void
    {
        // Em modo debug o corpo do erro traz `file`, `line` e `trace`; produção roda com APP_DEBUG=false.
        config(['app.debug' => false]);

        [$group, $member] = $this->groupWithMember();
        // O estranho é membro de OUTRO grupo: o caso realista de IDOR, e o que pega uma
        // autorização que só confira "é membro de algum grupo".
        [, $stranger] = $this->groupWithMember('Viagem');
        $invitee = User::factory()->create();
        $former = User::factory()->create();
        // Há sempre um convite aberto no grupo: sem a autorização a resposta seria 200 com dados.
        $this->makeInvitation($group, User::factory()->create(), GroupInvitation::STATUS_PENDING, ['invited_by' => $member->id]);

        [$requester, $groupId] = match ($kind) {
            'missing' => [$member, 999999999],
            'deleted' => (function () use ($group, $member) {
                $group->update(['deleted' => true]);

                return [$member, $group->id];
            })(),
            'outsider' => [$stranger, $group->id],
            'pending_invitee' => (function () use ($group, $invitee, $member) {
                $this->makeInvitation($group, $invitee, GroupInvitation::STATUS_PENDING, ['invited_by' => $member->id]);

                return [$invitee, $group->id];
            })(),
            'former_member' => (function () use ($group, $former) {
                $group->members()->attach($former->id);
                $group->members()->detach($former->id);

                return [$former, $group->id];
            })(),
            'suffix' => [$member, $group->id.'abc'],
        };
        $baseline = $this->list($requester, 999999999)->assertStatus(404);

        $response = $this->list($requester, $groupId);

        $response->assertStatus(404);
        $this->assertSame($baseline->getContent(), $response->getContent());
    }

    // --- DELETE /api/groups/{groupId}/invitations/{invitationId} ---------

    private function cancel(User $user, int|string $groupId, int|string $invitationId): TestResponse
    {
        return $this->as($user, 'DELETE', "/api/groups/{$groupId}/invitations/{$invitationId}");
    }

    /** O que um cancelamento indevido não pode mexer: nenhuma linha de convite (nem o `updated_at`). */
    private function invitationRows(): array
    {
        return DB::table('ex_group_invitations')->orderBy('id')->get()->all();
    }

    /** Grupo "Casa" criado por `$creator`, com `$inviter` (membro comum) e um convite dele para `$ana`. */
    private function groupWithCreatorAndInviter(): array
    {
        $creator = User::factory()->create();
        $inviter = User::factory()->create();
        $ana = User::factory()->create();
        $group = Group::create(['name' => 'Casa', 'created_by' => $creator->id]);
        $group->members()->attach([$creator->id, $inviter->id]);
        $invitation = $this->makeInvitation($group, $ana, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);

        return [$group, $creator, $inviter, $ana, $invitation];
    }

    public function test_cancelling_requires_authentication(): void
    {
        $this->deleteJson('/api/groups/1/invitations/1')->assertStatus(401);
    }

    /** CA-6 e D7-A: quem convidou cancela. Cancelar é `UPDATE`, não apaga a linha (Constitution §6.0). */
    public function test_the_one_who_invited_can_cancel_and_the_row_is_kept(): void
    {
        [$group, , $inviter, $ana, $invitation] = $this->groupWithCreatorAndInviter();
        $rowsBefore = DB::table('ex_group_invitations')->count();

        $response = $this->cancel($inviter, $group->id, $invitation->id);

        $response->assertOk()->assertExactJson(['message' => 'Convite cancelado.']);
        $fresh = $invitation->fresh();
        $this->assertSame(GroupInvitation::STATUS_CANCELLED, $fresh->status);
        $this->assertNotNull($fresh->responded_at);
        $this->assertSame($rowsBefore, DB::table('ex_group_invitations')->count());
        $this->list($inviter, $group->id)->assertOk()->assertExactJson([]);
        $this->as($ana, 'GET', '/api/me/invitations')->assertOk()->assertExactJson([]);
        $this->assertSame(0, Notification::count());
    }

    public function test_the_creator_can_cancel_an_invitation_made_by_someone_else(): void
    {
        [$group, $creator, , , $invitation] = $this->groupWithCreatorAndInviter();

        $this->cancel($creator, $group->id, $invitation->id)->assertOk();

        $this->assertSame(GroupInvitation::STATUS_CANCELLED, $invitation->fresh()->status);
    }

    public function test_a_common_member_who_did_not_invite_gets_403_and_nothing_changes(): void
    {
        [$group, , , , $invitation] = $this->groupWithCreatorAndInviter();
        $bystander = User::factory()->create();
        $group->members()->attach($bystander->id);
        $before = $this->invitationRows();

        $this->cancel($bystander, $group->id, $invitation->id)->assertStatus(403);

        $this->assertEquals($before, $this->invitationRows());
        $this->assertSame(GroupInvitation::STATUS_PENDING, $invitation->fresh()->status);
    }

    public function test_in_a_group_without_a_creator_only_the_one_who_invited_can_cancel(): void
    {
        [$group, $inviter] = $this->groupWithMember(); // `Group::create` sem `created_by`
        $bystander = User::factory()->create();
        $group->members()->attach($bystander->id);
        $invitation = $this->makeInvitation($group, User::factory()->create(), GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);

        $this->cancel($bystander, $group->id, $invitation->id)->assertStatus(403);
        $this->assertSame(GroupInvitation::STATUS_PENDING, $invitation->fresh()->status);

        $this->cancel($inviter, $group->id, $invitation->id)->assertOk();
        $this->assertSame(GroupInvitation::STATUS_CANCELLED, $invitation->fresh()->status);
    }

    public function test_when_the_inviter_no_longer_exists_only_the_creator_can_cancel(): void
    {
        [$group, $creator, $inviter] = $this->groupWithCreatorAndInviter();
        $orphan = $this->makeInvitation($group, User::factory()->create(), GroupInvitation::STATUS_PENDING, ['invited_by' => null]);

        $this->cancel($inviter, $group->id, $orphan->id)->assertStatus(403);
        $this->assertSame(GroupInvitation::STATUS_PENDING, $orphan->fresh()->status);

        $this->cancel($creator, $group->id, $orphan->id)->assertOk();
        $this->assertSame(GroupInvitation::STATUS_CANCELLED, $orphan->fresh()->status);
    }

    /** O `UPDATE` precisa do `id`: cancelar um convite não pode fechar os outros do grupo. */
    public function test_cancelling_one_invitation_leaves_the_others_pending(): void
    {
        [$group, , $inviter, , $first] = $this->groupWithCreatorAndInviter();
        $second = $this->makeInvitation($group, User::factory()->create(), GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);

        $this->cancel($inviter, $group->id, $second->id)->assertOk();

        $this->assertSame(GroupInvitation::STATUS_PENDING, $first->fresh()->status);
        $this->assertSame(GroupInvitation::STATUS_CANCELLED, $second->fresh()->status);
        $listed = $this->list($inviter, $group->id)->assertOk()->json();
        $this->assertSame([$first->id], collect($listed)->pluck('id')->all());
    }

    public function test_after_cancelling_the_invitee_cannot_accept_and_can_be_invited_again(): void
    {
        [$group, , $inviter, $ana, $invitation] = $this->groupWithCreatorAndInviter();
        $this->cancel($inviter, $group->id, $invitation->id)->assertOk();

        $this->as($ana, 'POST', "/api/me/invitations/{$invitation->id}/accept")->assertStatus(404);
        $this->assertFalse($group->members()->where('user_id', $ana->id)->exists());

        $this->as($inviter, 'POST', "/api/groups/{$group->id}/members", ['email' => $ana->email])
            ->assertStatus(201)
            ->assertJsonPath('pending', true);
        $this->assertSame(1, GroupInvitation::where('group_id', $group->id)->where('user_id', $ana->id)->count());
        $this->assertSame(GroupInvitation::STATUS_PENDING, $invitation->fresh()->status);
    }

    /** @return array<string, array{0: string}> */
    public static function cancellationsThatMustBe404(): array
    {
        return [
            'membro de outro grupo' => ['stranger'],
            'o próprio convidado' => ['invitee'],
            'grupo excluído' => ['deleted_group'],
            'grupo inexistente' => ['missing_group'],
            'convite de outro grupo' => ['other_group_invitation'],
            'convite já aceito' => [GroupInvitation::STATUS_ACCEPTED],
            'convite recusado' => [GroupInvitation::STATUS_DECLINED],
            'convite já cancelado' => [GroupInvitation::STATUS_CANCELLED],
            'convite vencido' => ['expired'],
            'convite já respondido, pedido por quem não convidou' => ['answered_by_a_bystander'],
            'convite inexistente' => ['missing_invitation'],
            'id do convite seguido de texto' => ['suffix_invitation'],
            'id do grupo seguido de texto' => ['suffix_group'],
        ];
    }

    /**
     * O mesmo 404, com o mesmo corpo, e nada muda. Nos cenários de estado quem pede é quem
     * convidou, que teria permissão: o 404 vem do estado do convite, não da autorização.
     *
     * @dataProvider cancellationsThatMustBe404
     */
    public function test_a_cancellation_that_cannot_happen_gives_the_same_404_and_changes_nothing(string $kind): void
    {
        // Em modo debug o corpo do erro traz `file`, `line` e `trace`; produção roda com APP_DEBUG=false.
        config(['app.debug' => false]);

        [$group, , $inviter, $ana, $invitation] = $this->groupWithCreatorAndInviter();
        [$otherGroup, $otherMember] = $this->groupWithMember('Viagem');
        $otherGroup->members()->attach($inviter->id); // quem convida é membro dos dois grupos
        $foreign = $this->makeInvitation($otherGroup, User::factory()->create(), GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);

        [$requester, $groupId, $invitationId] = match ($kind) {
            'stranger' => [$otherMember, $group->id, $invitation->id],
            'invitee' => [$ana, $group->id, $invitation->id],
            'deleted_group' => (function () use ($group, $inviter, $invitation) {
                $group->update(['deleted' => true]);

                return [$inviter, $group->id, $invitation->id];
            })(),
            'missing_group' => [$inviter, 999999999, $invitation->id],
            'other_group_invitation' => [$inviter, $group->id, $foreign->id],
            GroupInvitation::STATUS_ACCEPTED,
            GroupInvitation::STATUS_DECLINED,
            GroupInvitation::STATUS_CANCELLED => (function () use ($group, $inviter, $kind) {
                $answered = $this->makeInvitation($group, User::factory()->create(), $kind, ['invited_by' => $inviter->id]);

                return [$inviter, $group->id, $answered->id];
            })(),
            'expired' => [$inviter, $group->id, $this->makeExpiredInvitation($group, User::factory()->create(), $inviter)->id],
            // O estado vem antes da permissão (plan §4): quem não convidou e pede um convite já
            // respondido leva 404, e não 403, que confirmaria que o convite existe.
            'answered_by_a_bystander' => (function () use ($group, $inviter) {
                $bystander = User::factory()->create();
                $group->members()->attach($bystander->id);
                $answered = $this->makeInvitation($group, User::factory()->create(), GroupInvitation::STATUS_ACCEPTED, ['invited_by' => $inviter->id]);

                return [$bystander, $group->id, $answered->id];
            })(),
            'missing_invitation' => [$inviter, $group->id, 999999999],
            'suffix_invitation' => [$inviter, $group->id, $invitation->id.'abc'],
            'suffix_group' => [$inviter, $group->id.'abc', $invitation->id],
        };
        $baseline = $this->cancel($requester, 999999999, 999999999)->assertStatus(404);
        $before = $this->invitationRows();

        $response = $this->cancel($requester, $groupId, $invitationId);

        $response->assertStatus(404);
        $this->assertSame($baseline->getContent(), $response->getContent());
        $this->assertEquals($before, $this->invitationRows());
    }

    /**
     * Um aceite que entra entre a leitura e o `UPDATE` (a corrida do plan §4): o `UPDATE`
     * condicional em `pending` não acha mais a linha, dá 404 e **não** sobrescreve `accepted`
     * por `cancelled`. O evento `retrieved` vira o convite logo depois da leitura, o que
     * reproduz a corrida de forma determinística.
     */
    public function test_an_acceptance_that_sneaks_in_before_the_update_is_not_overwritten(): void
    {
        [$group, , $inviter, , $invitation] = $this->groupWithCreatorAndInviter();
        GroupInvitation::retrieved(function (GroupInvitation $retrieved) {
            DB::table('ex_group_invitations')->where('id', $retrieved->id)->update(['status' => GroupInvitation::STATUS_ACCEPTED]);
        });

        $this->cancel($inviter, $group->id, $invitation->id)->assertStatus(404);

        $this->assertSame(
            GroupInvitation::STATUS_ACCEPTED,
            DB::table('ex_group_invitations')->where('id', $invitation->id)->value('status')
        );
    }
}
