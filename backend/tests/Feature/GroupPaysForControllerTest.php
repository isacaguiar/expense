<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMemberResponsible;
use App\Models\Notification;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\Concerns\BuildsThirdPartyProofScenarios;
use Tests\TestCase;

/**
 * O responsável se declara (feature 20261110-quem-paga-se-declara-e-credor-recusa, TASK-604, specify R1 a
 * R4, D1, D2, D3, D8 e F1): `PUT /groups/{id}/me/pays-for` troca a lista de quem **eu** pago, sem aceite do
 * devedor (o caso da criança que não abre o app). Só mexe nos vínculos em que quem chama é o responsável;
 * a lista de terceiros e a do devedor (`PUT .../responsibles`) seguem como estão.
 */
class GroupPaysForControllerTest extends TestCase
{
    use BuildsSettlementScenarios;
    use BuildsThirdPartyProofScenarios;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-19 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function groupWith(array $members): Group
    {
        $group = Group::create(['name' => 'Casa']);
        $group->members()->attach(array_map(fn (User $user) => $user->id, $members));

        return $group;
    }

    /** `$caller` manda a lista inteira de quem ele paga. `$group` pode ser um id (inexistente, com texto...). */
    private function declare(User $caller, Group|int|string $group, array $body): TestResponse
    {
        $groupId = $group instanceof Group ? $group->id : $group;

        return $this->withToken($this->tokenFor($caller))->putJson("/api/groups/{$groupId}/me/pays-for", $body);
    }

    private function setPaysFor(User $caller, Group|int|string $group, array $debtorIds): TestResponse
    {
        return $this->declare($caller, $group, ['debtor_user_ids' => $debtorIds]);
    }

