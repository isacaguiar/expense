<?php

namespace Tests\Feature;

use App\Http\Controllers\GroupMemberResponsibleController;
use App\Models\Group;
use App\Models\GroupMemberResponsible;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `PUT /groups/{groupId}/members/{userId}/responsibles` (feature 20261109-responsavel-do-devedor,
 * plan §3 e §7): só o próprio devedor define a lista inteira de quem paga por ele; a lista
 * vazia desfaz tudo, e cada responsável novo é avisado (specify R2, R3, R4, R11, CA-1, CA-2, CA-10).
 */
class GroupMemberResponsibleControllerTest extends TestCase
{
    use DatabaseTransactions;

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

    /** `$body` já é o corpo inteiro; para a lista, `['responsible_user_ids' => [...]]`. */
    private function putAs(User $caller, Group $group, int|string $userId, array $body): TestResponse
    {
        return $this->withToken($this->tokenFor($caller))
            ->putJson("/api/groups/{$group->id}/members/{$userId}/responsibles", $body);
    }

    /** O devedor manda a própria lista. */
    private function setList(User $debtor, Group $group, array $ids): TestResponse
    {
        return $this->putAs($debtor, $group, $debtor->id, ['responsible_user_ids' => $ids]);
    }

    /** @return list<int> */
    private function responsibleIdsOf(Group $group, User $debtor): array
    {
        return GroupMemberResponsible::where('group_id', $group->id)
            ->where('user_id', $debtor->id)
            ->orderBy('responsible_user_id')
            ->pluck('responsible_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function assignedNotificationsFor(User $user)
    {
        return Notification::where('type', 'payment_responsible_assigned')->where('user_id', $user->id)->get();
    }

    // --- CA-1: definir a lista --------------------------------------------

    public function test_debtor_sets_two_responsibles(): void
    {
        $debtor = User::factory()->create();
        $ana = User::factory()->create(['name' => 'Ana']);
        $bruno = User::factory()->create(['name' => 'Bruno']);
        $group = $this->groupWith([$debtor, $ana, $bruno]);

        $response = $this->setList($debtor, $group, [$bruno->id, $ana->id]);

        $response->assertOk()
            ->assertJsonCount(2, 'responsibles')
            ->assertJson(['responsibles' => [
                ['id' => $ana->id, 'name' => 'Ana'],
                ['id' => $bruno->id, 'name' => 'Bruno'],
            ]])
            ->assertJsonMissingPath('responsibles.0.email');
        $this->assertEqualsCanonicalizing([$ana->id, $bruno->id], $this->responsibleIdsOf($group, $debtor));
    }

    public function test_put_with_another_list_adds_removes_and_keeps_who_stays(): void
    {
        [$debtor, $leaving, $staying, $entering] = User::factory()->count(4)->create()->all();
        $group = $this->groupWith([$debtor, $leaving, $staying, $entering]);
        $this->setList($debtor, $group, [$leaving->id, $staying->id])->assertOk();
        $stayingRowId = GroupMemberResponsible::where('group_id', $group->id)
            ->where('responsible_user_id', $staying->id)->value('id');

        $this->setList($debtor, $group, [$staying->id, $entering->id])->assertOk();

        $this->assertEqualsCanonicalizing([$staying->id, $entering->id], $this->responsibleIdsOf($group, $debtor));
        $this->assertSame(
            $stayingRowId,
            GroupMemberResponsible::where('group_id', $group->id)->where('responsible_user_id', $staying->id)->value('id'),
            'quem continua na lista não pode ter a linha recriada'
        );
    }

    public function test_put_with_an_empty_list_removes_every_link(): void
    {
        [$debtor, $first, $second] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith([$debtor, $first, $second]);
        $this->setList($debtor, $group, [$first->id, $second->id])->assertOk();

        $this->setList($debtor, $group, [])
            ->assertOk()
            ->assertExactJson(['message' => 'Responsáveis atualizados.', 'responsibles' => []]);

        $this->assertSame([], $this->responsibleIdsOf($group, $debtor));
    }

    public function test_put_without_the_list_returns_422(): void
    {
        [$debtor, $other] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$debtor, $other]);

        $this->putAs($debtor, $group, $debtor->id, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('responsible_user_ids');
    }

    // --- Teto da lista (BAIXA-3 da revisão de segurança) ------------------

    public function test_put_with_more_than_the_maximum_ids_returns_422_and_keeps_the_previous_list(): void
    {
        [$debtor, $ana] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$debtor, $ana]);
        $this->setList($debtor, $group, [$ana->id])->assertOk();

        $tooMany = range(1000, 1000 + GroupMemberResponsibleController::MAX_RESPONSIBLES);

