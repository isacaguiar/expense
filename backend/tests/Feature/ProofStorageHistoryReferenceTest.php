<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\SettlementConfirmation;
use App\Models\SettlementProofEntry;
use App\Models\User;
use App\Support\ProofStorage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `ProofStorage::deleteIfUnreferenced` conhece o histórico dos comprovantes do acerto (feature
 * 20261114-historico-de-comprovantes-e-justificativa, plan §7, TASK-622, CA-8): um arquivo que **só** uma
 * entrada de `ex_settlement_proof_entries` referencia não é órfão e não pode sair do disco (R2). O caso das
 * parcelas e das linhas de acerto continua em `ProofStorageReferencesTest`, que não é editado.
 */
class ProofStorageHistoryReferenceTest extends TestCase
{
    use DatabaseTransactions;

    private Group $group;

    private User $debtor;

    private User $creditor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        [$this->debtor, $this->creditor] = User::factory()->count(2)->create()->all();
        $this->group = Group::create(['name' => 'Grupo']);
        $this->group->members()->attach([$this->debtor->id, $this->creditor->id]);
    }

    private function storedFile(string $name = 'historico.jpg'): string
    {
        $path = "comprovantes/{$this->group->id}/{$name}";
        Storage::disk('local')->put($path, 'conteudo');

        return $path;
    }

    private function entryWith(string $path): SettlementProofEntry
    {
        return SettlementProofEntry::create([
            'group_id' => $this->group->id,
            'cycle_start' => '2026-08-01',
            'from_user_id' => $this->debtor->id,
            'to_user_id' => $this->creditor->id,
            'kind' => SettlementProofEntry::KIND_REPLACED,
            'sent_by' => $this->debtor->id,
            'status_at_exit' => 'sent',
            'amount' => 50,
            'proof_path' => $path,
        ]);
    }

    public function test_it_keeps_a_file_only_a_history_entry_references(): void
    {
        $path = $this->storedFile();
        $this->entryWith($path);

        ProofStorage::deleteIfUnreferenced($path);

        Storage::disk('local')->assertExists($path);
    }

    public function test_it_still_deletes_a_file_nothing_references(): void
    {
        $path = $this->storedFile('orfao.jpg');
        $this->entryWith($this->storedFile('outro.jpg'));

        ProofStorage::deleteIfUnreferenced($path);

        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertExists("comprovantes/{$this->group->id}/outro.jpg");
    }

    public function test_it_keeps_a_file_that_the_history_and_the_live_row_share(): void
    {
        $path = $this->storedFile();
        $this->entryWith($path);
        SettlementConfirmation::create([
            'group_id' => $this->group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'from_user_id' => $this->debtor->id,
            'to_user_id' => $this->creditor->id,
            'amount' => 50,
            'proof_path' => $path,
            'confirmed_at' => '2026-08-20 10:00:00',
        ]);

        ProofStorage::deleteIfUnreferenced($path);

        Storage::disk('local')->assertExists($path);
    }

    public function test_it_keeps_a_file_that_the_history_and_an_installment_share(): void
    {
        $path = $this->storedFile();
        $this->entryWith($path);
        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Compra',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 50,
            'group_id' => $this->group->id,
            'user_creator_id' => $this->creditor->id,
            'user_payer_id' => $this->creditor->id,
            'deleted' => false,
        ]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => true, 'payment_proof_path' => $path, 'value_quota' => 50]);

        ProofStorage::deleteIfUnreferenced($path);

        Storage::disk('local')->assertExists($path);
    }

    public function test_an_entry_of_another_path_does_not_protect_this_file(): void
    {
        $protected = $this->storedFile('a.jpg');
        $unprotected = $this->storedFile('b.jpg');
        $this->entryWith($protected);

        ProofStorage::deleteIfUnreferenced($unprotected);

        Storage::disk('local')->assertExists($protected);
        Storage::disk('local')->assertMissing($unprotected);
    }
}
