<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\GroupMemberResponsible;
use App\Models\Notification;
use App\Models\SettlementConfirmation;
use App\Models\User;
use App\Support\ProofStorage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\Concerns\BuildsThirdPartyProofScenarios;
use Tests\TestCase;

/**
 * Matriz de autorização das quatro rotas novas da feature 20261110-quem-paga-se-declara-e-credor-recusa
 * (TASK-605, specify §3.2 R1, R5, R6 e R9, e §3.5 F1 a F5): `PUT /groups/{id}/me/pays-for`,
 * `POST .../settlements/accept`, `POST .../settlements/refuse` e `DELETE .../settlements/confirmation`.
 *
 * Os status esperados ficam escritos aqui, e **em todo caso de recusa nada é gravado** (`world()` é igual
 * antes e depois: comprovantes, vínculos, avisos, selo e arquivos). Ordem das respostas:
 *
 * - `accept` e `refuse`: 401 sem token; 404 para grupo inexistente, excluído, de id malformado ou de quem
 *   não é membro; 422 de corpo inválido; **403** se `to_user_id` não é quem chama, **antes** de olhar o
 *   ciclo ou o comprovante; depois 422 (ciclo aberto, encerrado, sem passar pelo aceite, aceito ou
 *   recusado) e 404 (sem comprovante no par).
 * - `destroy`: a mesma entrada; depois 422 de ciclo, 404 sem comprovante, **403** para quem não enviou
 *   nem é o devedor e 422 para o que não pode sair (do devedor, ou já aceito).
 * - `pays-for`: 401; 404 para grupo inexistente, excluído, malformado ou de quem não é membro; 422 de
 *   lista inválida; 200 sempre sobre a lista de **quem chama**.
 *
 * Cada caso de 200 é o controle positivo do caso de recusa ao lado: sem ele o teste poderia passar com o
 * cenário quebrado.
 */
class SettlementDecisionAuthorizationMatrixTest extends TestCase
{
    use BuildsSettlementScenarios;
    use BuildsThirdPartyProofScenarios;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Grupo de agosto/2026 com o criador, o credor, o devedor, o responsável que **enviou** o comprovante
     * (vinculado ao devedor), outro responsável do mesmo devedor que não enviou nada, um terceiro e quem
     * não é membro. O comprovante aguarda o aceite e o arquivo está no disco falso.
     *
     * @return array{group: Group, creditor: User, debtor: User, responsible: User, creator: User, other_responsible: User, third: User, outsider: User, proof: SettlementConfirmation}
     */
    private function scenario(bool $closeCycle = true): array
    {
        Storage::fake(ProofStorage::DISK);
        [$group, $creditor, $debtor, $responsible, $proof, $creator] = $this->thirdPartyScenario(paid: true, closeCycle: $closeCycle);
        [$otherResponsible, $third, $outsider] = User::factory()->count(3)->create()->all();
        $group->members()->attach([$otherResponsible->id, $third->id]);
        foreach ([$responsible, $otherResponsible] as $linked) {
            GroupMemberResponsible::create(['group_id' => $group->id, 'user_id' => $debtor->id, 'responsible_user_id' => $linked->id]);
        }
        Storage::disk(ProofStorage::DISK)->put($proof->proof_path, 'conteudo do comprovante');

        return [
            'group' => $group,
            'creditor' => $creditor,
            'debtor' => $debtor,
            'responsible' => $responsible,
            'creator' => $creator,
            'other_responsible' => $otherResponsible,
            'third' => $third,
            'outsider' => $outsider,
            'proof' => $proof,
        ];
    }

    /** Tudo o que uma rota nova poderia gravar. Igual antes e depois de toda recusa. */
    private function world(): array
    {
        return [
            'confirmations' => SettlementConfirmation::orderBy('id')->get()->map->getAttributes()->all(),
            'links' => GroupMemberResponsible::orderBy('group_id')->orderBy('user_id')->orderBy('responsible_user_id')->get()->map->getAttributes()->all(),
            'notifications' => Notification::count(),
            'sealed' => GroupCycleSnapshot::whereNotNull('settled_at')->count(),
            'files' => Storage::disk(ProofStorage::DISK)->allFiles(),
        ];
    }