    /** @return list<int> os devedores por quem `$responsible` paga, direto do banco */
    private function paysFor(Group $group, User $responsible): array
    {
        return GroupMemberResponsible::where('group_id', $group->id)
            ->where('responsible_user_id', $responsible->id)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Todas as linhas de vínculo do grupo, para provar que um 422 não alterou nada. */
    private function allLinks(Group $group): array
    {
        return DB::table('ex_group_member_responsibles')
            ->where('group_id', $group->id)
            ->orderBy('id')
            ->get(['id', 'user_id', 'responsible_user_id', 'created_by'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function createdBy(Group $group, User $debtor, User $responsible): ?int
    {
        $value = DB::table('ex_group_member_responsibles')
            ->where('group_id', $group->id)
            ->where('user_id', $debtor->id)
            ->where('responsible_user_id', $responsible->id)
            ->value('created_by');

        return $value === null ? null : (int) $value;
    }

    /** Um vínculo que o devedor criou (como o `PUT .../responsibles` faz). */
    private function linkCreatedByDebtor(Group $group, User $debtor, User $responsible): void
    {
        GroupMemberResponsible::create([
            'group_id' => $group->id,
            'user_id' => $debtor->id,
            'responsible_user_id' => $responsible->id,
            'created_by' => $debtor->id,
        ]);
    }

    private function selfDeclaredFor(User $user)
    {
        return Notification::where('type', 'payment_responsible_self_declared')->where('user_id', $user->id)->get();
    }

    // --- R1, R3, D3: declarar ---------------------------------------------

    public function test_the_responsible_declares_who_they_pay_for_without_the_debtors_consent(): void
    {
        $me = User::factory()->create(['name' => 'Mae']);
        $davi = User::factory()->create(['name' => 'Davi']);
        $ana = User::factory()->create(['name' => 'Ana']);
        $group = $this->groupWith([$me, $davi, $ana]);

        $response = $this->setPaysFor($me, $group, [$davi->id, $ana->id]);

        $response->assertOk()
            ->assertExactJson([
                'message' => 'Escolha salva.',
                'pays_for' => [['id' => $ana->id, 'name' => 'Ana'], ['id' => $davi->id, 'name' => 'Davi']],
            ]);
        $this->assertEqualsCanonicalizing([$ana->id, $davi->id], $this->paysFor($group, $me));
        $this->assertSame($me->id, $this->createdBy($group, $ana, $me), 'o vínculo guarda quem o criou: o responsável');
        $this->assertSame($me->id, $this->createdBy($group, $davi, $me));
    }

    public function test_the_response_carries_only_id_and_name(): void
    {
        [$me, $debtor] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$me, $debtor]);

        $this->setPaysFor($me, $group, [$debtor->id])
            ->assertOk()
            ->assertJsonMissingPath('pays_for.0.email')
            ->assertJsonMissingPath('pays_for.0.pix_key')
            ->assertJsonCount(2, 'pays_for.0');
    }

    // --- D8: trocar e largar ----------------------------------------------

    public function test_a_new_list_adds_removes_and_keeps_who_stays_without_recreating_the_row(): void
    {
        [$me, $leaving, $staying, $entering] = User::factory()->count(4)->create()->all();
        $group = $this->groupWith([$me, $leaving, $staying, $entering]);
        $this->setPaysFor($me, $group, [$leaving->id, $staying->id])->assertOk();
        $stayingRowId = GroupMemberResponsible::where('group_id', $group->id)->where('user_id', $staying->id)->value('id');

        $this->setPaysFor($me, $group, [$staying->id, $entering->id])->assertOk();

        $this->assertEqualsCanonicalizing([$staying->id, $entering->id], $this->paysFor($group, $me));
        $this->assertSame(
            $stayingRowId,
            GroupMemberResponsible::where('group_id', $group->id)->where('user_id', $staying->id)->value('id'),
            'quem continua na lista não pode ter a linha recriada'
        );
    }

    public function test_the_responsible_can_drop_a_link_the_debtor_created(): void
    {
        [$me, $debtor] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$me, $debtor]);
        $this->linkCreatedByDebtor($group, $debtor, $me);
        $this->assertSame($debtor->id, $this->createdBy($group, $debtor, $me));

        // Quem já pagava por escolha do devedor continua com a linha e a autoria dela quando fica na lista.
        $this->setPaysFor($me, $group, [$debtor->id])->assertOk();
        $this->assertSame($debtor->id, $this->createdBy($group, $debtor, $me), 'a autoria do devedor não é reescrita');

        // E larga o papel quando sai dela (D8), mesmo que o devedor o tenha criado.
        $this->setPaysFor($me, $group, [])->assertOk()->assertExactJson(['message' => 'Escolha salva.', 'pays_for' => []]);
        $this->assertSame([], $this->paysFor($group, $me));
    }

    public function test_it_never_touches_the_links_of_anyone_else(): void
    {
        [$me, $other, $debtor, $third] = User::factory()->count(4)->create()->all();
        $group = $this->groupWith([$me, $other, $debtor, $third]);
        $this->linkCreatedByDebtor($group, $debtor, $other);   // outro responsável do mesmo devedor
        $this->linkCreatedByDebtor($group, $third, $debtor);   // um vínculo sem relação com quem chama
        $untouched = $this->allLinks($group);

        $this->setPaysFor($me, $group, [$debtor->id])->assertOk();
        $this->setPaysFor($me, $group, [])->assertOk();

        $this->assertSame($untouched, $this->allLinks($group), 'só as linhas de quem chama mudam, e voltaram ao que eram');
    }

