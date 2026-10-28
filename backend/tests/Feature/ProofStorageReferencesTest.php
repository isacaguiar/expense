<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\Quota;
use App\Models\SettlementConfirmation;
use App\Models\User;
use App\Support\ProofStorage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `ProofStorage::deleteIfUnreferenced` (feature
 * 20261027-pagamento-no-cadastro-despesa, plan.md §3, specify.md §2.11 e R18):
 * com a compra paga no cadastro, um mesmo arquivo de comprovante é referenciado
 * por várias quotas. Apagar o arquivo pelo caminho, como `delete` faz, quebraria
 * as outras; este método só remove quando ninguém mais o usa.
 */
class ProofStorageReferencesTest extends TestCase
{
    use DatabaseTransactions;

    private Group $group;

    private Expense $expense;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $creditor = User::factory()->create();
        $this->group = Group::create(['name' => 'Grupo']);
        $this->group->members()->attach($creditor->id);
        $this->expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Compra',
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 2,
            'total_value' => 100,
            'group_id' => $this->group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);
    }

    /** Grava um arquivo no disco privado, no layout por grupo, e devolve o caminho. */
    private function storedFile(string $name = 'comprovante.jpg'): string
    {
        $path = "comprovantes/{$this->group->id}/{$name}";
        Storage::disk('local')->put($path, 'conteudo');

        return $path;
    }

    private function quotaWith(?string $path, int $number = 1): Quota
    {
        return $this->expense->quotas()->create([
            'date_expected' => '2026-08-10',
            'number' => $number,
            'paid' => true,
            'payment_proof_path' => $path,
            'value_quota' => 50,
        ]);
    }

    public function test_it_deletes_an_unreferenced_file(): void
    {
        $path = $this->storedFile();

        ProofStorage::deleteIfUnreferenced($path);

        Storage::disk('local')->assertMissing($path);
    }

    public function test_it_keeps_a_file_a_quota_references(): void
    {
        $path = $this->storedFile();
        $this->quotaWith($path);

        ProofStorage::deleteIfUnreferenced($path);

        Storage::disk('local')->assertExists($path);
    }

    public function test_it_keeps_a_file_when_only_one_of_several_quotas_still_references_it(): void
    {
        $path = $this->storedFile();
        $first = $this->quotaWith($path, 1);
        $this->quotaWith($path, 2);

        $first->update(['payment_proof_path' => null]);
        ProofStorage::deleteIfUnreferenced($path);

        Storage::disk('local')->assertExists($path);
    }

    public function test_it_keeps_a_file_a_settlement_confirmation_references(): void
    {
        $path = $this->storedFile('acerto.jpg');
        $debtor = User::factory()->create();

        SettlementConfirmation::create([
            'group_id' => $this->group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'from_user_id' => $debtor->id,
            'to_user_id' => $this->expense->user_payer_id,
            'amount' => 50,
            'proof_path' => $path,
            'confirmed_at' => now(),
        ]);

        ProofStorage::deleteIfUnreferenced($path);

        Storage::disk('local')->assertExists($path);
    }

    public function test_it_ignores_null_and_empty_paths(): void
    {
        $path = $this->storedFile();

        ProofStorage::deleteIfUnreferenced(null);
        ProofStorage::deleteIfUnreferenced('');

        Storage::disk('local')->assertExists($path);
        $this->assertTrue(true, 'não lançou exceção para caminho vazio');
    }

    public function test_it_is_silent_when_the_file_is_already_gone(): void
    {
        ProofStorage::deleteIfUnreferenced("comprovantes/{$this->group->id}/nao-existe.jpg");

        $this->assertTrue(true, 'não lançou exceção para arquivo inexistente');
    }

    public function test_delete_still_removes_the_file_regardless_of_references(): void
    {
        $path = $this->storedFile();
        $this->quotaWith($path);

        ProofStorage::delete($path);

        Storage::disk('local')->assertMissing($path);
    }
}