    /** O corpo do par como num pedido HTTP de verdade: ids em texto. */
    private function pair(array $s, array $extra = []): array
    {
        return ['from_user_id' => (string) $s['debtor']->id, 'to_user_id' => (string) $s['creditor']->id] + $extra;
    }

    private function decide(string $route, User $caller, Group|int|string $group, array $body): TestResponse
    {
        $groupId = $group instanceof Group ? $group->id : $group;
        $token = $this->withToken($this->tokenFor($caller));

        return match ($route) {
            'accept' => $token->postJson("/api/groups/{$groupId}/settlements/accept", $body),
            'refuse' => $token->postJson("/api/groups/{$groupId}/settlements/refuse", $body),
            'destroy' => $token->deleteJson("/api/groups/{$groupId}/settlements/confirmation?".http_build_query($body)),
        };
    }

    private function paysFor(User $caller, Group|int|string $group, array $body): TestResponse
    {
        $groupId = $group instanceof Group ? $group->id : $group;

        return $this->withToken($this->tokenFor($caller))->putJson("/api/groups/{$groupId}/me/pays-for", $body);
    }

    // --- R6: quem decide sobre o comprovante (accept e refuse) --------------

    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function creditorsDecisionMatrix(): array
    {
        $rows = [];
        foreach (['accept', 'refuse'] as $route) {
            $rows["$route: o credor decide"] = [$route, 'creditor', 200];
            $rows["$route: o devedor, de quem é o acerto, não"] = [$route, 'debtor', 403];
            $rows["$route: quem enviou o comprovante não decide sobre o que enviou"] = [$route, 'responsible', 403];
            $rows["$route: outro responsável do mesmo devedor não"] = [$route, 'other_responsible', 403];
            $rows["$route: um terceiro do grupo não"] = [$route, 'third', 403];
            $rows["$route: o criador do grupo não é credor, então não"] = [$route, 'creator', 403];
            $rows["$route: quem não é membro recebe 404, não 403"] = [$route, 'outsider', 404];
        }

        return $rows;
    }

    /** @dataProvider creditorsDecisionMatrix */
    public function test_only_the_creditor_of_the_pair_decides(string $route, string $role, int $expected): void
    {
        $s = $this->scenario();
        $before = $this->world();

        $response = $this->decide($route, $s[$role], $s['group'], $this->pair($s));

        $response->assertStatus($expected);
        if ($expected === 200) {
            $fresh = $s['proof']->fresh();
            $this->assertSame($route === 'accept' ? 'accepted' : 'refused', $fresh->status());
            $this->assertNotNull($route === 'accept' ? $fresh->accepted_at : $fresh->refused_at);
            $this->assertSame($route === 'accept', $this->sealedSnapshot($s['group']) !== null, 'só o aceite sela o ciclo quitado');

            return;
        }

        $this->assertSame($before, $this->world(), 'nada foi escrito');
    }

    /**
     * Quem tenta passar por credor mandando `to_user_id` de outra pessoa recebe 403 antes de qualquer
     * consulta: o corpo não escolhe o credor, quem chama é que é.
     */
    public function test_a_wrong_to_user_id_is_403_before_the_proof_or_the_cycle_are_even_looked_at(): void
    {
        $s = $this->scenario(closeCycle: false);
        $before = $this->world();

        // Um par que nem tem comprovante: o credor existe, mas não é quem chama.
        $noProofPair = ['from_user_id' => (string) $s['third']->id, 'to_user_id' => (string) $s['creditor']->id];

        foreach (['accept', 'refuse'] as $route) {
            foreach (['debtor', 'responsible', 'other_responsible', 'third', 'creator'] as $role) {
                // O ciclo está aberto: se a rota olhasse o ciclo antes do 403, daria 422.
                $this->decide($route, $s[$role], $s['group'], $this->pair($s))->assertForbidden();
                // O par não tem comprovante: se a rota o procurasse antes do 403, daria 404.
                $this->decide($route, $s[$role], $s['group'], $noProofPair)->assertForbidden();
            }
        }

        $this->assertSame($before, $this->world());
    }

