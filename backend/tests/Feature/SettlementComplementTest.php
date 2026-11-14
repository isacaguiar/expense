<?php

namespace Tests\Feature;

use App\Exceptions\SettlementDecisionRefused;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\GroupMemberResponsible;
use App\Models\Notification;
use App\Models\SettlementConfirmation;
use App\Models\SettlementProofEntry;
use App\Models\User;
use App\Services\CycleSealingService;
use App\Services\SettlementConfirmationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\Concerns\BuildsThirdPartyProofScenarios;
use Tests\Concerns\CapturesTransactionQueries;
use Tests\Concerns\FailsToWriteProofs;
use Tests\TestCase;

/**
 * `POST /groups/{id}/settlements/complement` (feature 20261114-historico-de-comprovantes-e-justificativa,
 * plan §5, TASK-626, D3-B, D7-B, D9-A e D10-A): quem paga pelo devedor adiciona uma justificativa com foto
 * sobre o comprovante que o **próprio devedor** enviou. A entrada vai para o histórico; a linha atual, o
 * estado, `countsForSettlement` e o selo **não mudam** (R3), e vale também com o ciclo encerrado (a exceção
 * à R9). Os avisos (TASK-627, plan §5 e R10/R12) estão na seção "os avisos", no fim.
 */
class SettlementComplementTest extends TestCase
{
    use BuildsSettlementScenarios;
    use BuildsThirdPartyProofScenarios;
    use CapturesTransactionQueries;
    use DatabaseTransactions;
    use FailsToWriteProofs;

    // O orçamento próprio dos complementos (D13, TASK-638): a mensagem não é a do teto de quem envia.
    private const LIMIT_MESSAGE = 'O limite de justificativas e comprovantes adicionados a este acerto foi atingido.';

    protected function tearDown(): void
    {
        SettlementProofEntry::flushEventListeners();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * O comprovante do **próprio devedor** (`sent`) de agosto/2026, com o responsável vinculado a ele.
     *
     * @return array{group: Group, creditor: User, debtor: User, responsible: User, proof: SettlementConfirmation, creator: User}
     */
    private function world(bool $paid = false): array
    {
        [$group, $creditor, $debtor, $responsible, $proof, $creator] = $this->linkedScenario($paid);
        $proof->update(['confirmed_by' => null, 'note' => 'pix de agosto']);

        return compact('group', 'creditor', 'debtor', 'responsible', 'proof', 'creator');
    }

    /** @param  array<string, mixed>  $overrides */
    private function complement(User $caller, Group $group, User $debtor, User $creditor, array $overrides = []): TestResponse
    {
        $body = [
            'from_user_id' => (string) $debtor->id,
            'to_user_id' => (string) $creditor->id,
            'comprovante' => UploadedFile::fake()->image('correcao.jpg'),
            'justificativa' => 'a foto anterior era de outro mês',
        ];

        // O cliente de verdade (axios) manda `Accept: application/json`; sem ele a validação redireciona (302).
        return $this->withToken($this->tokenFor($caller))
            ->withHeader('Accept', 'application/json')
            ->post("/api/groups/{$group->id}/settlements/complement", array_merge($body, $overrides));
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, SettlementProofEntry> */
    private function entries(Group $group)
    {
        return SettlementProofEntry::where('group_id', $group->id)->orderBy('id')->get();
    }

    /** @return list<string> */
    private function files(Group $group): array
    {
        return Storage::disk('local')->allFiles("comprovantes/{$group->id}");
    }

    private function addEntries(Group $group, User $debtor, User $creditor, int $count, string $kind = SettlementProofEntry::KIND_REPLACED): void
    {
        for ($i = 0; $i < $count; $i++) {
            SettlementProofEntry::create([
                'group_id' => $group->id,
                'cycle_start' => '2026-08-01',
                'from_user_id' => $debtor->id,
                'to_user_id' => $creditor->id,
                'kind' => $kind,
                'sent_by' => $debtor->id,
                'status_at_exit' => 'sent',
                'amount' => 100,
                'proof_path' => "comprovantes/{$group->id}/velho{$i}.jpg",
            ]);
        }
    }

    // --- o caminho feliz ---------------------------------------------------

    public function test_the_responsible_adds_a_complement_and_it_goes_to_the_history(): void
    {
        $w = $this->world();
        Carbon::setTestNow('2026-08-21 15:30:00');

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])
            ->assertCreated()
            ->assertExactJson(['message' => 'Justificativa e comprovante enviados.']);

        $entry = $this->entries($w['group'])->sole();
        $this->assertSame(SettlementProofEntry::KIND_COMPLEMENT, $entry->kind);
        $this->assertSame($w['responsible']->id, (int) $entry->sent_by);
        $this->assertSame('a foto anterior era de outro mês', $entry->note);
        $this->assertSame(100.0, $entry->amount);
        $this->assertNull($entry->status_at_exit);
        $this->assertNull($entry->refusal_reason);
        $this->assertSame('2026-08-21 15:30:00', $entry->sent_at->toDateTimeString());
        $this->assertSame($w['debtor']->id, (int) $entry->from_user_id);
        $this->assertSame($w['creditor']->id, (int) $entry->to_user_id);
        $this->assertSame('2026-08-01', $entry->cycle_start->toDateString());
        $this->assertStringStartsWith("comprovantes/{$w['group']->id}/", $entry->proof_path);
        Storage::disk('local')->assertExists($entry->proof_path);
        Storage::disk('local')->assertExists($w['proof']->proof_path);
    }

