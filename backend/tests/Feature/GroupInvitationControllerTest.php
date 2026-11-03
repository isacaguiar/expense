<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
}
