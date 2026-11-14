<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\GroupMemberResponsible;
use App\Models\SettlementConfirmation;
use App\Models\SettlementProofEntry;
use App\Models\User;
use App\Support\ProofStorage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\Concerns\BuildsThirdPartyProofScenarios;
use Tests\TestCase;

/**
 * Retirar o comprovante em vez de excluí-lo (feature 20261114-historico-de-comprovantes-e-justificativa,
 * plan §4, TASK-621, D6-A): `DELETE .../settlements/confirmation` move a linha atual para o histórico
 * (`withdrawn`), **mantém o arquivo** (R2) e devolve o acerto a "a pagar". O aceito segue definitivo (R4), o
 * ciclo encerrado segue imutável (R5) e o teto de 10 **não** barra a retirada, que não grava arquivo novo.
 * A única exclusão física que sobra é a da linha atual, dentro do aval de hard delete da D7-A.
 */
class SettlementWithdrawKeepsHistoryTest extends TestCase
{
    use BuildsSettlementScenarios;
    use BuildsThirdPartyProofScenarios;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        SettlementConfirmation::flushEventListeners();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function withdraw(User $caller, Group $group, array $query): TestResponse
    {
        return $this->withToken($this->tokenFor($caller))
            ->deleteJson("/api/groups/{$group->id}/settlements/confirmation?".http_build_query($query));
    }

