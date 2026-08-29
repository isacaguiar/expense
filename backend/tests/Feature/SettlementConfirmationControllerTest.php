<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Confirmação de pagamento de settlement pelo devedor — conceito distinto de
 * pay()/unpay() (ExpenseControllerPayTest.php), que confirmam uma despesa
 * específica do lado do credor. Ver
 * docs/feature/20260825-pagamentos-grid-pix/specify.md §2.7.
 */
class SettlementConfirmationControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    /**
     * Cria uma despesa que gera um settlement de $devedor pra $credor: o
     * credor paga, o devedor participa — settlement líquido = valor / 2.
     */
    private function createSettlementBetween(Group $group, User $creditor, User $debtor, float $totalValue): Expense
    {
        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => $totalValue,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);
        $expense->payers()->sync([$creditor->id, $debtor->id]);
        $expense->quotas()->create(['date_expected' => '2026-08-10', 'number' => 1, 'paid' => false, 'value_quota' => $totalValue]);

        return $expense;
    }

    public function test_debtor_can_confirm_a_real_settlement(): void
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        $this->createSettlementBetween($group, $creditor, $debtor, 200);

        $response = $this->withToken($this->tokenFor($debtor))
            ->post("/api/groups/{$group->id}/settlements/confirm", [
                'to_user_id' => $creditor->id,
                'comprovante' => UploadedFile::fake()->image('comprovante.jpg'),
            ]);

        $response->assertStatus(200)->assertJsonPath('amount', 100);
        $this->assertNotNull($response->json('proof_url'));

        $confirmation = SettlementConfirmation::where('group_id', $group->id)
            ->where('from_user_id', $debtor->id)
            ->where('to_user_id', $creditor->id)
            ->firstOrFail();

        $this->assertStringStartsWith("comprovantes/{$group->id}/", $confirmation->proof_path);
        Storage::disk('local')->assertExists($confirmation->proof_path);
    }

    public function test_confirm_requires_comprovante(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        $this->createSettlementBetween($group, $creditor, $debtor, 200);

        $response = $this->withToken($this->tokenFor($debtor))
            ->post("/api/groups/{$group->id}/settlements/confirm", [
                'to_user_id' => $creditor->id,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
    }

    public function test_confirm_rejects_when_there_is_no_real_settlement_for_that_pair(): void
    {
        Storage::fake('public');
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $stranger = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $debtor->id, $stranger->id]);

        $this->createSettlementBetween($group, $creditor, $debtor, 200);

        // $stranger não deve nada a ninguém neste grupo — não há settlement
        // from_user_id=stranger,to_user_id=creditor pra confirmar.
        $response = $this->withToken($this->tokenFor($stranger))
            ->post("/api/groups/{$group->id}/settlements/confirm", [
                'to_user_id' => $creditor->id,
                'comprovante' => UploadedFile::fake()->image('comprovante.jpg'),
            ]);

        $response->assertStatus(422);
        // Escopado por grupo/par em vez de assertDatabaseCount(...,0) global —
        // o banco local de dev é compartilhado com o servidor rodando fora do
        // teste, então pode ter outras linhas legítimas de outras execuções.
        $this->assertDatabaseMissing('ex_settlement_confirmations', [
            'group_id' => $group->id,
            'from_user_id' => $stranger->id,
            'to_user_id' => $creditor->id,
        ]);
    }

    public function test_resending_replaces_the_previous_proof(): void
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        $this->createSettlementBetween($group, $creditor, $debtor, 200);

        $token = $this->tokenFor($debtor);

        // Escopado por grupo/par em todo canto abaixo — o banco local de dev
        // é compartilhado com o servidor rodando fora do teste, então uma
        // query sem filtro (SettlementConfirmation::firstOrFail(), sem
        // orderBy) pode pegar uma linha de outra execução em vez da deste
        // teste.
        $confirmationForThisPair = fn () => SettlementConfirmation::where('group_id', $group->id)
            ->where('from_user_id', $debtor->id)
            ->where('to_user_id', $creditor->id);

        $this->withToken($token)
            ->post("/api/groups/{$group->id}/settlements/confirm", [
                'to_user_id' => $creditor->id,
                'comprovante' => UploadedFile::fake()->image('primeiro.jpg'),
            ])->assertStatus(200);

        $firstPath = $confirmationForThisPair()->firstOrFail()->proof_path;

        $this->withToken($token)
            ->post("/api/groups/{$group->id}/settlements/confirm", [
                'to_user_id' => $creditor->id,
                'comprovante' => UploadedFile::fake()->image('segundo.jpg'),
            ])->assertStatus(200);

        $this->assertSame(1, $confirmationForThisPair()->count());
        $secondPath = $confirmationForThisPair()->firstOrFail()->proof_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertExists($secondPath);
        Storage::disk('local')->assertMissing($firstPath);
    }

    public function test_cannot_confirm_in_a_manually_closed_cycle(): void
    {
        Storage::fake('public');
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        $this->createSettlementBetween($group, $creditor, $debtor, 200);

        GroupCycleSnapshot::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'totals' => ['total' => 0, 'paid' => 0, 'pending' => 0],
            'expenses' => [],
            'balances' => [],
            'closed_manually_at' => now(),
        ]);

        $response = $this->withToken($this->tokenFor($debtor))
            ->post("/api/groups/{$group->id}/settlements/confirm", [
                'to_user_id' => $creditor->id,
                'comprovante' => UploadedFile::fake()->image('comprovante.jpg'),
            ]);

        $response->assertStatus(422);
    }

    public function test_confirm_requires_group_membership(): void
    {
        Storage::fake('public');
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        $this->createSettlementBetween($group, $creditor, $debtor, 200);

        $response = $this->withToken($this->tokenFor($outsider))
            ->post("/api/groups/{$group->id}/settlements/confirm", [
                'to_user_id' => $creditor->id,
                'comprovante' => UploadedFile::fake()->image('comprovante.jpg'),
            ]);

        $response->assertStatus(404);
    }

    public function test_summary_exposes_confirmed_proof_on_the_matching_settlement(): void
    {
        Storage::fake('public');
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        $this->createSettlementBetween($group, $creditor, $debtor, 200);

        $this->withToken($this->tokenFor($debtor))
            ->post("/api/groups/{$group->id}/settlements/confirm", [
                'to_user_id' => $creditor->id,
                'comprovante' => UploadedFile::fake()->image('comprovante.jpg'),
            ])->assertStatus(200);

        $response = $this->withToken($this->tokenFor($creditor))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200);
        $settlements = $response->json('settlements');

        $this->assertCount(1, $settlements);
        $this->assertSame($debtor->id, $settlements[0]['from_user_id']);
        $this->assertNotNull($settlements[0]['confirmedProofUrl']);
        $this->assertNotNull($settlements[0]['confirmedAt']);
    }

    public function test_summary_shows_null_confirmation_when_nobody_confirmed_yet(): void
    {
        Carbon::setTestNow('2026-08-19');

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        $this->createSettlementBetween($group, $creditor, $debtor, 200);

        $response = $this->withToken($this->tokenFor($creditor))
            ->getJson("/api/groups/{$group->id}/expenses/summary");

        $response->assertStatus(200);
        $settlements = $response->json('settlements');

        $this->assertCount(1, $settlements);
        $this->assertNull($settlements[0]['confirmedProofUrl']);
        $this->assertNull($settlements[0]['confirmedAt']);
    }
}