    /**
     * O credor de **outro** par (ele tem o próprio comprovante aguardando) não alcança o comprovante do
     * credor do primeiro par por nenhuma combinação de corpo: ou o 403, ou o 404 do par dele.
     */
    public function test_the_creditor_of_another_pair_cannot_reach_this_pair(): void
    {
        $s = $this->scenario();
        $otherCreditor = $s['third'];
        $otherProof = SettlementConfirmation::create([
            'group_id' => $s['group']->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'from_user_id' => $s['debtor']->id,
            'to_user_id' => $otherCreditor->id,
            'confirmed_by' => $s['responsible']->id,
            'amount' => 50,
            'proof_path' => 'comprovantes/'.$s['group']->id.'/y.jpg',
            'confirmed_at' => $this->proofSentAt(),
        ]);
        $before = $this->world();

        foreach (['accept', 'refuse'] as $route) {
            // Diz que é o credor do outro: 403 (to_user_id não é quem chama).
            $this->decide($route, $otherCreditor, $s['group'], $this->pair($s))->assertForbidden();
            // Diz que o credor é ele mesmo, mas de um devedor que não lhe deve nada: 404, sem tocar no outro.
            $this->decide($route, $otherCreditor, $s['group'], ['from_user_id' => (string) $s['creator']->id, 'to_user_id' => (string) $otherCreditor->id])->assertNotFound();
        }

        $this->assertSame($before, $this->world());
        $this->assertNull($s['proof']->fresh()->accepted_at);
        $this->assertNull($s['proof']->fresh()->refused_at);
        $this->assertNull($otherProof->fresh()->accepted_at, 'o comprovante do par dele só muda por uma chamada dele sobre o par dele');
    }

    // --- R6: quem exclui (destroy) -----------------------------------------

    /** @return array<string, array{0: string, 1: int}> */
    public static function destroyMatrix(): array
    {
        return [
            'quem enviou exclui' => ['responsible', 200],
            'o devedor exclui' => ['debtor', 200],
            'o credor não exclui' => ['creditor', 403],
            'outro responsável do mesmo devedor não' => ['other_responsible', 403],
            'um terceiro do grupo não' => ['third', 403],
            'o criador do grupo não' => ['creator', 403],
            'quem não é membro recebe 404' => ['outsider', 404],
        ];
    }

    /** @dataProvider destroyMatrix */
    public function test_only_the_sender_or_the_debtor_deletes(string $role, int $expected): void
    {
        $s = $this->scenario();
        $before = $this->world();

        $this->decide('destroy', $s[$role], $s['group'], $this->pair($s))->assertStatus($expected);

        if ($expected === 200) {
            $this->assertNull($s['proof']->fresh(), 'a linha saiu (D7-A, o único DELETE físico)');
            Storage::disk(ProofStorage::DISK)->assertMissing($s['proof']->proof_path);

            return;
        }

        $this->assertSame($before, $this->world(), 'nada foi escrito nem apagado');
        Storage::disk(ProofStorage::DISK)->assertExists($s['proof']->proof_path);
    }

    /** Quem saiu da lista de responsáveis perde o poder de reenviar, mas exclui o próprio comprovante. */
    public function test_a_sender_who_left_the_list_still_deletes_only_their_own_proof(): void
    {
        $s = $this->scenario();
        GroupMemberResponsible::where('group_id', $s['group']->id)->where('responsible_user_id', $s['responsible']->id)->delete();

        $this->confirmAs($s['responsible'], $s['group'], $s['creditor'], $s['debtor']->id)->assertForbidden();
        $this->decide('destroy', $s['responsible'], $s['group'], $this->pair($s))->assertOk();
        $this->assertNull($s['proof']->fresh());
    }

    // --- R4: o vínculo só dá o poder de enviar ------------------------------

    public function test_the_link_alone_never_gives_the_power_to_decide(): void
    {
        $s = $this->scenario();
        // A pessoa que acabou de se declarar responsável pelo devedor, pela rota nova.
        $this->paysFor($s['third'], $s['group'], ['debtor_user_ids' => [(string) $s['debtor']->id]])->assertOk();
        $before = $this->world();

        foreach (['accept', 'refuse'] as $route) {
            $this->decide($route, $s['third'], $s['group'], $this->pair($s))->assertForbidden();
        }
        $this->decide('destroy', $s['third'], $s['group'], $this->pair($s))->assertForbidden();

        $this->assertSame($before, $this->world());
    }

    // --- entrada: grupo, corpo e token --------------------------------------

