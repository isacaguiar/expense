<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\SettlementConfirmation;
use App\Models\SettlementProofEntry;
use App\Models\User;
use App\Services\CycleSealingService;
use App\Services\CycleSummaryService;
use App\Services\SettlementProofHistoryService;
use App\Support\ProofStorage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\Concerns\BuildsThirdPartyProofScenarios;
use Tests\TestCase;

/**
 * O resumo do ciclo expõe o histórico e o comprovante recusado só às partes (feature
 * 20261114-historico-de-comprovantes-e-justificativa, plan §6, TASK-624, D8-A, R7 e R12):
 * `CycleSummaryService::withConfirmations` ganha o `viewerId` opcional; sem ele o retorno é o de antes, e
 * com ele cada acerto traz `refusedProof` (`{proofUrl, note, reason}` ou nulo) e `proofHistory` (da entrada
 * mais nova para a mais antiga). O credor e o devedor leem tudo; quem enviou lê só o que enviou; os demais
 * (outro responsável, terceiro, criador do grupo) recebem `null` e `[]`, e **nenhuma URL do histórico** no JSON.
 */
class SettlementProofVisibilityTest extends TestCase
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
     * Cenário de agosto/2026 com o comprovante do responsável **recusado** (motivo e arquivo no disco), e três
     * entradas no histórico: duas enviadas pelo responsável e uma por outro responsável.
     *
     * @return array{group: Group, creditor: User, debtor: User, responsible: User, other: User, third: User, creator: User, proof: SettlementConfirmation, entries: list<SettlementProofEntry>}
     */
    private function world(): array
    {
        Storage::fake(ProofStorage::DISK);
        [$group, $creditor, $debtor, $responsible, $proof, $creator] = $this->thirdPartyScenario();
        [$other, $third] = User::factory()->count(2)->create()->all();
        $group->members()->attach([$other->id, $third->id]);

        $proof->update([
            'note' => 'foto do pix',
            'refused_at' => '2026-08-21 09:00:00',
            'refusal_reason' => 'foto cortada',
        ]);
        Storage::disk(ProofStorage::DISK)->put($proof->proof_path, 'conteudo atual');

        $entries = [
            $this->entry($group, $debtor, $creditor, $responsible, 'replaced', 'a.jpg'),
            $this->entry($group, $debtor, $creditor, $other, 'withdrawn', 'b.jpg'),
            $this->entry($group, $debtor, $creditor, $responsible, 'complement', 'c.jpg'),
        ];

        return compact('group', 'creditor', 'debtor', 'responsible', 'other', 'third', 'creator', 'proof', 'entries');
    }

    private function entry(Group $group, User $debtor, User $creditor, User $sender, string $kind, string $file, bool $withFile = true): SettlementProofEntry
    {
        $entry = SettlementProofEntry::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'kind' => $kind,
            'sent_by' => $sender->id,
            'status_at_exit' => $kind === 'complement' ? null : 'awaiting_acceptance',
            'amount' => 100,
            'proof_path' => "comprovantes/{$group->id}/{$file}",
            'note' => "nota {$file}",
            'sent_at' => '2026-08-20 10:00:00',
        ]);
        if ($withFile) {
            Storage::disk(ProofStorage::DISK)->put($entry->proof_path, "conteudo {$file}");
        }

        return $entry;
    }

    /** @return array<string, mixed> o acerto do devedor ao credor, como o `summary` o devolve ao `$viewer`. */
    private function settlementSeenBy(User $viewer, Group $group, User $debtor): array
    {
        $summary = $this->withToken($this->tokenFor($viewer))->getJson("/api/groups/{$group->id}/expenses/summary")->assertOk();

        return collect($summary->json('settlements'))->firstWhere('from_user_id', $debtor->id);
    }

    // --- sem `viewerId`: o retorno de antes --------------------------------

    public function test_without_a_viewer_the_service_returns_what_it_returned_before(): void
    {
        $w = $this->world();
        $settlements = [['from_user_id' => $w['debtor']->id, 'to_user_id' => $w['creditor']->id, 'amount' => 100.0]];

        $decorated = app(CycleSummaryService::class)->withConfirmations($w['group']->id, Carbon::parse('2026-08-01'), $settlements)[0];

        $this->assertArrayNotHasKey('refusedProof', $decorated);
        $this->assertArrayNotHasKey('proofHistory', $decorated);
        $this->assertSame('refused', $decorated['confirmationStatus']);
        $this->assertNull($decorated['confirmedProofUrl']);
        $this->assertNull($decorated['confirmedNote']);
        $this->assertNull($decorated['confirmedAt']);
    }

    // --- o comprovante recusado -------------------------------------------

    public function test_the_parties_see_the_refused_proof_with_note_reason_and_a_signed_url(): void
    {
        $w = $this->world();

        foreach (['creditor', 'debtor', 'responsible'] as $role) {
            $settlement = $this->settlementSeenBy($w[$role], $w['group'], $w['debtor']);

            $this->assertSame('refused', $settlement['confirmationStatus'], $role);
            $this->assertSame(['proofUrl', 'note', 'reason'], array_keys($settlement['refusedProof']), $role);
            $this->assertSame('foto do pix', $settlement['refusedProof']['note'], $role);
            $this->assertSame('foto cortada', $settlement['refusedProof']['reason'], $role);
            $this->assertStringContainsString("/proofs/settlement/{$w['proof']->id}", $settlement['refusedProof']['proofUrl'], $role);
            // O que já era nulo no recusado continua nulo (volta a "a pagar").
            $this->assertNull($settlement['confirmedProofUrl'], $role);
            $this->assertNull($settlement['confirmedNote'], $role);
            $this->assertNull($settlement['confirmedAt'], $role);
        }
    }

    public function test_the_other_members_do_not_see_the_refused_proof(): void
    {
        $w = $this->world();

        foreach (['other', 'third', 'creator'] as $role) {
            $settlement = $this->settlementSeenBy($w[$role], $w['group'], $w['debtor']);

            $this->assertSame('refused', $settlement['confirmationStatus'], $role);
            $this->assertNull($settlement['refusedProof'], $role);
        }
    }

    public function test_the_refused_proof_url_is_null_when_the_file_is_missing(): void
    {
        $w = $this->world();
        Storage::disk(ProofStorage::DISK)->delete($w['proof']->proof_path);

        $settlement = $this->settlementSeenBy($w['creditor'], $w['group'], $w['debtor']);

        $this->assertNull($settlement['refusedProof']['proofUrl']);
        $this->assertSame('foto cortada', $settlement['refusedProof']['reason']);
    }

    public function test_a_proof_that_is_not_refused_has_no_refused_proof(): void
    {
        $w = $this->world();
        $w['proof']->update(['refused_at' => null, 'refusal_reason' => null]);

        $settlement = $this->settlementSeenBy($w['creditor'], $w['group'], $w['debtor']);

        $this->assertSame('awaiting_acceptance', $settlement['confirmationStatus']);
        $this->assertNull($settlement['refusedProof']);
    }

    // --- o histórico -------------------------------------------------------

    public function test_the_creditor_and_the_debtor_read_the_whole_history_newest_first(): void
    {
        $w = $this->world();
        [$first, $second, $third] = $w['entries'];

        foreach (['creditor', 'debtor'] as $role) {
            $history = $this->settlementSeenBy($w[$role], $w['group'], $w['debtor'])['proofHistory'];

            $this->assertSame([$third->id, $second->id, $first->id], array_column($history, 'id'), $role);
        }
    }

    public function test_an_entry_carries_the_planned_fields_and_a_signed_url(): void
    {
        $w = $this->world();
        [, $withdrawn, $complement] = $w['entries'];
        $withdrawn->refresh();

        $history = collect($this->settlementSeenBy($w['creditor'], $w['group'], $w['debtor'])['proofHistory'])->keyBy('id');
        $entry = $history[$withdrawn->id];

        $this->assertSame(
            ['id', 'kind', 'status', 'sentBy', 'amount', 'note', 'refusalReason', 'sentAt', 'refusedAt', 'archivedAt', 'proofUrl'],
            array_keys($entry)
        );
        $this->assertSame('withdrawn', $entry['kind']);
        $this->assertSame('awaiting_acceptance', $entry['status']);
        $this->assertSame(['id' => $w['other']->id, 'name' => $w['other']->name], $entry['sentBy']);
        $this->assertEquals(100.0, $entry['amount'], 'o JSON entrega 100, não 100.0');
        $this->assertSame('nota b.jpg', $entry['note']);
        $this->assertNull($entry['refusalReason']);
        $this->assertNull($entry['refusedAt']);
        $this->assertSame(Carbon::parse('2026-08-20 10:00:00')->toIso8601String(), $entry['sentAt']);
        $this->assertSame($withdrawn->created_at->toIso8601String(), $entry['archivedAt']);
        $this->assertStringContainsString("/proofs/settlement_history/{$withdrawn->id}", $entry['proofUrl']);
        $this->assertNull($history[$complement->id]['status'], 'o complemento não tem estado de saída');
    }

    public function test_the_sender_reads_only_the_entries_they_sent(): void
    {
        $w = $this->world();
        [$first, $second, $third] = $w['entries'];

        $byResponsible = $this->settlementSeenBy($w['responsible'], $w['group'], $w['debtor'])['proofHistory'];
        $byOther = $this->settlementSeenBy($w['other'], $w['group'], $w['debtor'])['proofHistory'];

        $this->assertSame([$third->id, $first->id], array_column($byResponsible, 'id'));
        $this->assertSame([$second->id], array_column($byOther, 'id'));
    }

    public function test_the_other_members_get_an_empty_history_and_no_history_url_anywhere(): void
    {
        $w = $this->world();

        foreach (['third', 'creator'] as $role) {
            $summary = $this->withToken($this->tokenFor($w[$role]))->getJson("/api/groups/{$w['group']->id}/expenses/summary")->assertOk();
            $settlement = collect($summary->json('settlements'))->firstWhere('from_user_id', $w['debtor']->id);

            $this->assertSame([], $settlement['proofHistory'], $role);
            $this->assertStringNotContainsString('settlement_history', $summary->getContent(), $role);
        }
    }

    public function test_an_entry_whose_file_is_missing_comes_without_a_link(): void
    {
        $w = $this->world();
        $missing = $this->entry($w['group'], $w['debtor'], $w['creditor'], $w['responsible'], 'replaced', 'sumiu.jpg', withFile: false);

        $history = collect($this->settlementSeenBy($w['creditor'], $w['group'], $w['debtor'])['proofHistory'])->keyBy('id');

        $this->assertNull($history[$missing->id]['proofUrl']);
        $this->assertNotNull($history[$w['entries'][0]->id]['proofUrl']);
    }

    public function test_a_refused_entry_carries_the_reason_and_the_refusal_date(): void
    {
        $w = $this->world();
        $refused = $this->entry($w['group'], $w['debtor'], $w['creditor'], $w['responsible'], 'replaced', 'r.jpg');
        DB::table('ex_settlement_proof_entries')->where('id', $refused->id)->update([
            'status_at_exit' => 'refused',
            'refusal_reason' => 'borrada',
            'refused_at' => '2026-08-21 09:00:00',
        ]);

        $entry = collect($this->settlementSeenBy($w['debtor'], $w['group'], $w['debtor'])['proofHistory'])->firstWhere('id', $refused->id);

        $this->assertSame('refused', $entry['status']);
        $this->assertSame('borrada', $entry['refusalReason']);
        $this->assertSame(Carbon::parse('2026-08-21 09:00:00')->toIso8601String(), $entry['refusedAt']);
    }

    public function test_a_pair_without_history_has_an_empty_list(): void
    {
        $w = $this->world();
        SettlementProofEntry::where('group_id', $w['group']->id)->get()->each(
            fn ($entry) => DB::table('ex_settlement_proof_entries')->where('id', $entry->id)->delete()
        );

        $this->assertSame([], $this->settlementSeenBy($w['creditor'], $w['group'], $w['debtor'])['proofHistory']);
    }

    // --- defesa em profundidade (revisão de segurança da TASK-628) ----------

    /**
     * O filtro R7 não pode morar só em PHP: se ele regredir, a consulta não pode ter trazido do banco as
     * entradas de quem o leitor não é parte. A condição (`from_user_id`, `to_user_id` ou `sent_by` = leitor)
     * vai também para o SQL, e o filtro em PHP fica como segunda camada.
     */
    public function test_the_history_query_already_filters_by_the_viewer_in_sql(): void
    {
        $w = $this->world();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $entries = app(SettlementProofHistoryService::class)->forViewer($w['group']->id, '2026-08-01', $w['third']->id);
        $selects = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'ex_settlement_proof_entries'));

        $this->assertSame([], $entries, 'quem não é parte não lê nada');
        $this->assertCount(1, $selects, 'uma consulta só para o ciclo');
        $sql = $selects->first()['query'];
        foreach (['from_user_id', 'to_user_id', 'sent_by'] as $column) {
            $this->assertMatchesRegularExpression("/`{$column}` = \\?/", $sql, "o SQL já filtra por {$column}");
        }
        $this->assertContains($w['third']->id, $selects->first()['bindings'], 'com o id do leitor como binding');
    }

    // --- os três pontos que respondem ao usuário ----------------------------

    public function test_close_and_reopen_also_carry_the_history_for_the_caller(): void
    {
        $w = $this->world();
        $creditorToken = $this->tokenFor($w['creditor']);

        // O cenário já fechou agosto manualmente: reabrir devolve o resumo ao vivo.
        $reopened = $this->withToken($creditorToken)->postJson("/api/groups/{$w['group']->id}/expenses/reopen")->assertOk();
        $this->assertCount(3, collect($reopened->json('settlements'))->firstWhere('from_user_id', $w['debtor']->id)['proofHistory']);

        $closed = $this->withToken($creditorToken)->postJson("/api/groups/{$w['group']->id}/expenses/close")->assertOk();
        $this->assertCount(3, collect($closed->json('settlements'))->firstWhere('from_user_id', $w['debtor']->id)['proofHistory']);

        $closedForThird = $this->withToken($this->tokenFor($w['third']))->postJson("/api/groups/{$w['group']->id}/expenses/close")->assertOk();
        $this->assertSame([], collect($closedForThird->json('settlements'))->firstWhere('from_user_id', $w['debtor']->id)['proofHistory']);
    }

    public function test_a_sealed_cycle_also_shows_the_history(): void
    {
        Storage::fake(ProofStorage::DISK);
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario(paid: true);
        $proof->update(['confirmed_by' => null]);
        $entry = $this->entry($group, $debtor, $creditor, $debtor, 'replaced', 'selado.jpg');
        $this->assertTrue(app(CycleSealingService::class)->sealIfSettled($group, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')));

        $settlement = $this->settlementSeenBy($creditor, $group, $debtor);

        $this->assertSame([$entry->id], array_column($settlement['proofHistory'], 'id'));
    }

    // --- sem N+1 ------------------------------------------------------------

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_pairs(): void
    {
        $w = $this->world();
        $users = User::factory()->count(5)->create();
        $w['group']->members()->attach($users->pluck('id')->all());

        $one = [['from_user_id' => $w['debtor']->id, 'to_user_id' => $w['creditor']->id, 'amount' => 100.0]];
        $many = $one;
        foreach ($users as $user) {
            $many[] = ['from_user_id' => $user->id, 'to_user_id' => $w['creditor']->id, 'amount' => 10.0];
            $this->entry($w['group'], $user, $w['creditor'], $user, 'replaced', "u{$user->id}.jpg");
        }
        $service = app(CycleSummaryService::class);
        $count = function (array $settlements) use ($service, $w): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $service->withConfirmations($w['group']->id, Carbon::parse('2026-08-01'), $settlements, $w['creditor']->id);

            return count(DB::getQueryLog());
        };

        $this->assertSame($count($one), $count($many));
    }
}
