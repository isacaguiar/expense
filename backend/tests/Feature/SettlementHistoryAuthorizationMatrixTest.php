<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\GroupMemberResponsible;
use App\Models\Notification;
use App\Models\SettlementConfirmation;
use App\Models\SettlementProofEntry;
use App\Models\User;
use App\Services\CycleSealingService;
use App\Support\ProofStorage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\Concerns\BuildsThirdPartyProofScenarios;
use Tests\TestCase;

/**
 * Matriz de autorização da feature 20261114-historico-de-comprovantes-e-justificativa (TASK-628, plan §10,
 * specify R1 a R12, CA-7 e CA-9): a rota nova `POST /groups/{id}/settlements/complement`, as duas alteradas
 * (`POST .../settlements/refuse` com e sem `reason` e `DELETE .../settlements/confirmation`, que agora
 * **retira**), o download `settlement_history` e a leitura de `refusedProof` e `proofHistory` nos três
 * pontos que respondem ao usuário (`summary`, `close` e `reopen`).
 *
 * Os papéis são os do plan: o credor, o devedor, quem enviou o comprovante (vinculado ao devedor), **outro**
 * responsável do mesmo devedor, quem perdeu o vínculo, um terceiro do grupo, o criador do grupo e quem não é
 * membro; mais o grupo excluído e o estado (ciclo aberto, fechado, encerrado e fechado por data; par sem
 * comprovante). **Em toda célula de recusa (403, 404 ou 422) nada é escrito**: `world()` (comprovantes,
 * entradas do histórico, vínculos, avisos, selos e arquivos) é igual antes e depois. Cada célula de sucesso é
 * o controle positivo da de recusa ao lado e afirma o que **foi** escrito.
 *
 * Os testes mais finos de cada regra continuam nos arquivos das tasks (`SettlementComplementTest`,
 * `SettlementProofVisibilityTest`, `ProofDownloadHistoryTest`...); este junta a matriz que o plan §10 pede.
 */
class SettlementHistoryAuthorizationMatrixTest extends TestCase
{
    use BuildsSettlementScenarios;
    use BuildsThirdPartyProofScenarios;
    use DatabaseTransactions;

    private const REASON = 'foto cortada';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Grupo de agosto/2026 com o criador, o credor, o devedor, quem enviou o comprovante (vinculado ao
     * devedor), outro responsável do mesmo devedor, quem já teve o vínculo e o perdeu, um terceiro e quem
     * não é membro. Por padrão o comprovante é o de quem enviou, aguardando o aceite; `$ownProof` o troca
     * pelo do próprio devedor (`sent`), o único que se complementa. O arquivo está no disco falso.
     *
     * @return array{group: Group, creditor: User, debtor: User, responsible: User, other_responsible: User, former: User, third: User, creator: User, outsider: User, proof: SettlementConfirmation}
     */
    private function scenario(bool $ownProof = false, bool $closeCycle = true): array
    {
        Storage::fake(ProofStorage::DISK);
        [$group, $creditor, $debtor, $responsible, $proof, $creator] = $this->thirdPartyScenario(paid: true, closeCycle: $closeCycle);
        [$otherResponsible, $former, $third, $outsider] = User::factory()->count(4)->create()->all();
        $group->members()->attach([$otherResponsible->id, $former->id, $third->id]);
        foreach ([$responsible, $otherResponsible] as $linked) {
            GroupMemberResponsible::create(['group_id' => $group->id, 'user_id' => $debtor->id, 'responsible_user_id' => $linked->id]);
        }
        GroupMemberResponsible::create(['group_id' => $group->id, 'user_id' => $debtor->id, 'responsible_user_id' => $former->id])->delete();

        if ($ownProof) {
            $proof->update(['confirmed_by' => null, 'note' => 'pix de agosto']);
        }
        Storage::disk(ProofStorage::DISK)->put($proof->proof_path, 'conteudo do comprovante');

        return [
            'group' => $group,
            'creditor' => $creditor,
            'debtor' => $debtor,
            'responsible' => $responsible,
            'other_responsible' => $otherResponsible,
            'former' => $former,
            'third' => $third,
            'creator' => $creator,
            'outsider' => $outsider,
            'proof' => $proof,
        ];
    }