    public function test_it_only_changes_the_links_of_the_group_in_the_path(): void
    {
        [$me, $debtor] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$me, $debtor]);
        $otherGroup = $this->groupWith([$me, $debtor]);
        $this->linkCreatedByDebtor($otherGroup, $debtor, $me);

        // O vínculo já existente em outro grupo não conta como "quem já estava" neste: a linha nasce aqui.
        $this->setPaysFor($me, $group, [$debtor->id])->assertOk();
        $this->assertSame([$debtor->id], $this->paysFor($group, $me), 'o vínculo é criado no grupo da rota');
        $this->setPaysFor($me, $group, [])->assertOk();

        $this->assertSame([], $this->paysFor($group, $me));
        $this->assertSame([$debtor->id], $this->paysFor($otherGroup, $me), 'o vínculo do outro grupo fica');
    }

    // --- F1: 422 sem alterar nada -----------------------------------------

    public function test_declaring_yourself_returns_422_and_changes_nothing(): void
    {
        [$me, $debtor] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$me, $debtor]);
        $this->setPaysFor($me, $group, [$debtor->id])->assertOk();
        $before = $this->allLinks($group);

        $this->setPaysFor($me, $group, [$debtor->id, $me->id])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Você não pode pagar por você mesmo.']);

        $this->assertSame($before, $this->allLinks($group));
    }

    public function test_a_repeated_debtor_returns_422_and_changes_nothing(): void
    {
        [$me, $debtor] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$me, $debtor]);
        $before = $this->allLinks($group);

        $this->setPaysFor($me, $group, [$debtor->id, $debtor->id])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'A lista não pode repetir ninguém.']);

        $this->assertSame($before, $this->allLinks($group));
    }

    public function test_a_non_member_debtor_returns_422_and_changes_nothing(): void
    {
        [$me, $member, $outsider] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith([$me, $member]);
        $this->setPaysFor($me, $group, [$member->id])->assertOk();
        $before = $this->allLinks($group);

        $this->setPaysFor($me, $group, [$member->id, $outsider->id])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Escolha só membros deste grupo.']);

        $this->assertSame($before, $this->allLinks($group));
    }

    public function test_the_declaration_is_refused_when_the_debtor_left_the_group(): void
    {
        [$me, $staying, $leaver] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith([$me, $staying, $leaver]);
        $this->setPaysFor($me, $group, [$staying->id])->assertOk();
        $group->members()->detach($leaver->id);
        $before = $this->allLinks($group);

        $this->setPaysFor($me, $group, [$staying->id, $leaver->id])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Escolha só membros deste grupo.']);

        $this->assertSame($before, $this->allLinks($group), 'quem saiu do grupo não pode ser declarado');
    }

    public function test_more_than_fifty_returns_422_and_keeps_the_previous_list(): void
    {
        [$me, $debtor] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$me, $debtor]);
        $this->setPaysFor($me, $group, [$debtor->id])->assertOk();
        $before = $this->allLinks($group);

        $this->setPaysFor($me, $group, range(1000, 1050))
            ->assertStatus(422)
            ->assertJsonValidationErrors('debtor_user_ids')
            ->assertJsonPath('errors.debtor_user_ids.0', 'Escolha no máximo 50 pessoas.');

        $this->assertSame($before, $this->allLinks($group));
    }

    public function test_exactly_fifty_passes_the_validator(): void
    {
        [$me, $other] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$me, $other]);

        // 50 ids que não são membros: passam pelo validador e caem na regra do service, com a mensagem dela.
        $this->setPaysFor($me, $group, range(1000, 1049))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Escolha só membros deste grupo.']);
    }

    public function test_a_missing_or_invalid_field_returns_422_and_changes_nothing(): void
    {
        [$me, $debtor] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$me, $debtor]);
        $this->setPaysFor($me, $group, [$debtor->id])->assertOk(); // controle: a rota existe e funciona
        $before = $this->allLinks($group);

        $invalidBodies = [
            'campo ausente' => [],
            'texto na lista' => ['debtor_user_ids' => ['abc']],
            'lista dentro da lista' => ['debtor_user_ids' => [[$debtor->id]]],
            'id que não existe' => ['debtor_user_ids' => [999999999]],
            'a chave não é uma lista' => ['debtor_user_ids' => (string) $debtor->id],
        ];

        foreach ($invalidBodies as $caso => $body) {
            $this->declare($me, $group, $body)->assertStatus(422, "caso: {$caso}");
        }

        $this->assertSame($before, $this->allLinks($group), 'nenhum caso inválido alterou nada');
    }

    // --- R1: 404 e autenticação -------------------------------------------

    public function test_a_non_member_gets_404(): void
    {
        [$me, $debtor, $outsider] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith([$me, $debtor]);
        $this->setPaysFor($me, $group, [$debtor->id])->assertOk(); // controle: a rota existe e funciona
        $before = $this->allLinks($group);

        $this->setPaysFor($outsider, $group, [$debtor->id])->assertNotFound();
        $this->setPaysFor($outsider, $group, [])->assertNotFound();

        $this->assertSame($before, $this->allLinks($group));
    }

    public function test_a_missing_deleted_or_text_group_gets_404(): void
    {
        [$me, $debtor] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$me, $debtor]);
        $this->setPaysFor($me, $group, [$debtor->id])->assertOk(); // controle: a rota existe e funciona
        $before = $this->allLinks($group);

        $this->setPaysFor($me, 999999999, [])->assertNotFound();
        $this->declare($me, "{$group->id}abc", ['debtor_user_ids' => []])->assertNotFound();
        $group->update(['deleted' => true]);
        $this->setPaysFor($me, $group, [])->assertNotFound();

        $this->assertSame($before, $this->allLinks($group));
    }

    public function test_it_requires_authentication(): void
    {
        [$me, $debtor] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$me, $debtor]);

        $this->putJson("/api/groups/{$group->id}/me/pays-for", ['debtor_user_ids' => [$debtor->id]])->assertUnauthorized();

        $this->assertSame([], $this->allLinks($group));
    }

    // --- D2, R11: o aviso ao devedor ---------------------------------------

    public function test_each_debtor_who_entered_is_notified_with_group_id_and_the_responsibles_name(): void
    {
        $me = User::factory()->create(['name' => 'Mae']);
        [$first, $second] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$me, $first, $second]);

        $this->setPaysFor($me, $group, [$first->id, $second->id])->assertOk();

        foreach ([$first, $second] as $debtor) {
            $rows = $this->selfDeclaredFor($debtor);
            $this->assertCount(1, $rows);
            $this->assertSame($group->id, $rows->first()->group_id);
            $data = $rows->first()->data;
            $this->assertEqualsCanonicalizing(['actorName', 'groupId', 'groupName'], array_keys($data));
            $this->assertSame('Mae', $data['actorName'], 'quem aparece no aviso é quem se declarou');
            $this->assertSame($group->id, $data['groupId']);
            $this->assertSame('Casa', $data['groupName']);
        }
    }

    public function test_only_who_entered_is_notified_not_who_stayed_left_or_the_caller(): void
    {
        [$me, $staying, $leaving, $entering] = User::factory()->count(4)->create()->all();
        $group = $this->groupWith([$me, $staying, $leaving, $entering]);
        $this->setPaysFor($me, $group, [$staying->id, $leaving->id])->assertOk();
        Notification::query()->delete();
        // Fora da janela de 24 h: com a marca da 1ª lista ainda valendo, avisar quem já estava ou quem
        // saiu passaria despercebido, porque a janela o suprimiria. Aqui só a regra "quem entrou" decide.
        Carbon::setTestNow('2026-08-21 10:00:00');

        $this->setPaysFor($me, $group, [$staying->id, $entering->id])->assertOk();

        $this->assertCount(0, $this->selfDeclaredFor($staying), 'quem já estava não é avisado de novo');
        $this->assertCount(0, $this->selfDeclaredFor($leaving), 'sair da lista não gera aviso');
        $this->assertCount(1, $this->selfDeclaredFor($entering));
        $this->assertCount(0, $this->selfDeclaredFor($me), 'ninguém é avisado da ação que ele mesmo fez');
        $this->assertSame(1, Notification::where('type', 'payment_responsible_self_declared')->count());
    }

    public function test_toggling_within_24h_notifies_once_and_again_after_the_window(): void
    {
        [$me, $debtor] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$me, $debtor]);

        $this->setPaysFor($me, $group, [$debtor->id])->assertOk();
        $this->setPaysFor($me, $group, [])->assertOk();
        $this->setPaysFor($me, $group, [$debtor->id])->assertOk();
        $this->assertCount(1, $this->selfDeclaredFor($debtor), 'por mais que se alterne, um aviso em 24 h');

        $this->setPaysFor($me, $group, [])->assertOk();
        Carbon::setTestNow('2026-08-20 11:00:00'); // 25 h depois
        $this->setPaysFor($me, $group, [$debtor->id])->assertOk();

        $this->assertCount(2, $this->selfDeclaredFor($debtor), 'passada a janela, avisa de novo');
    }

    public function test_the_notice_window_is_per_type_and_does_not_mix_with_the_debtors_own_notice(): void
    {
        [$me, $debtor] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$me, $debtor]);

        // O devedor escolhe `$me` (aviso `payment_responsible_assigned` para `$me`) e depois `$me` se
        // declara por ele: são dois tipos, cada um com a própria janela, e o 2º ainda sai.
        $this->withToken($this->tokenFor($debtor))
            ->putJson("/api/groups/{$group->id}/members/{$debtor->id}/responsibles", ['responsible_user_ids' => [$me->id]])
            ->assertOk();
        $this->setPaysFor($me, $group, [])->assertOk();
        $this->setPaysFor($me, $group, [$debtor->id])->assertOk();

        $this->assertSame(1, Notification::where('type', 'payment_responsible_assigned')->where('user_id', $me->id)->count());
        $this->assertCount(1, $this->selfDeclaredFor($debtor));
    }

    public function test_a_notifier_failure_does_not_undo_the_links(): void
    {
        Log::spy();
        [$me, $debtor] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$me, $debtor]);
        Notification::creating(function () {
            throw new \RuntimeException('falha simulada ao gravar a notificação');
        });

        $this->setPaysFor($me, $group, [$debtor->id])->assertOk();

        $this->assertSame([$debtor->id], $this->paysFor($group, $me), 'o vínculo existe mesmo sem o aviso');
        $this->assertSame(0, Notification::where('type', 'payment_responsible_self_declared')->count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => ($context['type'] ?? null) === 'payment_responsible_self_declared')
            ->once();
    }

    // --- R2, R4: o devedor remove; o vínculo só dá o poder de enviar -------

    private function settlementScenario(): array
    {
        Storage::fake('local');
        [$creditor, $debtor, $me] = User::factory()->count(3)->create()->all();
        $group = $this->groupWith([$creditor, $debtor, $me]);
        $this->makeSettlement($group, $creditor, $debtor, 200);

        return [$group, $creditor, $debtor, $me];
    }

    public function test_the_declared_responsible_can_send_the_proof_for_the_debtor(): void
    {
        [$group, $creditor, $debtor, $me] = $this->settlementScenario();
        $this->confirmAs($me, $group, $creditor, $debtor->id)->assertForbidden(); // controle: sem declarar, não pode

        $this->setPaysFor($me, $group, [$debtor->id])->assertOk();
        $this->confirmAs($me, $group, $creditor, $debtor->id)->assertOk();

        $row = SettlementConfirmation::where('group_id', $group->id)->sole();
        $this->assertSame($debtor->id, (int) $row->from_user_id, 'o acerto continua sendo do devedor');
        $this->assertSame($me->id, (int) $row->confirmed_by);
        $this->assertSame(SettlementConfirmation::STATUS_AWAITING_ACCEPTANCE, $row->status(), 'aguarda o aceite do credor');
    }

    public function test_the_debtor_who_omits_the_declared_responsible_removes_the_link(): void
    {
        [$group, $creditor, $debtor, $me] = $this->settlementScenario();
        $this->setPaysFor($me, $group, [$debtor->id])->assertOk();
        $this->assertSame([$debtor->id], $this->paysFor($group, $me));

        $this->withToken($this->tokenFor($debtor))
            ->putJson("/api/groups/{$group->id}/members/{$debtor->id}/responsibles", ['responsible_user_ids' => []])
            ->assertOk();

        $this->assertSame([], $this->paysFor($group, $me), 'o devedor continua mandando na própria lista (R2)');
        $this->confirmAs($me, $group, $creditor, $debtor->id)->assertForbidden();
    }
}
