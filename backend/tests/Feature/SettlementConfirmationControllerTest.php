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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        $confirmation = SettlementConfirmation::where('group_id', $group->id)
            ->where('from_user_id', $debtor->id)
            ->where('to_user_id', $creditor->id)
            ->firstOrFail();

        $this->assertStringStartsWith("comprovantes/{$group->id}/", $confirmation->proof_path);
        Storage::disk('local')->assertExists($confirmation->proof_path);

        // A URL exposta é uma signed route p/ proofs.show e baixa o arquivo.
        $proofUrl = $response->json('proof_url');
        $this->assertStringContainsString("/groups/{$group->id}/proofs/settlement/{$confirmation->id}", $proofUrl);
        $this->get($proofUrl)->assertOk();
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

    private function enableWhatsApp(): void
    {
        config()->set('services.whatsapp', [
            'enabled' => true,
            'token' => 'tok',
            'phone_number_id' => '999',
            'api_version' => 'v21.0',
            'locale' => 'pt_BR',
            'templates' => ['expense_proof' => 'tpl_e', 'settlement_proof' => 'tpl_s'],
        ]);
    }

    private function optInWhatsApp(User $user, string $whatsapp): void
    {
        $user->whatsapp = $whatsapp;
        $user->notify_whatsapp = true;
        $user->save();
    }

    public function test_confirm_notifica_o_credor_opt_in(): void
    {
        Storage::fake('local');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200)]);
        Carbon::setTestNow('2026-08-19');
        $this->enableWhatsApp();

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $this->optInWhatsApp($creditor, '(11) 90000-0001');
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        $this->createSettlementBetween($group, $creditor, $debtor, 200);

        $this->withToken($this->tokenFor($debtor))
            ->post("/api/groups/{$group->id}/settlements/confirm", [
                'to_user_id' => $creditor->id,
                'comprovante' => UploadedFile::fake()->image('comprovante.jpg'),
            ])->assertStatus(200);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($debtor, $group) {
            return $request->url() === 'https://graph.facebook.com/v21.0/999/messages'
                && $request['to'] === '5511900000001'
                && $request['template']['name'] === 'tpl_s'
                && $request['template']['components'][0]['parameters'] === [
                    ['type' => 'text', 'text' => $debtor->name],
                    ['type' => 'text', 'text' => 'R$ 100,00'],
                    ['type' => 'text', 'text' => 'ago/2026'],
                ]
                && $request['template']['components'][1]['parameters'][0]['text'] === "groups/{$group->id}/payments";
        });
    }

    public function test_confirm_nao_notifica_credor_sem_opt_in(): void
    {
        Storage::fake('local');
        Http::fake();
        Carbon::setTestNow('2026-08-19');
        $this->enableWhatsApp();

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

        Http::assertNothingSent();
    }

    public function test_reenvio_do_comprovante_notifica_de_novo(): void
    {
        Storage::fake('local');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200)]);
        Carbon::setTestNow('2026-08-19');
        $this->enableWhatsApp();

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $this->optInWhatsApp($creditor, '(11) 90000-0001');
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        $this->createSettlementBetween($group, $creditor, $debtor, 200);

        $payload = fn () => [
            'to_user_id' => $creditor->id,
            'comprovante' => UploadedFile::fake()->image('comprovante.jpg'),
        ];

        $this->withToken($this->tokenFor($debtor))
            ->post("/api/groups/{$group->id}/settlements/confirm", $payload())->assertStatus(200);
        $this->withToken($this->tokenFor($debtor))
            ->post("/api/groups/{$group->id}/settlements/confirm", $payload())->assertStatus(200);

        // O reenvio dispara nova notificação: pelo menos uma por confirmação,
        // sempre o template de acerto para o credor. (Contagem exata não é
        // firme aqui: nos testes HTTP os callbacks `afterResponse` de uma
        // request anterior ainda rodam na seguinte — em produção cada request
        // tem ciclo próprio.)
        $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertGreaterThanOrEqual(2, $sent->count());
        $sent->each(function ($request) use ($group) {
            $this->assertSame('tpl_s', $request['template']['name']);
            $this->assertSame('5511900000001', $request['to']);
            $this->assertSame("groups/{$group->id}/payments", $request['template']['components'][1]['parameters'][0]['text']);
        });
    }

    public function test_confirm_com_meta_fora_do_ar_nao_quebra(): void
    {
        Storage::fake('local');
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);
        Log::spy();
        Carbon::setTestNow('2026-08-19');
        $this->enableWhatsApp();

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $this->optInWhatsApp($creditor, '(11) 90000-0001');
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        $this->createSettlementBetween($group, $creditor, $debtor, 200);

        $this->withToken($this->tokenFor($debtor))
            ->post("/api/groups/{$group->id}/settlements/confirm", [
                'to_user_id' => $creditor->id,
                'comprovante' => UploadedFile::fake()->image('comprovante.jpg'),
            ])->assertStatus(200)->assertJsonPath('amount', 100);

        $this->assertDatabaseHas('ex_settlement_confirmations', [
            'group_id' => $group->id,
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
        ]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'falha'))
            ->atLeast()->once();
    }
}