    /** Tudo o que as rotas desta feature poderiam gravar. Igual antes e depois de toda recusa. */
    private function world(): array
    {
        return [
            'confirmations' => SettlementConfirmation::orderBy('id')->get()->map->getAttributes()->all(),
            'entries' => DB::table('ex_settlement_proof_entries')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'links' => GroupMemberResponsible::orderBy('id')->get()->map->getAttributes()->all(),
            'notifications' => Notification::count(),
            'snapshots' => GroupCycleSnapshot::orderBy('id')->get()->map->getAttributes()->all(),
            'files' => Storage::disk(ProofStorage::DISK)->allFiles(),
        ];
    }

    /** O corpo do par como num pedido HTTP de verdade: ids em texto. */
    private function pair(array $s, array $extra = []): array
    {
        return ['from_user_id' => (string) $s['debtor']->id, 'to_user_id' => (string) $s['creditor']->id] + $extra;
    }

    private function act(string $route, ?User $caller, Group|int|string $group, array $body): TestResponse
    {
        $groupId = $group instanceof Group ? $group->id : $group;
        $http = $this->withHeader('Accept', 'application/json');
        if ($caller !== null) {
            $http = $http->withToken($this->tokenFor($caller));
        }

        return match ($route) {
            'complement' => $http->post("/api/groups/{$groupId}/settlements/complement", $body + [
                'comprovante' => UploadedFile::fake()->image('correcao.jpg'),
                'justificativa' => 'a foto anterior era de outro mês',
            ]),
            'accept' => $http->postJson("/api/groups/{$groupId}/settlements/accept", $body),
            'refuse' => $http->postJson("/api/groups/{$groupId}/settlements/refuse", $body),
            'destroy' => $http->deleteJson("/api/groups/{$groupId}/settlements/confirmation?".http_build_query($body)),
        };
    }

    private function addEntries(array $s, int $count, string $kind = SettlementProofEntry::KIND_REPLACED): void
    {
        for ($i = 0; $i < $count; $i++) {
            SettlementProofEntry::create([
                'group_id' => $s['group']->id,
                'cycle_start' => '2026-08-01',
                'from_user_id' => $s['debtor']->id,
                'to_user_id' => $s['creditor']->id,
                'kind' => $kind,
                'sent_by' => $s['debtor']->id,
                'status_at_exit' => 'sent',
                'amount' => 100,
                'proof_path' => "comprovantes/{$s['group']->id}/velho{$i}.jpg",
            ]);
        }
    }

    /**
     * Põe o cenário no estado `$state` e devolve o que vai a mais no corpo (`cycles_ago`). O ciclo aberto é
     * decidido antes, ao montar o cenário (`scenarioFor`).
     *
     * @return array<string, string>
     */
    private function applyState(array $s, string $state): array
    {
        $at = $this->proofSentAt();

        switch ($state) {
            case 'sealed':
                GroupCycleSnapshot::where('group_id', $s['group']->id)->where('cycle_start', '2026-08-01')->update(['settled_at' => $at]);
                break;
            case 'awaiting':
                $s['proof']->update(['confirmed_by' => $s['responsible']->id]);
                break;
            case 'accepted':
                $s['proof']->update(['confirmed_by' => $s['responsible']->id, 'accepted_at' => $at]);
                break;
            case 'refused':
                $s['proof']->update(['confirmed_by' => $s['responsible']->id, 'refused_at' => $at, 'refusal_reason' => self::REASON]);
                break;
            case 'own':
                $s['proof']->update(['confirmed_by' => null]);
                break;
            case 'missing':
                $s['proof']->delete();
                break;
            case 'other_cycle':
                return ['cycles_ago' => '1'];
            case 'closed_by_date':
                Carbon::setTestNow('2026-09-20');

                return ['cycles_ago' => '1'];
            case 'deleted_group':
                $s['group']->update(['deleted' => true]);
                break;
            case 'full':
                // O orçamento dos complementos (D13, TASK-638): só os complementos o enchem.
                $this->addEntries($s, 10, SettlementProofEntry::KIND_COMPLEMENT);
                break;
        }

        return [];
    }

    private function scenarioFor(string $state, bool $ownProof = false): array
    {
        return $this->scenario(ownProof: $ownProof, closeCycle: $state !== 'open_cycle');
    }

    // === complement ==========================================================

