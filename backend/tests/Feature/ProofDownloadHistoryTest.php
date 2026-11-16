<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\SettlementProofEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Download do arquivo de uma entrada do histórico dos comprovantes (feature
 * 20261114-historico-de-comprovantes-e-justificativa, plan §6, TASK-623): o tipo `settlement_history` da
 * rota assinada `proofs.show` (ADR-005). A assinatura é a autorização (a aba do navegador não manda
 * `Bearer`) e a URL só é emitida a quem é parte do acerto (TASK-624); aqui o controller revalida o par
 * recurso × grupo e responde o **mesmo 404** para tipo inválido, entrada inexistente, de outro grupo ou
 * sem arquivo. Os tipos `quota` e `settlement` seguem em `ProofDownloadControllerTest`, que não é editado.
 */
class ProofDownloadHistoryTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0: Group, 1: SettlementProofEntry} */
    private function entryWithFile(string $content = 'ARQUIVO-DO-HISTORICO'): array
    {
        Storage::fake('local');

        [$debtor, $creditor] = User::factory()->count(2)->create()->all();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach([$debtor->id, $creditor->id]);

        $entry = SettlementProofEntry::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'kind' => SettlementProofEntry::KIND_REPLACED,
            'sent_by' => $debtor->id,
            'status_at_exit' => 'sent',
            'amount' => 50,
            'proof_path' => "comprovantes/{$group->id}/antigo.jpg",
        ]);
        Storage::disk('local')->put($entry->proof_path, $content);

        return [$group, $entry];
    }

    private function signed(int $groupId, string $type, int|string $id): string
    {
        return URL::temporarySignedRoute('proofs.show', now()->addMinutes(30), [
            'groupId' => $groupId,
            'type' => $type,
            'id' => $id,
        ]);
    }

    public function test_it_serves_the_file_of_a_history_entry_with_the_url_the_model_issues(): void
    {
        [, $entry] = $this->entryWithFile();

        $response = $this->get($entry->proofUrl());

        $response->assertOk();
        $this->assertSame('ARQUIVO-DO-HISTORICO', $response->streamedContent());
    }

    public function test_without_a_signature_it_is_a_403(): void
    {
        [$group, $entry] = $this->entryWithFile();

        $this->get("/api/groups/{$group->id}/proofs/settlement_history/{$entry->id}")->assertForbidden();
    }

    public function test_a_tampered_signature_is_a_403(): void
    {
        [, $entry] = $this->entryWithFile();

        $this->get($entry->proofUrl().'&extra=1')->assertForbidden();
    }

    public function test_an_expired_signature_is_a_403(): void
    {
        [$group, $entry] = $this->entryWithFile();
        $expired = URL::temporarySignedRoute('proofs.show', now()->subMinute(), [
            'groupId' => $group->id,
            'type' => 'settlement_history',
            'id' => $entry->id,
        ]);

        $this->get($expired)->assertForbidden();
    }

    public function test_an_entry_of_another_group_is_a_404(): void
    {
        [$group, $entry] = $this->entryWithFile();
        $other = Group::create(['name' => 'Outro grupo']);

        $this->get($this->signed($other->id, 'settlement_history', $entry->id))->assertNotFound();
        $this->get($this->signed($group->id + 999, 'settlement_history', $entry->id))->assertNotFound();
    }

    public function test_a_missing_entry_is_the_same_404(): void
    {
        [$group] = $this->entryWithFile();

        $this->get($this->signed($group->id, 'settlement_history', 99999999))->assertNotFound();
    }

    public function test_a_file_missing_from_the_disk_is_a_404(): void
    {
        [, $entry] = $this->entryWithFile();
        Storage::disk('local')->delete($entry->proof_path);

        $this->get($entry->proofUrl())->assertNotFound();
    }

    public function test_an_entry_without_a_path_is_a_404(): void
    {
        [$group, $entry] = $this->entryWithFile();
        // Só o banco: o model não deixa editar (R1) e o caso é o de um registro sem caminho.
        SettlementProofEntry::query()->whereKey($entry->id)->update(['proof_path' => '']);

        $this->get($this->signed($group->id, 'settlement_history', $entry->id))->assertNotFound();
    }

    public function test_the_other_types_do_not_resolve_a_history_entry(): void
    {
        [$group, $entry] = $this->entryWithFile();

        foreach (['settlement', 'quota'] as $type) {
            $this->get($this->signed($group->id, $type, $entry->id))->assertNotFound();
        }
    }
}
