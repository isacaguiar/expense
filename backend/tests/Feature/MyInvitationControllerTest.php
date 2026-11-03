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
 * Convites recebidos pelo usuário autenticado (feature 20261103-convite-pendente-no-grupo,
 * plan §3 e §5). Sempre escopado ao próprio usuário, como o `NotificationController`; quem
 * tem convite ainda não é membro, então nada do grupo além do nome pode sair daqui (R10).
 */
class MyInvitationControllerTest extends TestCase
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
}