    /** @return array<string, array{0: string}> */
    public static function decisionRoutes(): array
    {
        return ['accept' => ['accept'], 'refuse' => ['refuse'], 'destroy' => ['destroy']];
    }

    /** @dataProvider decisionRoutes */
    public function test_group_not_found_deleted_or_malformed_is_404_even_for_the_creditor(string $route): void
    {
        $s = $this->scenario();
        $caller = $route === 'destroy' ? $s['responsible'] : $s['creditor'];

        $this->decide($route, $caller, 99999999, $this->pair($s))->assertNotFound();
        $this->decide($route, $caller, $s['group']->id.'abc', $this->pair($s))->assertNotFound();

        $s['group']->update(['deleted' => true]);
        $before = $this->world();
        $this->decide($route, $caller, $s['group'], $this->pair($s))->assertNotFound();

        $this->assertSame($before, $this->world());
        $this->assertNull($s['proof']->fresh()->accepted_at);
        $this->assertNull($s['proof']->fresh()->refused_at);
    }

    /** @dataProvider decisionRoutes */
    public function test_an_invalid_body_is_422_and_writes_nothing(string $route): void
    {
        $s = $this->scenario();
        $caller = $route === 'destroy' ? $s['responsible'] : $s['creditor'];
        $before = $this->world();

        foreach ([
            [],
            ['from_user_id' => (string) $s['debtor']->id],
            ['from_user_id' => '999999999', 'to_user_id' => (string) $s['creditor']->id],
            ['from_user_id' => 'abc', 'to_user_id' => (string) $s['creditor']->id],
            $this->pair($s, ['cycles_ago' => '-1']),
        ] as $body) {
            $this->decide($route, $caller, $s['group'], $body)->assertStatus(422);
        }

        $this->assertSame($before, $this->world());
    }

    public function test_every_new_route_requires_a_token(): void
    {
        $s = $this->scenario();
        $before = $this->world();
        $group = $s['group']->id;

        $this->postJson("/api/groups/{$group}/settlements/accept", $this->pair($s))->assertUnauthorized();
        $this->postJson("/api/groups/{$group}/settlements/refuse", $this->pair($s))->assertUnauthorized();
        $this->deleteJson("/api/groups/{$group}/settlements/confirmation?".http_build_query($this->pair($s)))->assertUnauthorized();
        $this->putJson("/api/groups/{$group}/me/pays-for", ['debtor_user_ids' => []])->assertUnauthorized();

        $this->assertSame($before, $this->world());
    }

    // --- R9 e R6: estado do ciclo e do comprovante --------------------------

    /**
     * Quem **deveria** poder agir (o credor em accept e refuse, quem enviou em destroy) esbarra no estado:
     * a resposta é 422 ou 404, e nada é escrito.
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function stateMatrix(): array
    {
        $rows = [];
        foreach (['accept', 'refuse', 'destroy'] as $route) {
            $rows["$route: ciclo ainda aberto"] = [$route, 'open_cycle', 422];
            $rows["$route: ciclo encerrado (selado) é imutável"] = [$route, 'sealed_cycle', 422];
            $rows["$route: comprovante já aceito é definitivo"] = [$route, 'accepted', 422];
            $rows["$route: comprovante sem registro no par"] = [$route, 'missing', 404];
            $rows["$route: ciclo sem comprovante (cycles_ago=1)"] = [$route, 'other_cycle', 404];
        }
        // Os dois que dependem da rota: o recusado ainda pode ser excluído (e só ele sai por aqui), o do
        // devedor sem responsável nunca passa pelo aceite nem pela exclusão por esta rota.
        $rows['accept: comprovante já recusado'] = ['accept', 'refused', 422];
        $rows['refuse: comprovante já recusado'] = ['refuse', 'refused', 422];
        $rows['destroy: comprovante recusado sai (retificar e excluir)'] = ['destroy', 'refused', 200];
        foreach (['accept', 'refuse', 'destroy'] as $route) {
            $rows["$route: comprovante do próprio devedor (sem confirmed_by)"] = [$route, 'own_null', 422];
            $rows["$route: comprovante do próprio devedor (confirmed_by = devedor)"] = [$route, 'own_self', 422];
        }

        return $rows;
    }

    /** @dataProvider stateMatrix */
    public function test_the_state_of_the_cycle_and_of_the_proof_is_enforced(string $route, string $state, int $expected): void
    {
        $s = $this->scenario(closeCycle: $state !== 'open_cycle');
        $body = $this->pair($s);

        switch ($state) {
            case 'sealed_cycle':
                GroupCycleSnapshot::where('group_id', $s['group']->id)->where('cycle_start', '2026-08-01')->update(['settled_at' => $this->proofSentAt()]);
                break;
            case 'accepted':
                $s['proof']->update(['accepted_at' => $this->proofSentAt()]);
                break;
            case 'refused':
                $s['proof']->update(['refused_at' => $this->proofSentAt()]);
                break;
            case 'missing':
                $s['proof']->delete();
                break;
            case 'other_cycle':
                $body['cycles_ago'] = '1';
                break;
            case 'own_null':
                $s['proof']->update(['confirmed_by' => null]);
                break;
            case 'own_self':
                $s['proof']->update(['confirmed_by' => $s['debtor']->id]);
                break;
        }

        // Quem deveria poder: o credor decide, quem enviou exclui. O comprovante do devedor não tem
        // "quem enviou": quem o exclui, em tese, é o próprio devedor.
        $caller = match (true) {
            $route !== 'destroy' => $s['creditor'],
            in_array($state, ['own_null', 'own_self'], true) => $s['debtor'],
            default => $s['responsible'],
        };
        $before = $this->world();

        $response = $this->decide($route, $caller, $s['group'], $body);

        $response->assertStatus($expected);
        if ($expected === 200) {
            $this->assertNull($s['proof']->fresh());

            return;
        }

        $this->assertSame($before, $this->world(), 'nada foi escrito');
    }

