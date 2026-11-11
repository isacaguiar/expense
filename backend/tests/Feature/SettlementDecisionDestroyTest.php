<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\GroupMemberResponsible;
use App\Models\Notification;
use App\Models\Quota;
use App\Models\SettlementConfirmation;
use App\Models\User;
use App\Support\ProofStorage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\Concerns\BuildsThirdPartyProofScenarios;
use Tests\TestCase;

/**
 * `DELETE /groups/{id}/settlements/confirmation` (feature 20261110-quem-paga-se-declara-e-credor-recusa,
 * plan §4, TASK-600, D7-A): quem enviou o comprovante, ou o devedor, o **exclui**, enquanto o credor não o
 * aceitou e o ciclo não está encerrado. É a **única exclusão física** da feature, com o aval de hard delete
 * dele (2026-11-10), limitada ao comprovante que passa pelo aceite (o de responsável). A linha sai primeiro e
 * o arquivo depois, só se nenhuma outra linha ou parcela o usa.
 */
class SettlementDecisionDestroyTest extends TestCase
{
    use BuildsSettlementScenarios;
    use BuildsThirdPartyProofScenarios;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Os três campos vão na query string, como o plan manda. */
    private function destroyProof(User $caller, Group|int|string $group, array $query): TestResponse
    {
        $groupId = $group instanceof Group ? $group->id : $group;

        return $this->withToken($this->tokenFor($caller))
            ->deleteJson("/api/groups/{$groupId}/settlements/confirmation?".http_build_query($query));
    }

    /** O arquivo do comprovante de verdade no disco falso. */
    private function putFile(SettlementConfirmation $proof): void
    {
        Storage::disk(ProofStorage::DISK)->put($proof->proof_path, 'conteudo do comprovante');
    }

    // --- o caminho feliz ---------------------------------------------------

    public function test_the_sender_deletes_a_refused_proof_and_the_file(): void
    {
        Storage::fake(ProofStorage::DISK);
        [$group, $creditor, $debtor, $responsible, $proof] = $this->thirdPartyScenario(paid: true);
        $proof->update(['refused_at' => $this->proofSentAt()]);
        $this->putFile($proof);

        $this->destroyProof($responsible, $group, $this->pairBody($debtor, $creditor))
            ->assertOk()
            ->assertJson(['message' => 'Comprovante excluído.']);

        $this->assertNull(SettlementConfirmation::find($proof->id), 'a linha foi excluída');
        Storage::disk(ProofStorage::DISK)->assertMissing($proof->proof_path);
    }

    public function test_the_debtor_deletes_an_awaiting_proof(): void
    {
        Storage::fake(ProofStorage::DISK);
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario(paid: true);
        $this->putFile($proof);

        $this->destroyProof($debtor, $group, $this->pairBody($debtor, $creditor))->assertOk();

        $this->assertNull(SettlementConfirmation::find($proof->id));
        Storage::disk(ProofStorage::DISK)->assertMissing($proof->proof_path);
    }