    public function test_the_live_row_state_and_the_seal_rule_do_not_change_and_no_update_is_issued(): void
    {
        $w = $this->world();
        $before = $w['proof']->fresh()->getAttributes();

        $updates = [];
        $queries = $this->queriesAfterTheTransactionOpens(function () use ($w) {
            $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])->assertCreated();
        });
        foreach ($queries as $sql) {
            if (str_starts_with(strtolower($sql), 'update `ex_settlement_confirmations`')) {
                $updates[] = $sql;
            }
        }

        $this->assertSame([], $updates, 'nenhum UPDATE na linha do comprovante (R3 e R11)');
        $row = $w['proof']->fresh();
        $this->assertSame($before, $row->getAttributes(), '`updated_at` incluído');
        $this->assertSame(SettlementConfirmation::STATUS_SENT, $row->status());
        $this->assertSame(1, SettlementConfirmation::where('id', $row->id)->countsForSettlement()->count());
    }

    public function test_a_complement_works_in_a_sealed_cycle_and_leaves_the_snapshot_alone(): void
    {
        $w = $this->world(paid: true);
        $this->assertTrue(app(CycleSealingService::class)->sealIfSettled($w['group'], Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')));
        $snapshot = GroupCycleSnapshot::where('group_id', $w['group']->id)->where('cycle_start', '2026-08-01')->firstOrFail();
        $snapshotBefore = $snapshot->fresh()->getAttributes();
        $rowBefore = $w['proof']->fresh()->getAttributes();

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])->assertCreated();

        $this->assertSame($snapshotBefore, $snapshot->fresh()->getAttributes(), 'o selo e a foto do ciclo ficam como estão (D7-B)');
        $this->assertNotNull($snapshot->fresh()->settled_at);
        $this->assertSame($rowBefore, $w['proof']->fresh()->getAttributes());
        $this->assertSame(1, $this->entries($w['group'])->count());
    }

    public function test_a_complement_works_in_a_cycle_closed_by_date(): void
    {
        $w = $this->world();
        Carbon::setTestNow('2026-09-20');

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'], ['cycles_ago' => '1'])->assertCreated();

        $this->assertSame(1, $this->entries($w['group'])->count());
    }

    public function test_each_complement_adds_an_entry_and_keeps_every_file(): void
    {
        $w = $this->world();

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])->assertCreated();
        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'], ['justificativa' => 'segunda justificativa'])->assertCreated();

        $entries = $this->entries($w['group']);
        $this->assertSame(['a foto anterior era de outro mês', 'segunda justificativa'], $entries->pluck('note')->all());
        $this->assertCount(3, $this->files($w['group']), 'o atual e os dois complementos');
    }

    public function test_the_parties_see_the_complement_in_the_summary(): void
    {
        $w = $this->world();
        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])->assertCreated();

        $summary = $this->withToken($this->tokenFor($w['creditor']))->getJson("/api/groups/{$w['group']->id}/expenses/summary")->assertOk();
        $settlement = collect($summary->json('settlements'))->firstWhere('from_user_id', $w['debtor']->id);

        $this->assertSame('complement', $settlement['proofHistory'][0]['kind']);
        $this->assertSame('a foto anterior era de outro mês', $settlement['proofHistory'][0]['note']);
        $this->assertSame('sent', $settlement['confirmationStatus'], 'o estado do comprovante não mudou');
    }

    // --- o ciclo -----------------------------------------------------------

    public function test_a_cycle_that_is_not_closed_is_a_422_and_writes_nothing(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $proof->update(['confirmed_by' => null]);
        // linkedScenario fecha agosto; reabre para o caso do ciclo que ainda não fechou.
        GroupCycleSnapshot::where('group_id', $group->id)->where('cycle_start', '2026-08-01')->update(['reopened_at' => Carbon::now()->addMinute()]);

        $this->complement($responsible, $group, $debtor, $creditor)
            ->assertStatus(422)
            ->assertJson(['error' => 'O acerto só pode ser confirmado depois que a competência é fechada.']);

        $this->assertSame(0, $this->entries($group)->count());
        $this->assertSame([$proof->proof_path], $this->files($group));
    }

    // --- quem pode --------------------------------------------------------

    public function test_a_non_member_and_a_deleted_group_are_a_404(): void
    {
        $w = $this->world();
        $outsider = User::factory()->create();

        $this->complement($outsider, $w['group'], $w['debtor'], $w['creditor'])->assertNotFound();

        $w['group']->update(['deleted' => true]);
        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])->assertNotFound();

        $this->assertSame(0, $this->entries($w['group'])->count());
    }

    /** @return array<string, array{0: string}> */
    public static function callersWithoutThePower(): array
    {
        return [
            'the creditor' => ['creditor'],
            'the creditor who is also linked to the debtor' => ['creditor_linked'],
            'the debtor' => ['debtor'],
            'a member never linked to the debtor' => ['unlinked'],
            'someone whose link was removed' => ['former'],
            'the group creator' => ['creator'],
            'the debtor even with a link to themselves' => ['debtor_self_linked'],
        ];
    }

    /** @dataProvider callersWithoutThePower */
    public function test_only_who_pays_for_the_debtor_can_complement(string $role): void
    {
        $w = $this->world();
        [$unlinked, $former] = User::factory()->count(2)->create()->all();
        $w['group']->members()->attach([$unlinked->id, $former->id]);
        $link = GroupMemberResponsible::create(['group_id' => $w['group']->id, 'user_id' => $w['debtor']->id, 'responsible_user_id' => $former->id]);
        $link->delete();
        GroupMemberResponsible::create(['group_id' => $w['group']->id, 'user_id' => $w['debtor']->id, 'responsible_user_id' => $w['creditor']->id]);
        $caller = [
            'creditor' => $w['creditor'], 'creditor_linked' => $w['creditor'], 'debtor' => $w['debtor'],
            'unlinked' => $unlinked, 'former' => $former, 'creator' => $w['creator'], 'debtor_self_linked' => $w['debtor'],
        ][$role];
        if ($role === 'debtor_self_linked') {
            // Não acontece pela API, mas o 403 do devedor não pode depender disso (D10: o devedor já substitui o dele).
            GroupMemberResponsible::create(['group_id' => $w['group']->id, 'user_id' => $w['debtor']->id, 'responsible_user_id' => $w['debtor']->id]);
        }
        $before = $w['proof']->fresh()->getAttributes();

        $this->complement($caller, $w['group'], $w['debtor'], $w['creditor'])->assertForbidden();

        $this->assertSame(0, $this->entries($w['group'])->count());
        $this->assertSame([$w['proof']->proof_path], $this->files($w['group']), 'nenhum arquivo foi gravado');
        $this->assertSame($before, $w['proof']->fresh()->getAttributes());
    }

    public function test_the_403_comes_before_the_cycle_and_the_state_checks(): void
    {
        $w = $this->world();
        $unlinked = User::factory()->create();
        $w['group']->members()->attach($unlinked->id);
        $w['proof']->update(['accepted_at' => $this->proofSentAt()]);

        // Aceito e sem vínculo: quem não tem o poder recebe 403, não o 422 do estado.
        $this->complement($unlinked, $w['group'], $w['debtor'], $w['creditor'])->assertForbidden();
    }

    // --- o corpo -----------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidBodies(): array
    {
        return [
            'no photo' => [['comprovante' => null], 'comprovante'],
            'not an image' => [['comprovante' => 'ARQUIVO-DE-TEXTO'], 'comprovante'],
            'photo over 5 MB' => [['comprovante' => 'BIG'], 'comprovante'],
            'no justification' => [['justificativa' => null], 'justificativa'],
            'blank justification' => [['justificativa' => '   '], 'justificativa'],
            'justification over 500 characters' => [['justificativa' => 'LONG'], 'justificativa'],
            'no debtor' => [['from_user_id' => null], 'from_user_id'],
            'unknown creditor' => [['to_user_id' => '99999999'], 'to_user_id'],
            'negative cycles_ago' => [['cycles_ago' => '-1'], 'cycles_ago'],
        ];
    }

    /**
     * @dataProvider invalidBodies
     *
     * @param  array<string, mixed>  $overrides
     */
    public function test_an_invalid_body_is_a_422_and_writes_nothing(array $overrides, string $field): void
    {
        $w = $this->world();
        $overrides = array_map(fn ($value) => match ($value) {
            'ARQUIVO-DE-TEXTO' => UploadedFile::fake()->create('nota.txt', 5, 'text/plain'),
            'BIG' => UploadedFile::fake()->image('grande.jpg')->size(6000),
            'LONG' => str_repeat('a', 501),
            default => $value,
        }, $overrides);

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'], $overrides)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->assertSame(0, $this->entries($w['group'])->count());
        $this->assertSame([$w['proof']->proof_path], $this->files($w['group']));
    }

    // --- o estado do comprovante ------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function statesThatCannotBeComplemented(): array
    {
        return [
            'awaiting acceptance' => [['confirmed_by' => 'RESPONSIBLE'], 'Só dá para complementar o comprovante que o próprio devedor enviou.'],
            'refused' => [['confirmed_by' => 'RESPONSIBLE', 'refused_at' => '2026-08-21 09:00:00'], 'Só dá para complementar o comprovante que o próprio devedor enviou.'],
            'accepted' => [['confirmed_by' => 'RESPONSIBLE', 'accepted_at' => '2026-08-21 09:00:00'], 'Só dá para complementar o comprovante que o próprio devedor enviou.'],
        ];
    }

    /**
     * @dataProvider statesThatCannotBeComplemented
     *
     * @param  array<string, mixed>  $attributes
     */
    public function test_only_the_debtors_own_sent_proof_can_be_complemented(array $attributes, string $message): void
    {
        $w = $this->world();
        $attributes['confirmed_by'] = $w['responsible']->id;
        $w['proof']->update($attributes);
        $before = $w['proof']->fresh()->getAttributes();

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])
            ->assertStatus(422)
            ->assertJson(['error' => $message]);

        $this->assertSame(0, $this->entries($w['group'])->count());
        $this->assertSame([$w['proof']->proof_path], $this->files($w['group']), 'nenhum arquivo órfão');
        $this->assertSame($before, $w['proof']->fresh()->getAttributes());
    }

    public function test_a_pair_without_a_proof_is_a_404_and_leaves_no_file(): void
    {
        $w = $this->world();
        $w['proof']->delete();
        // Se o controller gravasse o arquivo antes de procurar o comprovante, daria 500 em vez de 404.
        $this->failProofWrites();
        Storage::disk('local')->put($w['proof']->proof_path, 'comprovante anterior');

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])->assertNotFound();

        $this->assertSame([$w['proof']->proof_path], $this->files($w['group']), 'só o arquivo que já estava no disco');
        $this->assertSame(0, $this->entries($w['group'])->count());
    }

    // --- o teto ------------------------------------------------------------

    // Editados na TASK-638 (D13, plan ajuste 10): antes enchiam o histórico com `replaced` e esperavam 422; o
    // complemento agora tem orçamento próprio e só os próprios complementos o enchem.
    public function test_with_ten_complements_it_is_refused_before_the_file_is_written(): void
    {
        $w = $this->world();
        $this->addEntries($w['group'], $w['debtor'], $w['creditor'], 10, SettlementProofEntry::KIND_COMPLEMENT);
        // Gravar o arquivo antes de checar o teto daria 500 em vez de 422.
        $this->failProofWrites();
        Storage::disk('local')->put($w['proof']->proof_path, 'comprovante anterior');
        $before = $w['proof']->fresh()->getAttributes();

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])
            ->assertStatus(422)
            ->assertJson(['error' => self::LIMIT_MESSAGE]);

        $this->assertSame(10, $this->entries($w['group'])->count());
        $this->assertSame([$w['proof']->proof_path], $this->files($w['group']), 'o disco não ganhou arquivo');
        $this->assertSame($before, $w['proof']->fresh()->getAttributes(), 'a linha atual segue igual');
        $this->assertSame(0, Notification::where('type', 'settlement_complement_added')->count(), 'nenhum aviso');
    }

    public function test_nine_complements_still_leave_room_for_one_more(): void
    {
        $w = $this->world();
        $this->addEntries($w['group'], $w['debtor'], $w['creditor'], 9, SettlementProofEntry::KIND_COMPLEMENT);

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])->assertCreated();

        $this->assertSame(10, $this->entries($w['group'])->where('kind', SettlementProofEntry::KIND_COMPLEMENT)->count());
    }

    public function test_ten_replaced_and_withdrawn_entries_do_not_block_a_complement(): void
    {
        $w = $this->world();
        // O orçamento de quem envia cheio (replaced + withdrawn) não gasta o dos complementos (D13).
        $this->addEntries($w['group'], $w['debtor'], $w['creditor'], 7, SettlementProofEntry::KIND_REPLACED);
        $this->addEntries($w['group'], $w['debtor'], $w['creditor'], 4, SettlementProofEntry::KIND_WITHDRAWN);

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])->assertCreated();

        $complement = $this->entries($w['group'])->where('kind', SettlementProofEntry::KIND_COMPLEMENT)->sole();
        $this->assertSame($w['responsible']->id, (int) $complement->sent_by);
        $this->assertSame(12, $this->entries($w['group'])->count());
    }

    public function test_the_service_checks_the_complement_budget_again_under_the_row_lock(): void
    {
        $w = $this->world();
        $this->addEntries($w['group'], $w['debtor'], $w['creditor'], 10, SettlementProofEntry::KIND_COMPLEMENT);
        $before = $w['proof']->fresh()->getAttributes();
        $cycle = ['start' => Carbon::parse('2026-08-01'), 'end' => Carbon::parse('2026-08-31'), 'status' => 'closed'];

        // A checagem do controller já passou (outro pedido encheu o orçamento depois dela): é a do service que vale.
        try {
            app(SettlementConfirmationService::class)->addComplement(
                $w['group'], $cycle, $w['debtor']->id, $w['creditor']->id, $w['responsible']->id,
                "comprovantes/{$w['group']->id}/novo.jpg", 'outra justificativa'
            );
            $this->fail('o service devia recusar o 11º complemento');
        } catch (SettlementDecisionRefused $e) {
            $this->assertSame(self::LIMIT_MESSAGE, $e->getMessage());
        }

        $this->assertSame(10, $this->entries($w['group'])->count(), 'nada foi gravado');
        $this->assertSame($before, $w['proof']->fresh()->getAttributes(), 'a linha atual segue igual');
    }

    public function test_the_blocked_reason_and_message_of_the_complement_budget_are_its_own(): void
    {
        $w = $this->world();
        $key = [
            'group_id' => $w['group']->id,
            'cycle_start' => '2026-08-01',
            'from_user_id' => $w['debtor']->id,
            'to_user_id' => $w['creditor']->id,
        ];

        $this->assertNull(SettlementConfirmationService::complementBlockedBy($key));

        $this->addEntries($w['group'], $w['debtor'], $w['creditor'], 10, SettlementProofEntry::KIND_COMPLEMENT);

        $this->assertSame(SettlementConfirmationService::BLOCKED_COMPLEMENT_FULL, SettlementConfirmationService::complementBlockedBy($key));
        $this->assertSame(self::LIMIT_MESSAGE, SettlementConfirmationService::blockedMessage(SettlementConfirmationService::BLOCKED_COMPLEMENT_FULL));
        $this->assertNotSame(
            SettlementConfirmationService::blockedMessage(SettlementConfirmationService::BLOCKED_HISTORY_FULL),
            SettlementConfirmationService::blockedMessage(SettlementConfirmationService::BLOCKED_COMPLEMENT_FULL)
        );
    }

    // --- atomicidade ------------------------------------------------------

    public function test_a_failure_saving_the_entry_removes_the_new_file(): void
    {
        $w = $this->world();
        SettlementProofEntry::creating(function () {
            throw new RuntimeException('falha simulada ao gravar a entrada');
        });

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])->assertStatus(500);

        SettlementProofEntry::flushEventListeners();
        $this->assertSame(0, $this->entries($w['group'])->count());
        $this->assertSame([$w['proof']->proof_path], $this->files($w['group']), 'o arquivo novo foi apagado e o atual segue');
    }

    // --- os avisos (TASK-627) ---------------------------------------------

    /** @return \Illuminate\Database\Eloquent\Builder<Notification> */
    private function notices()
    {
        return Notification::where('type', 'settlement_complement_added');
    }

    public function test_it_notifies_the_creditor_and_the_debtor_and_nobody_else(): void
    {
        $w = $this->world();

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])->assertCreated();

        foreach ([$w['creditor'], $w['debtor']] as $recipient) {
            $notice = $this->notices()->where('user_id', $recipient->id)->first();
            $this->assertNotNull($notice, 'avisa '.$recipient->name);
            $this->assertSame($w['group']->id, (int) $notice->group_id);
            $this->assertSame($w['responsible']->name, $notice->data['actorName'], 'quem adicionou');
            $this->assertSame($w['debtor']->name, $notice->data['debtorName'], 'de quem é o acerto');
            $this->assertSame('100.00', $notice->data['amount']);
            $this->assertSame('agosto/2026', $notice->data['cycleLabel']);
            $this->assertSame($w['group']->id, $notice->data['groupId']);
            $this->assertSame('Casa', $notice->data['groupName']);
        }
        $this->assertSame(2, $this->notices()->count(), 'só o credor e o devedor');
        $this->assertSame(
            0,
            $this->notices()->whereIn('user_id', [$w['responsible']->id, $w['creator']->id])->count(),
            'nem o autor, nem o criador do grupo'
        );
    }

    public function test_the_notice_carries_no_justification_and_no_other_data(): void
    {
        $w = $this->world();

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'], ['justificativa' => 'TEXTO-SO-DO-HISTORICO'])->assertCreated();

        foreach ($this->notices()->get() as $notice) {
            $this->assertEqualsCanonicalizing(
                ['actorName', 'debtorName', 'groupId', 'groupName', 'amount', 'cycleLabel'],
                array_keys($notice->data)
            );
            $this->assertStringNotContainsString('TEXTO-SO-DO-HISTORICO', json_encode($notice->data));
        }
        $this->assertSame(2, $this->notices()->count());
    }

    public function test_the_notice_names_the_cycle_of_the_proof_and_not_the_current_month(): void
    {
        $w = $this->world();
        Carbon::setTestNow('2026-09-20');

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'], ['cycles_ago' => '1'])->assertCreated();

        $this->assertSame(2, $this->notices()->count());
        foreach ($this->notices()->get() as $notice) {
            $this->assertSame('agosto/2026', $notice->data['cycleLabel'], 'o ciclo do comprovante, com o "hoje" em setembro');
        }
    }

    public function test_a_second_complement_notifies_again(): void
    {
        $w = $this->world();

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])->assertCreated();
        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'], ['justificativa' => 'segunda justificativa'])->assertCreated();

        $this->assertSame(4, $this->notices()->count(), 'cada complemento é um fato novo: dois avisos por vez');
    }

    public function test_a_notifier_failure_is_logged_and_the_complement_still_succeeds(): void
    {
        Log::spy();
        $w = $this->world();
        Notification::creating(function () {
            throw new RuntimeException('falha simulada ao gravar a notificação');
        });

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])
            ->assertCreated()
            ->assertExactJson(['message' => 'Justificativa e comprovante enviados.']);

        Notification::flushEventListeners();
        $this->assertSame(1, $this->entries($w['group'])->count(), 'a entrada ficou gravada');
        $this->assertSame(0, $this->notices()->count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => ($context['type'] ?? null) === 'settlement_complement_added')
            ->once();
    }

    public function test_no_notice_goes_out_when_the_complement_is_refused(): void
    {
        $w = $this->world();
        // Editado na TASK-638 (D13): o 422 vem do orçamento dos complementos, não mais do teto de quem envia.
        $this->addEntries($w['group'], $w['debtor'], $w['creditor'], 10, SettlementProofEntry::KIND_COMPLEMENT);

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])->assertStatus(422);
        $this->complement($w['debtor'], $w['group'], $w['debtor'], $w['creditor'])->assertForbidden();
        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'], ['justificativa' => ''])->assertStatus(422);

        $this->assertSame(0, $this->notices()->count());
    }

    public function test_no_notice_goes_out_when_the_entry_is_not_saved(): void
    {
        $w = $this->world();
        SettlementProofEntry::creating(function () {
            throw new RuntimeException('falha simulada ao gravar a entrada');
        });

        $this->complement($w['responsible'], $w['group'], $w['debtor'], $w['creditor'])->assertStatus(500);

        SettlementProofEntry::flushEventListeners();
        $this->assertSame(0, $this->notices()->count(), 'ninguém é avisado de um complemento que não existiu');
    }
}