    /** A ordem de F3 e F4 em `destroy`: o ciclo e a existência vêm **antes** do 403 (documentado no controller). */
    public function test_destroy_orders_cycle_and_existence_before_the_forbidden(): void
    {
        $s = $this->scenario(closeCycle: false);
        $this->decide('destroy', $s['third'], $s['group'], $this->pair($s))->assertStatus(422); // ciclo aberto, antes do 403

        $this->closeAugustCycle($s['group']);
        $s['proof']->delete();
        $this->decide('destroy', $s['third'], $s['group'], $this->pair($s))->assertNotFound(); // sem comprovante, antes do 403
    }

    // --- R1: o que `pays-for` escreve --------------------------------------

    /** @return array<string, array{0: string}> */
    public static function memberRoles(): array
    {
        return [
            'o credor' => ['creditor'],
            'o devedor' => ['debtor'],
            'quem enviou o comprovante' => ['responsible'],
            'outro responsável' => ['other_responsible'],
            'um terceiro' => ['third'],
            'o criador do grupo, sem poder especial' => ['creator'],
        ];
    }

    /**
     * Qualquer membro escolhe a **própria** lista e só ela: os vínculos dos outros (de outros membros,
     * de outros grupos e os que têm quem chama como **devedor**) ficam como estavam, e os campos
     * estranhos do corpo (`responsible_user_id`, `user_id`, `group_id`, `created_by`) não valem nada.
     *
     * @dataProvider memberRoles
     */
    public function test_any_member_writes_only_their_own_list(string $role): void
    {
        $s = $this->scenario();
        $caller = $s[$role];
        $target = $s['creditor']->id === $caller->id ? $s['third'] : $s['creditor'];

        $otherGroup = Group::create(['name' => 'Outro grupo']);
        $otherGroup->members()->attach(array_values(array_unique([$caller->id, $target->id, $s['third']->id])));
        // Vínculos que não são de quem chama: o dele mesmo em **outro grupo**, o de um estranho a ele neste
        // grupo e, salvo para o próprio terceiro, um em que quem chama é o **devedor** (o responsável é o
        // terceiro). O cenário já tem os dois responsáveis do devedor, que também são "dos outros".
        $stranger = $caller->id === $s['other_responsible']->id ? $s['responsible'] : $s['other_responsible'];
        $foreign = [
            GroupMemberResponsible::create(['group_id' => $otherGroup->id, 'user_id' => $target->id, 'responsible_user_id' => $caller->id, 'created_by' => $caller->id]),
            GroupMemberResponsible::create(['group_id' => $s['group']->id, 'user_id' => $s['creator']->id, 'responsible_user_id' => $stranger->id, 'created_by' => $stranger->id]),
        ];
        $asDebtor = null;
        if ($caller->id !== $s['third']->id) {
            $asDebtor = GroupMemberResponsible::create(['group_id' => $s['group']->id, 'user_id' => $caller->id, 'responsible_user_id' => $s['third']->id, 'created_by' => $caller->id]);
        }
        $othersQuery = fn () => GroupMemberResponsible::where(function ($query) use ($caller, $s) {
            $query->where('group_id', '!=', $s['group']->id)->orWhere('responsible_user_id', '!=', $caller->id);
        })->orderBy('id')->get()->map->getAttributes()->all();
        $othersBefore = $othersQuery();

        $response = $this->paysFor($caller, $s['group'], [
            'debtor_user_ids' => [(string) $target->id],
            'responsible_user_id' => $s['creator']->id,
            'user_id' => $s['third']->id,
            'group_id' => $otherGroup->id,
            'created_by' => $s['third']->id,
        ]);

        $response->assertOk()->assertJsonPath('pays_for.0.id', $target->id);
        $own = GroupMemberResponsible::where('group_id', $s['group']->id)->where('responsible_user_id', $caller->id)->get();
        $this->assertSame([$target->id], $own->pluck('user_id')->map(fn ($id) => (int) $id)->all(), 'a lista dele é a pedida, na rota');
        $this->assertSame([$caller->id], $own->pluck('created_by')->map(fn ($id) => (int) $id)->unique()->values()->all(), 'created_by é quem chamou, não o do corpo');

        $this->assertSame($othersBefore, $othersQuery(), 'os vínculos dos outros não mudaram, nem um campo');

        // E esvaziar a lista só larga os papéis de quem chama, na rota.
        $this->paysFor($caller, $s['group'], ['debtor_user_ids' => []])->assertOk();
        $this->assertSame(0, GroupMemberResponsible::where('group_id', $s['group']->id)->where('responsible_user_id', $caller->id)->count());
        $this->assertNotNull($foreign[0]->fresh(), 'o vínculo do mesmo responsável em outro grupo fica');
        $this->assertNotNull($foreign[1]->fresh(), 'o vínculo de um terceiro fica');
        if ($asDebtor !== null) {
            $this->assertNotNull($asDebtor->fresh(), 'quem chama como devedor não perde os responsáveis que tem');
        }
    }