    /** O cenário com o arquivo do comprovante de verdade no disco falso. */
    private function scenarioWithFile(bool $paid = true): array
    {
        Storage::fake(ProofStorage::DISK);
        $scenario = $this->thirdPartyScenario(paid: $paid);
        Storage::disk(ProofStorage::DISK)->put($scenario[4]->proof_path, 'conteudo do comprovante');

        return $scenario;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, SettlementProofEntry> */
    private function entries(Group $group)
    {
        return SettlementProofEntry::where('group_id', $group->id)->orderBy('id')->get();
    }

    /** @return list<string> */
    private function files(Group $group): array
    {
        return Storage::disk(ProofStorage::DISK)->allFiles("comprovantes/{$group->id}");
    }

    // --- retirar ----------------------------------------------------------

    public function test_the_sender_withdraws_an_awaiting_proof_and_the_file_stays(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->scenarioWithFile();
        $path = $proof->proof_path;
        $proof->update(['note' => 'pix da viagem']);

        $this->withdraw($responsible, $group, $this->pairBody($debtor, $creditor))
            ->assertOk()
            ->assertExactJson(['message' => 'Comprovante retirado.']);

        $this->assertNull(SettlementConfirmation::find($proof->id), 'a linha atual saiu');
        $entry = $this->entries($group)->sole();
        $this->assertSame(SettlementProofEntry::KIND_WITHDRAWN, $entry->kind);
        $this->assertSame('awaiting_acceptance', $entry->status_at_exit);
        $this->assertSame($responsible->id, (int) $entry->sent_by);
        $this->assertSame($path, $entry->proof_path);
        $this->assertSame('pix da viagem', $entry->note);
        $this->assertSame(100.0, $entry->amount);
        $this->assertSame($this->proofSentAt(), $entry->sent_at->toDateTimeString());
        Storage::disk(ProofStorage::DISK)->assertExists($path);
    }

    public function test_the_debtor_withdraws_a_refused_proof_with_its_reason(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->scenarioWithFile();
        $proof->update(['refused_at' => '2026-08-21 09:00:00', 'refusal_reason' => 'foto cortada']);

        $this->withdraw($debtor, $group, $this->pairBody($debtor, $creditor))->assertOk();

        $entry = $this->entries($group)->sole();
        $this->assertSame('refused', $entry->status_at_exit);
        $this->assertSame('foto cortada', $entry->refusal_reason);
        $this->assertSame('2026-08-21 09:00:00', $entry->refused_at->toDateTimeString());
        $this->assertSame($responsible->id, (int) $entry->sent_by, 'quem enviou o comprovante, não quem o retirou');
        Storage::disk(ProofStorage::DISK)->assertExists($proof->proof_path);
    }

    public function test_the_pair_goes_back_to_to_pay_and_accepts_a_new_confirm_without_a_new_entry(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->scenarioWithFile();
        GroupMemberResponsible::create(['group_id' => $group->id, 'user_id' => $debtor->id, 'responsible_user_id' => $responsible->id]);

        $this->withdraw($responsible, $group, $this->pairBody($debtor, $creditor))->assertOk();

        $summary = $this->withToken($this->tokenFor($creditor))->getJson("/api/groups/{$group->id}/expenses/summary")->assertOk();
        $settlement = collect($summary->json('settlements'))->firstWhere('from_user_id', $debtor->id);
        $this->assertNull($settlement['confirmedAt'], 'voltou a "a pagar"');
        $this->assertNull($settlement['confirmationStatus']);

        $this->withToken($this->tokenFor($responsible))->post("/api/groups/{$group->id}/settlements/confirm", [
            'to_user_id' => $creditor->id,
            'from_user_id' => $debtor->id,
            'comprovante' => UploadedFile::fake()->image('novo.jpg'),
        ])->assertOk();

        $this->assertSame(1, $this->entries($group)->count(), 'o primeiro envio depois de retirar não cria entrada');
        $this->assertCount(2, $this->files($group), 'o retirado e o novo');
    }

    public function test_withdrawing_works_with_ten_entries_because_it_writes_no_new_file(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->scenarioWithFile();
        for ($i = 0; $i < 10; $i++) {
            SettlementProofEntry::create([
                'group_id' => $group->id,
                'cycle_start' => '2026-08-01',
                'from_user_id' => $debtor->id,
                'to_user_id' => $creditor->id,
                'kind' => SettlementProofEntry::KIND_REPLACED,
                'sent_by' => $debtor->id,
                'status_at_exit' => 'sent',
                'amount' => 100,
                'proof_path' => "comprovantes/{$group->id}/velho{$i}.jpg",
            ]);
        }

        $this->withdraw($responsible, $group, $this->pairBody($debtor, $creditor))->assertOk();

        $this->assertSame(11, $this->entries($group)->count());
    }

    // --- o que continua barrado -------------------------------------------

    public function test_an_accepted_proof_is_not_withdrawn_and_nothing_is_written(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->scenarioWithFile(paid: false);
        $proof->update(['accepted_at' => $this->proofSentAt()]);
        $before = $proof->fresh()->getAttributes();

        $this->withdraw($responsible, $group, $this->pairBody($debtor, $creditor))
            ->assertStatus(422)
            ->assertJson(['error' => 'Este comprovante já foi aceito e não pode ser retirado.']);

        $this->assertSame(0, $this->entries($group)->count());
        $this->assertSame($before, $proof->fresh()->getAttributes());
        Storage::disk(ProofStorage::DISK)->assertExists($proof->proof_path);
    }

    public function test_the_debtors_own_proof_is_not_withdrawn(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->scenarioWithFile(paid: false);
        $proof->update(['confirmed_by' => null]);

        $this->withdraw($debtor, $group, $this->pairBody($debtor, $creditor))
            ->assertStatus(422)
            ->assertJson(['error' => 'Só o comprovante enviado por quem paga por outra pessoa pode ser retirado.']);

        $this->assertSame(0, $this->entries($group)->count());
        $this->assertNotNull(SettlementConfirmation::find($proof->id));
    }

    public function test_a_sealed_cycle_still_refuses_and_writes_no_entry(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->scenarioWithFile();
        GroupCycleSnapshot::where('group_id', $group->id)->where('cycle_start', '2026-08-01')->update(['settled_at' => $this->proofSentAt()]);

        $this->withdraw($responsible, $group, $this->pairBody($debtor, $creditor))
            ->assertStatus(422)
            ->assertJson(['error' => 'Esta competência já foi encerrada.']);

        $this->assertSame(0, $this->entries($group)->count());
        $this->assertNotNull(SettlementConfirmation::find($proof->id));
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function forbiddenCallers(): array
    {
        return [
            'the creditor' => ['creditor', 403],
            'another responsible of the same debtor' => ['other_responsible', 403],
            'a third member' => ['third', 403],
            'the group creator' => ['creator', 403],
            'someone outside the group' => ['outsider', 404],
        ];
    }

    /** @dataProvider forbiddenCallers */
    public function test_the_other_roles_cannot_withdraw_and_nothing_is_written(string $role, int $expected): void
    {
        [$group, $creditor, $debtor, $responsible, $proof, $creator] = $this->scenarioWithFile();
        [$otherResponsible, $third, $outsider] = User::factory()->count(3)->create()->all();
        $group->members()->attach([$otherResponsible->id, $third->id]);
        GroupMemberResponsible::create(['group_id' => $group->id, 'user_id' => $debtor->id, 'responsible_user_id' => $otherResponsible->id]);
        $caller = ['creditor' => $creditor, 'other_responsible' => $otherResponsible, 'third' => $third, 'creator' => $creator, 'outsider' => $outsider][$role];

        $this->withdraw($caller, $group, $this->pairBody($debtor, $creditor))->assertStatus($expected);

        $this->assertSame(0, $this->entries($group)->count());
        $this->assertNotNull(SettlementConfirmation::find($proof->id));
        Storage::disk(ProofStorage::DISK)->assertExists($proof->proof_path);
    }

    // --- atomicidade ------------------------------------------------------

    public function test_a_failure_removing_the_row_rolls_back_the_entry_and_keeps_the_file(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->scenarioWithFile();
        SettlementConfirmation::deleting(function () {
            throw new RuntimeException('falha simulada ao remover a linha');
        });

        $this->withdraw($responsible, $group, $this->pairBody($debtor, $creditor))->assertStatus(500);

        SettlementConfirmation::flushEventListeners();
        $this->assertSame(0, $this->entries($group)->count(), 'a entrada desfez junto da remoção (F2)');
        $this->assertNotNull(SettlementConfirmation::find($proof->id));
        Storage::disk(ProofStorage::DISK)->assertExists($proof->proof_path);
    }
}