    public function test_a_shared_file_is_kept(): void
    {
        Storage::fake(ProofStorage::DISK);
        [$group, $creditor, $debtor, $responsible, $proof] = $this->thirdPartyScenario(paid: true);
        $this->putFile($proof);
        // Outra linha de acerto aponta para o mesmo arquivo: ele não pode sair do disco.
        $other = User::factory()->create();
        $group->members()->attach($other->id);
        SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'from_user_id' => $other->id,
            'to_user_id' => $creditor->id,
            'amount' => 10,
            'proof_path' => $proof->proof_path,
            'confirmed_at' => $this->proofSentAt(),
        ]);

        $this->destroyProof($responsible, $group, $this->pairBody($debtor, $creditor))->assertOk();

        $this->assertNull(SettlementConfirmation::find($proof->id), 'a linha desta confirmação saiu');
        Storage::disk(ProofStorage::DISK)->assertExists($proof->proof_path);
    }

    public function test_a_file_still_used_by_an_installment_is_kept(): void
    {
        Storage::fake(ProofStorage::DISK);
        [$group, $creditor, $debtor, $responsible, $proof] = $this->thirdPartyScenario(paid: true);
        $this->putFile($proof);
        // A parcela da despesa do grupo aponta para o mesmo arquivo (compra paga no cadastro, por exemplo).
        Quota::whereHas('expense', fn ($query) => $query->where('group_id', $group->id))->update(['payment_proof_path' => $proof->proof_path]);

        $this->destroyProof($responsible, $group, $this->pairBody($debtor, $creditor))->assertOk();

        Storage::disk(ProofStorage::DISK)->assertExists($proof->proof_path);
    }

    // --- quem pode excluir -------------------------------------------------

    public function test_the_creditor_cannot_delete(): void
    {
        [$group, $creditor, $debtor, , $proof, $creator] = $this->thirdPartyScenario(paid: true);
        $outsider = User::factory()->create();
        $group->members()->attach($outsider->id);

        foreach ([$creditor, $creator, $outsider] as $caller) {
            $this->destroyProof($caller, $group, $this->pairBody($debtor, $creditor))->assertForbidden();
        }

        $this->assertNotNull(SettlementConfirmation::find($proof->id), 'nada foi excluído');
    }

    public function test_a_former_responsible_can_still_delete_the_own_proof(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->thirdPartyScenario(paid: true);
        $link = GroupMemberResponsible::create([
            'group_id' => $group->id,
            'user_id' => $debtor->id,
            'responsible_user_id' => $responsible->id,
        ]);
        $link->delete();

        $this->destroyProof($responsible, $group, $this->pairBody($debtor, $creditor))->assertOk();

        $this->assertNull(SettlementConfirmation::find($proof->id));
    }

    public function test_the_creditor_who_sent_the_proof_himself_can_delete_it(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario(paid: true);
        $proof->update(['confirmed_by' => $creditor->id]);

        $this->destroyProof($creditor, $group, $this->pairBody($debtor, $creditor))->assertOk();

        $this->assertNull(SettlementConfirmation::find($proof->id), 'quem enviou exclui, mesmo sendo o credor');
    }

    public function test_it_returns_404_for_a_non_member_before_the_403(): void
    {
        [$group, $creditor, $debtor] = $this->thirdPartyScenario(paid: true);

        $this->destroyProof(User::factory()->create(), $group, $this->pairBody($debtor, $creditor))->assertNotFound();
    }

    public function test_it_returns_404_for_a_missing_deleted_or_malformed_group_id(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->thirdPartyScenario(paid: true);

        $this->destroyProof($responsible, 99999999, $this->pairBody($debtor, $creditor))->assertNotFound();
        $this->destroyProof($responsible, $group->id.'abc', $this->pairBody($debtor, $creditor))->assertNotFound();

        $group->update(['deleted' => true]);
        $this->destroyProof($responsible, $group, $this->pairBody($debtor, $creditor))->assertNotFound();
    }

    public function test_it_returns_404_when_there_is_no_confirmation_for_the_pair(): void
    {
        [$group, $creditor, , $responsible, , $creator] = $this->thirdPartyScenario(paid: true);

        // O devedor "criador" não tem comprovante nenhum: 404 tanto para ele quanto para quem não é o devedor.
        $this->destroyProof($creator, $group, $this->pairBody($creator, $creditor))->assertNotFound();
        $this->destroyProof($responsible, $group, $this->pairBody($creator, $creditor))->assertNotFound();
    }

    // --- o estado do comprovante -------------------------------------------

    public function test_an_accepted_or_own_proof_cannot_be_deleted(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->thirdPartyScenario(paid: false);

        $proof->update(['accepted_at' => $this->proofSentAt()]);
        $this->destroyProof($responsible, $group, $this->pairBody($debtor, $creditor))
            ->assertStatus(422)
            ->assertJson(['error' => 'Este comprovante já foi aceito e não pode ser excluído.']);
        $this->assertNotNull(SettlementConfirmation::find($proof->id));

        $proof->update(['accepted_at' => null, 'confirmed_by' => null]);
        $this->destroyProof($debtor, $group, $this->pairBody($debtor, $creditor))
            ->assertStatus(422)
            ->assertJson(['error' => 'Só o comprovante enviado por quem paga por outra pessoa pode ser excluído.']);
        $this->assertNotNull(SettlementConfirmation::find($proof->id), 'o comprovante do próprio devedor não ganha exclusão nova');
    }

    // --- o ciclo -----------------------------------------------------------

    public function test_it_returns_422_for_a_sealed_cycle(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->thirdPartyScenario(paid: true);
        GroupCycleSnapshot::where('group_id', $group->id)->where('cycle_start', '2026-08-01')->update(['settled_at' => $this->proofSentAt()]);

        $this->destroyProof($responsible, $group, $this->pairBody($debtor, $creditor))
            ->assertStatus(422)
            ->assertJson(['error' => 'Esta competência já foi encerrada.']);

        $this->assertNotNull(SettlementConfirmation::find($proof->id), 'ciclo encerrado é imutável');
    }

    public function test_it_returns_422_when_the_cycle_is_not_closed_yet(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->thirdPartyScenario(paid: true, closeCycle: false);

        $this->destroyProof($responsible, $group, $this->pairBody($debtor, $creditor))
            ->assertStatus(422)
            ->assertJson(['error' => 'O acerto só pode ser confirmado depois que a competência é fechada.']);

        $this->assertNotNull(SettlementConfirmation::find($proof->id));
    }

    public function test_cycles_ago_picks_a_previous_closed_cycle(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->thirdPartyScenario(paid: true, closeCycle: false);
        Carbon::setTestNow('2026-09-20');

        $this->destroyProof($responsible, $group, $this->pairBody($debtor, $creditor, ['cycles_ago' => 1]))->assertOk();

        $this->assertNull(SettlementConfirmation::find($proof->id));
    }

    // --- validação ---------------------------------------------------------

    public function test_the_query_is_validated(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->thirdPartyScenario(paid: true);

        $this->destroyProof($responsible, $group, ['to_user_id' => $creditor->id])->assertStatus(422)->assertJsonValidationErrors('from_user_id');
        $this->destroyProof($responsible, $group, ['from_user_id' => $debtor->id, 'to_user_id' => 99999999])->assertStatus(422)->assertJsonValidationErrors('to_user_id');
        $this->destroyProof($responsible, $group, $this->pairBody($debtor, $creditor, ['cycles_ago' => -1]))->assertStatus(422)->assertJsonValidationErrors('cycles_ago');
    }

    // --- depois de excluir -------------------------------------------------

    public function test_after_deleting_the_pair_is_to_pay_again_and_accepts_a_new_confirm(): void
    {
        Storage::fake(ProofStorage::DISK);
        [$group, $creditor, $debtor, $responsible, $proof] = $this->thirdPartyScenario(paid: true);
        GroupMemberResponsible::create(['group_id' => $group->id, 'user_id' => $debtor->id, 'responsible_user_id' => $responsible->id]);
        $this->putFile($proof);

        $this->destroyProof($responsible, $group, $this->pairBody($debtor, $creditor))->assertOk();

        $summary = $this->withToken($this->tokenFor($creditor))->getJson("/api/groups/{$group->id}/expenses/summary")->assertOk();
        $settlement = collect($summary->json('settlements'))->firstWhere('from_user_id', $debtor->id);
        $this->assertNull($settlement['confirmedAt'], 'voltou a "a pagar"');
        $this->assertNull($settlement['confirmationStatus']);

        $this->withToken($this->tokenFor($responsible))->post("/api/groups/{$group->id}/settlements/confirm", [
            'to_user_id' => $creditor->id,
            'from_user_id' => $debtor->id,
            'comprovante' => UploadedFile::fake()->image('novo.jpg'),
        ])->assertOk();

        $new = SettlementConfirmation::where('group_id', $group->id)->where('from_user_id', $debtor->id)->first();
        $this->assertSame('awaiting_acceptance', $new->status());
    }

    public function test_deleting_notifies_nobody(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->thirdPartyScenario(paid: true);

        $this->destroyProof($responsible, $group, $this->pairBody($debtor, $creditor))->assertOk();

        $this->assertSame(0, Notification::whereIn('type', ['settlement_refused', 'settlement_accepted', 'settlement_confirmed'])->count());
    }

    // --- consistência ------------------------------------------------------

    public function test_it_locks_the_confirmation_row_while_deleting(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->thirdPartyScenario(paid: true);

        DB::enableQueryLog();
        $this->destroyProof($responsible, $group, $this->pairBody($debtor, $creditor))->assertOk();
        $locked = collect(DB::getQueryLog())->pluck('query')->filter(
            fn (string $sql) => str_contains($sql, 'ex_settlement_confirmations') && str_contains(strtolower($sql), 'for update')
        );

        $this->assertGreaterThanOrEqual(1, $locked->count(), 'a linha do comprovante é lida com `for update`');
    }
}