    public function test_pays_for_is_404_for_a_non_member_a_missing_a_deleted_and_a_malformed_group(): void
    {
        $s = $this->scenario();
        $body = ['debtor_user_ids' => [(string) $s['debtor']->id]];
        $before = $this->world();

        $this->paysFor($s['outsider'], $s['group'], $body)->assertNotFound();
        $this->paysFor($s['creditor'], 99999999, $body)->assertNotFound();
        $this->paysFor($s['creditor'], $s['group']->id.'abc', $body)->assertNotFound();
        $s['group']->update(['deleted' => true]);
        $this->paysFor($s['creditor'], $s['group'], $body)->assertNotFound();

        $this->assertSame($before, $this->world());
    }

    public function test_pays_for_refuses_the_invalid_lists_with_422_and_writes_nothing(): void
    {
        $s = $this->scenario();
        $outsider = $s['outsider'];
        $before = $this->world();

        foreach ([
            'a si mesmo' => [(string) $s['creditor']->id],
            'repetido' => [(string) $s['debtor']->id, (string) $s['debtor']->id],
            'quem não é membro' => [(string) $outsider->id],
            'um id que não existe' => ['999999999'],
            'texto' => ['abc'],
        ] as $why => $ids) {
            $this->paysFor($s['creditor'], $s['group'], ['debtor_user_ids' => $ids])->assertStatus(422);
        }
        $this->paysFor($s['creditor'], $s['group'], [])->assertStatus(422);
        // O teto vem da validação do corpo (o campo é apontado), não de o service recusar quem não é membro.
        $this->paysFor($s['creditor'], $s['group'], ['debtor_user_ids' => range(1, 51)])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('debtor_user_ids');

        $this->assertSame($before, $this->world(), 'a lista inválida não muda nada');
    }

    // --- R5: o comprovante de responsável só sela depois do aceite -----------

    private function statusOfProof(Group $group): string
    {
        return SettlementConfirmation::where('group_id', $group->id)->firstOrFail()->status();
    }