        $this->setList($debtor, $group, $tooMany)
            ->assertStatus(422)
            ->assertJsonValidationErrors('responsible_user_ids')
            ->assertJsonPath('errors.responsible_user_ids.0', 'Escolha no máximo 50 pessoas.');

        $this->assertSame([$ana->id], $this->responsibleIdsOf($group, $debtor), 'a lista anterior fica');
    }

    public function test_put_with_exactly_the_maximum_ids_passes_the_validator(): void
    {
        [$debtor, $other] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$debtor, $other]);

        // 50 ids que não são membros: passam pelo validador e caem na regra do service, com a mensagem dela.
        $atTheLimit = range(1000, 999 + GroupMemberResponsibleController::MAX_RESPONSIBLES);
        $this->assertCount(GroupMemberResponsibleController::MAX_RESPONSIBLES, $atTheLimit);

        $this->setList($debtor, $group, $atTheLimit)
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Escolha só membros deste grupo.']);
    }

    // --- R2, CA-1: quem pode, e 404 --------------------------------------

    public function test_put_from_another_member_returns_403(): void
    {
        [$debtor, $other, $friend] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith([$debtor, $other, $friend]);

        $this->putAs($other, $group, $debtor->id, ['responsible_user_ids' => [$friend->id]])->assertForbidden();

        $this->assertSame([], $this->responsibleIdsOf($group, $debtor));
    }

    public function test_creator_cannot_set_someone_elses_responsibles(): void
    {
        [$creator, $debtor, $friend] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith([$creator, $debtor, $friend], $creator);

        $this->putAs($creator, $group, $debtor->id, ['responsible_user_ids' => [$friend->id]])->assertForbidden();

        $this->assertSame([], $this->responsibleIdsOf($group, $debtor));
        $this->assertSame([], $this->responsibleIdsOf($group, $creator));
    }

    public function test_non_member_gets_404(): void
    {
        [$debtor, $friend, $outsider] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith([$debtor, $friend]);
        $this->setList($debtor, $group, [$friend->id])->assertOk(); // controle: a rota existe e funciona

        $this->putAs($outsider, $group, $outsider->id, ['responsible_user_ids' => [$friend->id]])->assertNotFound();
        $this->putAs($outsider, $group, $debtor->id, ['responsible_user_ids' => []])->assertNotFound();

        $this->assertSame([$friend->id], $this->responsibleIdsOf($group, $debtor), 'a lista do devedor não foi tocada');
    }

    public function test_deleted_group_gets_404(): void
    {
        [$debtor, $friend] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$debtor, $friend]);
        $this->setList($debtor, $group, [$friend->id])->assertOk(); // controle: a rota existe e funciona
        $group->update(['deleted' => true]);

        $this->setList($debtor, $group, [])->assertNotFound();

        $this->assertSame([$friend->id], $this->responsibleIdsOf($group, $debtor));
    }

    // --- R3, CA-1: 422 sem alterar nada ----------------------------------

    public function test_put_with_a_member_who_left_returns_422_and_changes_nothing(): void
    {
        [$debtor, $staying, $leaver] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith([$debtor, $staying, $leaver]);
        $this->setList($debtor, $group, [$staying->id])->assertOk();
        $group->members()->detach($leaver->id);

        $this->setList($debtor, $group, [$staying->id, $leaver->id])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Escolha só membros deste grupo.']);

        $this->assertSame([$staying->id], $this->responsibleIdsOf($group, $debtor), 'a lista antiga continua igual');
    }

    public function test_responsible_cannot_be_the_debtor(): void
    {
        [$debtor, $other] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$debtor, $other]);

        $this->setList($debtor, $group, [$other->id, $debtor->id])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Você não pode ser quem paga por você mesmo.']);

        $this->assertSame([], $this->responsibleIdsOf($group, $debtor));
    }

    public function test_repeated_responsible_returns_422(): void
    {
        [$debtor, $other] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$debtor, $other]);

        $this->setList($debtor, $group, [$other->id, $other->id])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'A lista não pode repetir ninguém.']);

        $this->assertSame([], $this->responsibleIdsOf($group, $debtor));
    }

    public function test_invalid_ids_return_422(): void
    {
        [$debtor, $other] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$debtor, $other]);
        $this->setList($debtor, $group, [$other->id])->assertOk(); // controle: a rota existe e funciona

        $invalidBodies = [
            'texto na lista' => ['responsible_user_ids' => ['abc']],
            'lista dentro da lista' => ['responsible_user_ids' => [[$other->id]]],
            'id que não existe' => ['responsible_user_ids' => [999999999]],
            'a chave não é uma lista' => ['responsible_user_ids' => (string) $other->id],
        ];

        foreach ($invalidBodies as $caso => $body) {
            $this->putAs($debtor, $group, $debtor->id, $body)->assertStatus(422, "caso: {$caso}");
        }

        $this->assertSame([$other->id], $this->responsibleIdsOf($group, $debtor), 'nenhum caso inválido alterou a lista');
    }

    // --- autenticação e a rota sem DELETE --------------------------------

    public function test_put_requires_authentication(): void
    {
        [$debtor, $other] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$debtor, $other]);
        // Sem login antes: o guard `api` guarda o usuário do `login()` e a chamada sem token passaria
        // autenticada. Sem a rota o teste já falha (404 em vez de 401), então não precisa de controle.
        $this->putJson("/api/groups/{$group->id}/members/{$debtor->id}/responsibles", ['responsible_user_ids' => [$other->id]])
            ->assertUnauthorized();

        $this->assertSame([], $this->responsibleIdsOf($group, $debtor));
    }

    public function test_delete_on_the_route_returns_405(): void
    {
        [$debtor, $other] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$debtor, $other]);
        $this->setList($debtor, $group, [$other->id])->assertOk(); // controle: a rota existe e funciona

        $this->withToken($this->tokenFor($debtor))
            ->deleteJson("/api/groups/{$group->id}/members/{$debtor->id}/responsibles")
            ->assertStatus(405);

        $this->assertSame([$other->id], $this->responsibleIdsOf($group, $debtor));
    }

    // --- R11, CA-10: avisos ----------------------------------------------

    public function test_put_notifies_each_new_responsible_once_with_group_id(): void
    {
        $debtor = User::factory()->create(['name' => 'Devedor']);
        [$first, $second] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$debtor, $first, $second]);

        $this->setList($debtor, $group, [$first->id, $second->id])->assertOk();

        foreach ([$first, $second] as $responsible) {
            $rows = $this->assignedNotificationsFor($responsible);
            $this->assertCount(1, $rows);
            $this->assertSame($group->id, $rows->first()->group_id);
            $data = $rows->first()->data;
            // O MySQL reordena as chaves de uma coluna json: confere o conjunto, não a ordem.
            $this->assertEqualsCanonicalizing(['actorName', 'groupId', 'groupName'], array_keys($data));
            $this->assertSame('Devedor', $data['actorName']);
            $this->assertSame($group->id, $data['groupId']);
            $this->assertSame('Casa', $data['groupName']);
        }
        $this->assertSame(0, Notification::where('user_id', $debtor->id)->count(), 'o devedor não é avisado');
    }

    public function test_identical_put_does_not_notify_again(): void
    {
        [$debtor, $responsible] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$debtor, $responsible]);

        $this->setList($debtor, $group, [$responsible->id])->assertOk();
        $this->setList($debtor, $group, [$responsible->id])->assertOk();

        $this->assertCount(1, $this->assignedNotificationsFor($responsible));
    }

    public function test_put_notifies_only_who_entered_the_list(): void
    {
        [$debtor, $first, $second] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith([$debtor, $first, $second]);
        $this->setList($debtor, $group, [$first->id])->assertOk();

        $this->setList($debtor, $group, [$first->id, $second->id])->assertOk();

        $this->assertCount(1, $this->assignedNotificationsFor($first), 'quem já estava não é avisado de novo');
        $this->assertCount(1, $this->assignedNotificationsFor($second));
    }

    public function test_removing_from_the_list_notifies_nobody(): void
    {
        [$debtor, $first, $second] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith([$debtor, $first, $second]);
        $this->setList($debtor, $group, [$first->id, $second->id])->assertOk();
        $this->assertSame(2, Notification::where('type', 'payment_responsible_assigned')->count());

        $this->setList($debtor, $group, [$first->id])->assertOk();
        $this->setList($debtor, $group, [])->assertOk();

        $this->assertSame(2, Notification::where('type', 'payment_responsible_assigned')->count(), 'sair da lista não gera aviso');
    }

    public function test_notifier_failure_does_not_undo_the_links(): void
    {
        Log::spy();
        [$debtor, $responsible] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$debtor, $responsible]);
        Notification::creating(function () {
            throw new \RuntimeException('falha simulada ao gravar a notificação');
        });

        $this->setList($debtor, $group, [$responsible->id])->assertOk();

        $this->assertSame([$responsible->id], $this->responsibleIdsOf($group, $debtor), 'o vínculo existe mesmo sem o aviso');
        $this->assertSame(0, Notification::where('type', 'payment_responsible_assigned')->count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => ($context['type'] ?? null) === 'payment_responsible_assigned')
            ->once();
    }
}