    /** @return array<string, array{0: string, 1: int}> */
    public static function complementRoles(): array
    {
        return [
            'quem paga pelo devedor complementa' => ['responsible', 201],
            'outro responsável do mesmo devedor também' => ['other_responsible', 201],
            'o devedor não (ele mesmo substitui o dele)' => ['debtor', 403],
            'o credor nunca' => ['creditor', 403],
            'o credor nunca, nem se for também responsável do devedor' => ['creditor_linked', 403],
            'o devedor não, nem com um vínculo consigo mesmo (a API não o cria)' => ['debtor_self_linked', 403],
            'quem perdeu o vínculo não' => ['former', 403],
            'um terceiro do grupo não' => ['third', 403],
            'o criador do grupo não' => ['creator', 403],
            'quem não é membro recebe 404, não 403' => ['outsider', 404],
        ];
    }

    /** @dataProvider complementRoles */
    public function test_complement_by_role(string $role, int $expected): void
    {
        $s = $this->scenario(ownProof: true);
        // Os dois casos de quem **tem** o vínculo e mesmo assim não pode: o 403 não pode depender de o devedor
        // nunca ter um vínculo consigo mesmo nem de o credor nunca ser responsável do devedor.
        if ($role === 'creditor_linked') {
            GroupMemberResponsible::create(['group_id' => $s['group']->id, 'user_id' => $s['debtor']->id, 'responsible_user_id' => $s['creditor']->id]);
            $s[$role] = $s['creditor'];
        }
        if ($role === 'debtor_self_linked') {
            GroupMemberResponsible::create(['group_id' => $s['group']->id, 'user_id' => $s['debtor']->id, 'responsible_user_id' => $s['debtor']->id]);
            $s[$role] = $s['debtor'];
        }
        $before = $this->world();

        $this->act('complement', $s[$role], $s['group'], $this->pair($s))->assertStatus($expected);

        $after = $this->world();
        if ($expected !== 201) {
            $this->assertSame($before, $after, 'nada foi escrito: nem arquivo, nem entrada, nem aviso');

            return;
        }

        $entry = SettlementProofEntry::where('group_id', $s['group']->id)->sole();
        $this->assertSame(SettlementProofEntry::KIND_COMPLEMENT, $entry->kind);
        $this->assertSame($s[$role]->id, (int) $entry->sent_by);
        $this->assertSame($before['confirmations'], $after['confirmations'], 'a linha atual não muda (R3)');
        $this->assertSame($before['snapshots'], $after['snapshots']);
        $this->assertSame($before['links'], $after['links']);
        $this->assertCount(count($before['files']) + 1, $after['files'], 'o arquivo novo e todos os que já havia');
        $recipients = Notification::where('type', 'settlement_complement_added')->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame(collect([$s['creditor']->id, $s['debtor']->id])->sort()->values()->all(), $recipients, 'só o credor e o devedor');
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function complementStates(): array
    {
        return [
            'ciclo ainda aberto' => ['open_cycle', 422],
            'ciclo fechado manualmente' => ['closed', 201],
            'ciclo encerrado (selado): a exceção à R9 (D7-B)' => ['sealed', 201],
            'ciclo fechado por data (cycles_ago=1)' => ['closed_by_date', 201],
            'comprovante aguardando aceite' => ['awaiting', 422],
            'comprovante recusado' => ['refused', 422],
            'comprovante aceito' => ['accepted', 422],
            'par sem comprovante' => ['missing', 404],
            'ciclo sem comprovante (cycles_ago=1)' => ['other_cycle', 404],
            'teto de 10 complementos (R8, D13)' => ['full', 422],
            'grupo excluído' => ['deleted_group', 404],
        ];
    }

    /** @dataProvider complementStates */
    public function test_complement_by_state(string $state, int $expected): void
    {
        $s = $this->scenarioFor($state, ownProof: true);
        $extra = $this->applyState($s, $state);
        $before = $this->world();

        $this->act('complement', $s['responsible'], $s['group'], $this->pair($s, $extra))->assertStatus($expected);

        $after = $this->world();
        if ($expected !== 201) {
            $this->assertSame($before, $after, 'nada foi escrito: nem arquivo, nem entrada, nem aviso');

            return;
        }

        $this->assertCount(count($before['entries']) + 1, $after['entries'], 'uma entrada, e só ela');
        $this->assertSame($before['confirmations'], $after['confirmations'], 'a linha atual não muda, nem em ciclo encerrado');
        $this->assertSame($before['snapshots'], $after['snapshots'], 'o selo e a foto do ciclo ficam como estão');
        $this->assertCount(count($before['files']) + 1, $after['files']);
    }

    public function test_complement_without_a_token_is_401_and_writes_nothing(): void
    {
        $s = $this->scenario(ownProof: true);
        $before = $this->world();

        $this->act('complement', null, $s['group'], $this->pair($s))->assertUnauthorized();

        $this->assertSame($before, $this->world());
    }

    /** O 403 vem antes de o ciclo ou o estado serem olhados: o sem poder nunca descobre nada pelo 422. */
    public function test_complement_forbidden_comes_before_the_state_checks(): void
    {
        $s = $this->scenarioFor('open_cycle');
        $s['proof']->update(['accepted_at' => $this->proofSentAt()]);
        $before = $this->world();

        foreach (['debtor', 'creditor', 'third', 'creator', 'former'] as $role) {
            // Ciclo aberto **e** comprovante aceito: se a rota olhasse o estado antes do 403, daria 422.
            $this->act('complement', $s[$role], $s['group'], $this->pair($s))->assertForbidden();
        }

        $this->assertSame($before, $this->world());
    }

    // === refuse (com e sem motivo) ============================================

    /** @return array<string, array{0: string, 1: ?string, 2: int}> */
    public static function refuseRoles(): array
    {
        $rows = [];
        foreach (['sem motivo' => null, 'com motivo' => self::REASON] as $label => $reason) {
            $rows["$label: o credor recusa"] = ['creditor', $reason, 200];
            foreach ([
                'debtor' => 'o devedor, de quem é o acerto, não',
                'responsible' => 'quem enviou não decide sobre o que enviou',
                'other_responsible' => 'outro responsável do mesmo devedor não',
                'former' => 'quem perdeu o vínculo não',
                'third' => 'um terceiro do grupo não',
                'creator' => 'o criador do grupo não é credor, então não',
            ] as $role => $text) {
                $rows["$label: $text"] = [$role, $reason, 403];
            }
            $rows["$label: quem não é membro recebe 404"] = ['outsider', $reason, 404];
        }

        return $rows;
    }

    /** @dataProvider refuseRoles */
    public function test_refuse_by_role(string $role, ?string $reason, int $expected): void
    {
        $s = $this->scenario();
        $before = $this->world();

        $this->act('refuse', $s[$role], $s['group'], $this->pair($s, $reason === null ? [] : ['reason' => $reason]))->assertStatus($expected);

        $after = $this->world();
        if ($expected !== 200) {
            $this->assertSame($before, $after, 'nada foi escrito, o motivo incluído');
            $this->assertNull($s['proof']->fresh()->refusal_reason);

            return;
        }

        $row = $s['proof']->fresh();
        $this->assertSame('refused', $row->status());
        $this->assertSame($reason, $row->refusal_reason, 'o motivo fica na linha atual (R6)');
        $this->assertSame($before['entries'], $after['entries'], 'recusar não escreve entrada: o recusado segue na linha atual (R6)');
        $this->assertSame($before['files'], $after['files']);
        $this->assertEquals($before['confirmations'][0]['confirmed_at'], $row->getRawOriginal('confirmed_at'), 'recusar não reescreve confirmed_at (item 147, R11)');
        foreach (Notification::where('type', 'settlement_refused')->get() as $notice) {
            $this->assertArrayNotHasKey('reason', $notice->data, 'o motivo não vai para o sino (D4-A)');
            $this->assertStringNotContainsString(self::REASON, json_encode($notice->data));
        }
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function refuseStates(): array
    {
        return [
            'ciclo ainda aberto' => ['open_cycle', 422],
            'ciclo encerrado (selado) é imutável (R5)' => ['sealed', 422],
            'comprovante já aceito é definitivo' => ['accepted', 422],
            'comprovante já recusado' => ['refused', 422],
            'comprovante do próprio devedor não passa pelo aceite' => ['own', 422],
            'par sem comprovante' => ['missing', 404],
            'ciclo sem comprovante (cycles_ago=1)' => ['other_cycle', 404],
            'grupo excluído' => ['deleted_group', 404],
        ];
    }

    /** @dataProvider refuseStates */
    public function test_refuse_with_a_reason_by_state(string $state, int $expected): void
    {
        $s = $this->scenarioFor($state);
        $extra = $this->applyState($s, $state);
        $before = $this->world();

        $this->act('refuse', $s['creditor'], $s['group'], $this->pair($s, $extra + ['reason' => 'outro motivo']))->assertStatus($expected);

        $this->assertSame($before, $this->world(), 'nada foi escrito: o motivo novo não sobrescreve nada');
    }

    public function test_refuse_without_a_token_is_401_and_writes_nothing(): void
    {
        $s = $this->scenario();
        $before = $this->world();

        $this->act('refuse', null, $s['group'], $this->pair($s, ['reason' => self::REASON]))->assertUnauthorized();

        $this->assertSame($before, $this->world());
    }

    // === destroy (retirar) =====================================================

    /** @return array<string, array{0: string, 1: int}> */
    public static function destroyRoles(): array
    {
        return [
            'quem enviou retira' => ['responsible', 200],
            'o devedor retira' => ['debtor', 200],
            'o credor não retira' => ['creditor', 403],
            'outro responsável do mesmo devedor não' => ['other_responsible', 403],
            'quem perdeu o vínculo não' => ['former', 403],
            'um terceiro do grupo não' => ['third', 403],
            'o criador do grupo não' => ['creator', 403],
            'quem não é membro recebe 404' => ['outsider', 404],
        ];
    }

    /** @dataProvider destroyRoles */
    public function test_withdraw_by_role(string $role, int $expected): void
    {
        $s = $this->scenario();
        $before = $this->world();

        $this->act('destroy', $s[$role], $s['group'], $this->pair($s))->assertStatus($expected);

        $after = $this->world();
        if ($expected !== 200) {
            $this->assertSame($before, $after, 'nada foi escrito nem apagado, o arquivo incluído');
            Storage::disk(ProofStorage::DISK)->assertExists($s['proof']->proof_path);

            return;
        }

        $this->assertNull($s['proof']->fresh(), 'a linha atual saiu (D7-A, o único DELETE físico)');
        $entry = SettlementProofEntry::where('group_id', $s['group']->id)->sole();
        $this->assertSame(SettlementProofEntry::KIND_WITHDRAWN, $entry->kind);
        $this->assertSame($s['proof']->proof_path, $entry->proof_path, 'a entrada aponta para o arquivo de sempre');
        $this->assertSame($s['responsible']->id, (int) $entry->sent_by, 'quem enviou o comprovante, não quem o retirou');
        $this->assertSame($before['files'], $after['files'], 'nenhum arquivo foi apagado (R2)');
        Storage::disk(ProofStorage::DISK)->assertExists($s['proof']->proof_path);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function withdrawStates(): array
    {
        return [
            'ciclo ainda aberto' => ['open_cycle', 422],
            'ciclo encerrado (selado) é imutável (R5)' => ['sealed', 422],
            'comprovante aceito é definitivo (R4)' => ['accepted', 422],
            'comprovante do próprio devedor não sai por aqui' => ['own', 422],
            'par sem comprovante' => ['missing', 404],
            'ciclo sem comprovante (cycles_ago=1)' => ['other_cycle', 404],
            'grupo excluído' => ['deleted_group', 404],
            'comprovante recusado sai, com o motivo, para o histórico' => ['refused', 200],
        ];
    }

    /** @dataProvider withdrawStates */
    public function test_withdraw_by_state(string $state, int $expected): void
    {
        $s = $this->scenarioFor($state);
        $extra = $this->applyState($s, $state);
        $before = $this->world();
        $caller = $state === 'own' ? $s['debtor'] : $s['responsible'];

        $this->act('destroy', $caller, $s['group'], $this->pair($s, $extra))->assertStatus($expected);

        $after = $this->world();
        if ($expected !== 200) {
            $this->assertSame($before, $after, 'nada foi escrito nem apagado');

            return;
        }

        $entry = SettlementProofEntry::where('group_id', $s['group']->id)->sole();
        $this->assertSame('refused', $entry->status_at_exit);
        $this->assertSame(self::REASON, $entry->refusal_reason, 'o motivo da recusa vai junto');
        $this->assertSame($before['files'], $after['files']);
    }

    public function test_withdraw_without_a_token_is_401_and_writes_nothing(): void
    {
        $s = $this->scenario();
        $before = $this->world();

        $this->act('destroy', null, $s['group'], $this->pair($s))->assertUnauthorized();

        $this->assertSame($before, $this->world());
    }

    // === R4: o aceito é definitivo ===========================================

    public function test_an_accepted_proof_is_never_replaced_or_withdrawn(): void
    {
        $s = $this->scenario();
        $this->applyState($s, 'accepted');
        $before = $this->world();

        $this->confirmAs($s['responsible'], $s['group'], $s['creditor'], $s['debtor']->id)->assertStatus(422)->assertJson(['error' => 'Este comprovante já foi aceito.']);
        $this->confirmAs($s['debtor'], $s['group'], $s['creditor'])->assertStatus(422)->assertJson(['error' => 'Este comprovante já foi aceito.']);
        $this->act('destroy', $s['responsible'], $s['group'], $this->pair($s))->assertStatus(422);
        $this->act('destroy', $s['debtor'], $s['group'], $this->pair($s))->assertStatus(422);

        $this->assertSame($before, $this->world(), 'o aceito é definitivo: nenhuma entrada, nenhum arquivo apagado (R4)');
    }

    // === R5: o ciclo encerrado e as quatro ações =============================

    /**
     * Ciclo encerrado (selado) é histórico imutável: aceitar, recusar, retirar e reenviar dão 422 e **não
     * escrevem nada** (nem entrada, nem arquivo, nem aviso); só o complemento passa (D7-B). O comprovante é
     * o do próprio devedor, que selou o ciclo, para que **o reenvio chegue até a gravação** e só a guarda do
     * ciclo o barre.
     */
    public function test_a_sealed_cycle_takes_none_of_the_four_actions_but_still_takes_the_complement(): void
    {
        $s = $this->scenario(ownProof: true);
        $this->applyState($s, 'sealed');
        $before = $this->world();

        // Aceitar, recusar e retirar sobre o comprovante do devedor já dão 422 por outro motivo (ele não passa
        // pelo aceite): o ciclo encerrado é o que o 422 de `decidableCycle` diz, então confere-se a mensagem.
        $this->act('accept', $s['creditor'], $s['group'], $this->pair($s))->assertStatus(422)->assertJson(['error' => 'Esta competência já foi encerrada.']);
        $this->act('refuse', $s['creditor'], $s['group'], $this->pair($s, ['reason' => self::REASON]))->assertStatus(422)->assertJson(['error' => 'Esta competência já foi encerrada.']);
        $this->act('destroy', $s['debtor'], $s['group'], $this->pair($s))->assertStatus(422)->assertJson(['error' => 'Esta competência já foi encerrada.']);
        $this->confirmAs($s['debtor'], $s['group'], $s['creditor'])->assertStatus(422)->assertJson(['error' => 'Esta competência já foi encerrada.']);
        $this->confirmAs($s['responsible'], $s['group'], $s['creditor'], $s['debtor']->id)->assertStatus(422)->assertJson(['error' => 'Esta competência já foi encerrada.']);

        $this->assertSame($before, $this->world(), 'nada foi escrito pelas quatro ações');

        $this->act('complement', $s['responsible'], $s['group'], $this->pair($s))->assertCreated();
        $this->assertCount(1, SettlementProofEntry::where('group_id', $s['group']->id)->get(), 'só o complemento entrou');
    }

    // === a leitura: summary, close e reopen ===================================

    /**
     * Agosto/2026 com o comprovante de quem enviou **recusado** (motivo e arquivo) e três entradas no
     * histórico: `a` (substituída) e `c` (complemento) de quem enviou, e `b` (retirada) de **outro**
     * responsável.
     *
     * @return array{0: array, 1: array{a: SettlementProofEntry, b: SettlementProofEntry, c: SettlementProofEntry}}
     */
    private function readScenario(bool $closeCycle): array
    {
        $s = $this->scenario(closeCycle: $closeCycle);
        $s['proof']->update(['note' => 'foto do pix', 'refused_at' => '2026-08-21 09:00:00', 'refusal_reason' => self::REASON]);

        $entries = [];
        foreach ([['a', 'replaced', $s['responsible']], ['b', 'withdrawn', $s['other_responsible']], ['c', 'complement', $s['responsible']]] as [$key, $kind, $sender]) {
            $entry = SettlementProofEntry::create([
                'group_id' => $s['group']->id,
                'cycle_start' => '2026-08-01',
                'from_user_id' => $s['debtor']->id,
                'to_user_id' => $s['creditor']->id,
                'kind' => $kind,
                'sent_by' => $sender->id,
                'status_at_exit' => $kind === 'complement' ? null : 'awaiting_acceptance',
                'amount' => 100,
                'proof_path' => "comprovantes/{$s['group']->id}/{$key}.jpg",
                'note' => "nota {$key}",
                'sent_at' => '2026-08-20 10:00:00',
            ]);
            Storage::disk(ProofStorage::DISK)->put($entry->proof_path, "conteudo {$key}");
            $entries[$key] = $entry;
        }

        return [$s, $entries];
    }

    /**
     * O que cada papel lê: `refusedProof` e as entradas do histórico (da mais nova para a mais antiga).
     * Credor e devedor leem tudo; quem enviou, só o que enviou (e o recusado é dele); os outros, nada.
     *
     * @return array<string, array{0: string, 1: string, 2: bool, 3: string}> chamada, papel, vê o recusado, entradas
     */
    public static function readMatrix(): array
    {
        $rows = [];
        foreach (['summary', 'reopen', 'close'] as $call) {
            $rows["$call: o credor lê tudo"] = [$call, 'creditor', true, 'cba'];
            $rows["$call: o devedor lê tudo"] = [$call, 'debtor', true, 'cba'];
            $rows["$call: quem enviou lê só o que enviou"] = [$call, 'responsible', true, 'ca'];
            $rows["$call: outro responsável lê só a entrada que enviou"] = [$call, 'other_responsible', false, 'b'];
            $rows["$call: quem perdeu o vínculo não lê nada"] = [$call, 'former', false, ''];
            $rows["$call: um terceiro do grupo não lê nada"] = [$call, 'third', false, ''];
            $rows["$call: o criador do grupo não lê nada"] = [$call, 'creator', false, ''];
        }

        return $rows;
    }

    private function read(string $call, User $caller, Group $group): TestResponse
    {
        $http = $this->withToken($this->tokenFor($caller));

        return match ($call) {
            'summary' => $http->getJson("/api/groups/{$group->id}/expenses/summary"),
            'close' => $http->postJson("/api/groups/{$group->id}/expenses/close"),
            'reopen' => $http->postJson("/api/groups/{$group->id}/expenses/reopen"),
        };
    }

    /** @dataProvider readMatrix */
    public function test_who_reads_the_history_and_the_refused_proof(string $call, string $role, bool $seesRefused, string $keys): void
    {
        [$s, $entries] = $this->readScenario(closeCycle: $call !== 'close');

        $response = $this->read($call, $s[$role], $s['group'])->assertOk();

        $settlement = collect($response->json('settlements'))->firstWhere('from_user_id', $s['debtor']->id);
        $this->assertSame('refused', $settlement['confirmationStatus']);
        $this->assertSame(
            array_map(fn ($key) => $entries[$key]->id, $keys === '' ? [] : str_split($keys)),
            array_column($settlement['proofHistory'], 'id'),
            'só as entradas que este papel lê, da mais nova para a mais antiga'
        );
        $this->assertSame($seesRefused, $settlement['refusedProof'] !== null);
        if ($seesRefused) {
            $this->assertSame(self::REASON, $settlement['refusedProof']['reason']);
            $this->assertNotNull($settlement['refusedProof']['proofUrl']);
        }
        if ($keys === '') {
            $this->assertStringNotContainsString('settlement_history', $response->getContent(), 'quem não é parte nunca recebe uma URL do histórico (R7)');
        }
        // Nenhuma URL do histórico das entradas que o papel não lê.
        foreach ($entries as $key => $entry) {
            if (! str_contains($keys, $key)) {
                $this->assertStringNotContainsString("/proofs/settlement_history/{$entry->id}", $response->getContent(), "a entrada {$key} não é deste papel");
            }
        }
    }

    /** @return array<string, array{0: string}> */
    public static function readCalls(): array
    {
        return ['summary' => ['summary'], 'close' => ['close'], 'reopen' => ['reopen']];
    }

    /** @dataProvider readCalls */
    public function test_a_non_member_reads_nothing(string $call): void
    {
        [$s] = $this->readScenario(closeCycle: $call !== 'close');
        $before = $this->world();

        $response = $this->read($call, $s['outsider'], $s['group'])->assertNotFound();

        $this->assertStringNotContainsString('settlement_history', $response->getContent());
        $this->assertSame($before, $this->world());
    }

    /** @dataProvider readCalls */
    public function test_a_read_without_a_token_is_401(string $call): void
    {
        [$s] = $this->readScenario(closeCycle: $call !== 'close');

        $http = $this->withHeader('Accept', 'application/json');
        match ($call) {
            'summary' => $http->getJson("/api/groups/{$s['group']->id}/expenses/summary")->assertUnauthorized(),
            'close' => $http->postJson("/api/groups/{$s['group']->id}/expenses/close")->assertUnauthorized(),
            'reopen' => $http->postJson("/api/groups/{$s['group']->id}/expenses/reopen")->assertUnauthorized(),
        };
    }

    /** O ciclo encerrado (selado) também mostra o histórico às partes, e a ninguém mais. */
    public function test_a_sealed_cycle_shows_the_history_to_the_parties_only(): void
    {
        [$s, $entries] = $this->readScenario(closeCycle: true);
        // Selado com o comprovante recusado seria incoerente: o cenário do selo é o do aceite.
        $s['proof']->update(['refused_at' => null, 'refusal_reason' => null, 'accepted_at' => $this->proofSentAt()]);
        $this->assertTrue(app(CycleSealingService::class)->sealIfSettled($s['group'], Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')));

        $ids = fn (User $viewer) => array_column(
            collect($this->read('summary', $viewer, $s['group'])->assertOk()->json('settlements'))->firstWhere('from_user_id', $s['debtor']->id)['proofHistory'],
            'id'
        );

        $this->assertSame([$entries['c']->id, $entries['b']->id, $entries['a']->id], $ids($s['creditor']));
        $this->assertSame([$entries['c']->id, $entries['b']->id, $entries['a']->id], $ids($s['debtor']));
        $this->assertSame([], $ids($s['third']));
        $this->assertSame([], $ids($s['creator']));
    }

    /**
     * Os caminhos que **não** passam o `viewerId` seguem sem detalhe (plan ajuste 4): o relatório de
     * fechamento e o painel pessoal nunca levam `proofHistory`, `refusedProof` nem URL do histórico, nem
     * para o credor.
     */
    public function test_the_endpoints_without_a_viewer_never_carry_the_history(): void
    {
        [$s] = $this->readScenario(closeCycle: true);
        $token = $this->withToken($this->tokenFor($s['creditor']));

        $report = $token->getJson("/api/groups/{$s['group']->id}/expenses/closing-report?cycle_start=2026-08-01")->assertOk();
        $overview = $token->getJson('/api/me/overview')->assertOk();

        foreach ([$report, $overview] as $response) {
            foreach (['proofHistory', 'refusedProof', 'settlement_history'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $response->getContent());
            }
        }
    }

    // === o download ===========================================================

    /** @return array<string, array{0: string}> */
    public static function everyRole(): array
    {
        $roles = ['creditor', 'debtor', 'responsible', 'other_responsible', 'former', 'third', 'creator', 'outsider'];

        return array_combine($roles, array_map(fn ($role) => [$role], $roles));
    }

    /**
     * O Bearer não autoriza o arquivo (a aba do navegador não o manda, ADR-005): sem a assinatura nem as
     * partes o baixam. O que decide quem recebe o link é a emissão, coberta em `test_who_reads_the_history...`.
     *
     * @dataProvider everyRole
     */
    public function test_a_history_file_needs_the_signature_even_with_a_token(string $role): void
    {
        [$s, $entries] = $this->readScenario(closeCycle: true);

        $this->withToken($this->tokenFor($s[$role]))
            ->get("/api/groups/{$s['group']->id}/proofs/settlement_history/{$entries['a']->id}")
            ->assertForbidden();
    }

    public function test_the_url_the_summary_issues_downloads_exactly_that_entry(): void
    {
        [$s, $entries] = $this->readScenario(closeCycle: true);

        $settlement = collect($this->read('summary', $s['creditor'], $s['group'])->assertOk()->json('settlements'))->firstWhere('from_user_id', $s['debtor']->id);

        foreach ($settlement['proofHistory'] as $item) {
            $key = array_search($item['id'], array_map(fn ($entry) => $entry->id, $entries), true);
            // Sem Bearer, como a aba do navegador.
            $response = $this->get($item['proofUrl'])->assertOk();
            $this->assertSame("conteudo {$key}", $response->streamedContent());
        }
    }

    /** A URL de um grupo não abre a entrada de outro: o mesmo 404 de "não existe". */
    public function test_a_signed_url_does_not_cross_groups(): void
    {
        [$s, $entries] = $this->readScenario(closeCycle: true);
        $other = Group::create(['name' => 'Outro grupo']);
        $url = $entries['a']->proofUrl();

        $this->get($url)->assertOk();
        $this->get(str_replace("/groups/{$s['group']->id}/", "/groups/{$other->id}/", $url))->assertForbidden(); // a assinatura cobre a URL inteira
        $signedForOther = URL::temporarySignedRoute('proofs.show', now()->addMinutes(30), [
            'groupId' => $other->id,
            'type' => 'settlement_history',
            'id' => $entries['a']->id,
        ]);
        $this->get($signedForOther)->assertNotFound();
    }
}