    /**
     * O caminho inteiro pela API, sem montar linha à mão: quem paga se declara, envia o comprovante, o
     * ciclo (quitado) **não** sela, ninguém além do credor decide, a recusa devolve a pendente, o reenvio
     * volta a aguardar e só o aceite sela.
     */
    public function test_a_responsibles_proof_only_seals_after_the_creditor_accepts(): void
    {
        $s = $this->scenario();
        $s['proof']->delete();
        GroupMemberResponsible::where('group_id', $s['group']->id)->delete();

        $this->paysFor($s['responsible'], $s['group'], ['debtor_user_ids' => [(string) $s['debtor']->id]])->assertOk();
        $this->confirmAs($s['responsible'], $s['group'], $s['creditor'], $s['debtor']->id)->assertOk();

        $this->assertSame('awaiting_acceptance', $this->statusOfProof($s['group']));
        $this->assertNull($this->sealedSnapshot($s['group']), 'o comprovante de responsável não sela antes do aceite');

        foreach (['debtor', 'responsible', 'other_responsible', 'third', 'creator'] as $role) {
            $this->decide('accept', $s[$role], $s['group'], $this->pair($s))->assertForbidden();
        }
        $this->assertNull($this->sealedSnapshot($s['group']));

        // Recusa: volta a pendente e continua sem selar.
        $this->decide('refuse', $s['creditor'], $s['group'], $this->pair($s))->assertOk();
        $this->assertSame('refused', $this->statusOfProof($s['group']));
        $this->assertNull($this->sealedSnapshot($s['group']), 'recusado não sela');

        // Reenvia: volta a aguardar (não a aceito) e continua sem selar.
        $this->confirmAs($s['responsible'], $s['group'], $s['creditor'], $s['debtor']->id)->assertOk();
        $this->assertSame('awaiting_acceptance', $this->statusOfProof($s['group']));
        $this->assertNull($this->sealedSnapshot($s['group']));

        // Aceite do credor: sela, e depois ninguém muda mais nada (R8 e R9).
        $this->decide('accept', $s['creditor'], $s['group'], $this->pair($s))->assertOk();
        $this->assertSame('accepted', $this->statusOfProof($s['group']));
        $this->assertNotNull($this->sealedSnapshot($s['group']), 'o aceite quitou o ciclo');

        $after = $this->world();
        $this->decide('refuse', $s['creditor'], $s['group'], $this->pair($s))->assertStatus(422);
        $this->decide('destroy', $s['responsible'], $s['group'], $this->pair($s))->assertStatus(422);
        $this->confirmAs($s['responsible'], $s['group'], $s['creditor'], $s['debtor']->id)->assertStatus(422);
        $this->assertSame($after, $this->world());
    }

    /** O contraste da R5 (D6): o comprovante que o próprio devedor envia vale na hora, como sempre valeu. */
    public function test_the_debtors_own_proof_still_seals_at_once(): void
    {
        $s = $this->scenario();
        $s['proof']->delete();

        $this->confirmAs($s['debtor'], $s['group'], $s['creditor'])->assertOk();

        $this->assertSame('sent', $this->statusOfProof($s['group']));
        $this->assertNotNull($this->sealedSnapshot($s['group']), 'sem passar pelo aceite');
    }

    /**
     * O ciclo selado é histórico imutável (R9) mesmo quando o comprovante selado é o do devedor, que nunca
     * passou pelo aceite: nem ele nem quem paga por ele o substituem depois. É a única forma de o reenvio
     * chegar até a gravação, então é o que isola a guarda do ciclo encerrado em `confirmSettlement`.
     */
    public function test_a_sealed_cycle_takes_no_resend_even_over_the_debtors_own_proof(): void
    {
        $s = $this->scenario();
        $s['proof']->delete();

        $this->confirmAs($s['debtor'], $s['group'], $s['creditor'])->assertOk();
        $this->assertNotNull($this->sealedSnapshot($s['group']), 'o comprovante do devedor selou o ciclo');

        $after = $this->world();
        $this->confirmAs($s['debtor'], $s['group'], $s['creditor'])->assertStatus(422);
        $this->confirmAs($s['responsible'], $s['group'], $s['creditor'], $s['debtor']->id)->assertStatus(422);
        $this->assertSame($after, $this->world());
    }
}
