<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\Notification;
use App\Models\Quota;
use App\Models\User;
use App\Services\CycleSummaryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pagamento registrado no cadastro da despesa (feature
 * 20261027-pagamento-no-cadastro-despesa, specify.md R1 a R7 e CA-01 a CA-27):
 * `POST /api/expenses` aceita `comprovante` e `descricao_pagamento` e, havendo
 * qualquer um, cria a despesa já paga pelo credor, numa só requisição.
 *
 * "Nada criado" quer dizer: nenhuma linha em ex_expenses, ex_expenses_payers e
 * ex_quotas, e nenhum arquivo em comprovantes/.
 */
class ExpenseControllerStorePaymentTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array{group: Group, creditor: User, debtor: User}
     */
    private function scenario(string $now = '2026-08-19 12:00:00'): array
    {
        Carbon::setTestNow($now);
        Storage::fake('local');

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'República']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        return ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor];
    }

    /** Corpo de um cadastro À Vista de 150,00; sem `quotas` (o servidor as gera). */
    private function body(Group $group, User $creator, User $payer, User $other, array $overrides = []): array
    {
        return array_merge([
            'date_payment' => '2026-08-10',
            'description' => 'Conta de luz',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 150,
            'group_id' => $group->id,
            'user_creator_id' => $creator->id,
            'user_payer_id' => $payer->id,
            'payers' => [$payer->id, $other->id],
        ], $overrides);
    }

    private function token(User $user): string
    {
        return auth('api')->login($user);
    }

    /** @return array{expenses: int, payers: int, quotas: int, files: int} */
    private function counts(): array
    {
        return [
            'expenses' => Expense::count(),
            'payers' => \DB::table('ex_expenses_payers')->count(),
            'quotas' => Quota::count(),
            'files' => count(Storage::disk('local')->allFiles()),
        ];
    }

    // --- CA-01: sem pagamento, o cadastro é o de hoje -------------------------------------

    public function test_without_payment_data_the_expense_is_created_pending_as_today(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        $response = $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor))
            ->assertStatus(201)
            ->assertJsonPath('payment_registered', false);

        $quota = Quota::where('expense_id', $response->json('expense_id'))->firstOrFail();

        $this->assertFalse($quota->paid);
        $this->assertNull($quota->paid_at);
        $this->assertNull($quota->paid_by);
        $this->assertNull($quota->payment_proof_path);
        $this->assertNull($quota->payment_note);
        $this->assertFalse($quota->born_paid);
        $this->assertSame(0, Notification::where('type', 'expense_paid')->count());
    }

    // --- CA-02: o que não conta como descrição --------------------------------------------

    /**
     * Medição do CA-02: o `TrimStrings` do Laravel 10 aparou todos estes (a regra
     * do framework inclui NBSP via `\s` com `/u`, U+200B e U+FEFF), então o
     * cadastro segue como hoje, sem erro e sem pagamento.
     *
     * @return array<string, array{0: string}>
     */
    public static function blankNotes(): array
    {
        return [
            'espaços' => ['     '],
            'tabs' => ["\t\t"],
            'quebras de linha' => ["\n\r\n"],
            'NBSP' => ["\u{00A0}\u{00A0}"],
            'U+200B (largura zero)' => ["\u{200B}"],
            'U+FEFF (BOM)' => ["\u{FEFF}"],
            'mistura' => [" \t\n\u{00A0}\u{200B} "],
            'string vazia' => [''],
        ];
    }

    /**
     * @dataProvider blankNotes
     */
    public function test_a_blank_payment_note_is_not_a_payment_and_not_an_error(string $note): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        $response = $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, ['descricao_pagamento' => $note]))
            ->assertStatus(201)
            ->assertJsonPath('payment_registered', false);

        $quota = Quota::where('expense_id', $response->json('expense_id'))->firstOrFail();
        $this->assertFalse($quota->paid);
        $this->assertNull($quota->payment_note);
    }

    // --- CA-03 a CA-06: À Vista nasce quitada ---------------------------------------------

    public function test_in_cash_with_a_payment_note_is_born_paid_by_the_creditor(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        $response = $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, ['descricao_pagamento' => '  Cartão final 1234 ']))
            ->assertStatus(201)
            ->assertJsonPath('payment_registered', true);

        $expense = Expense::findOrFail($response->json('expense_id'));
        $quota = $expense->quotas()->firstOrFail();

        $this->assertTrue($quota->paid);
        $this->assertSame($creditor->id, $quota->paid_by);
        $this->assertEquals(Carbon::parse('2026-08-19 12:00:00'), $quota->paid_at);
        $this->assertSame('Cartão final 1234', $quota->payment_note);
        $this->assertNull($quota->payment_proof_path);
        $this->assertFalse($quota->born_paid, 'pagamento comum nunca usa born_paid');

        // A descrição da despesa é outro campo e não mudou.
        $this->assertSame('Conta de luz', $expense->description);
        $this->assertNotSame($expense->description, $quota->payment_note);
    }

    public function test_in_cash_with_a_photo_stores_the_file_and_exposes_the_url(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        $response = $this->withToken($this->token($creditor))
            ->post('/api/expenses', $this->body($group, $creditor, $creditor, $debtor) + ['comprovante' => UploadedFile::fake()->image('comprovante.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('payment_registered', true);

        $quota = Quota::where('expense_id', $response->json('expense_id'))->firstOrFail();

        $this->assertTrue($quota->paid);
        $this->assertStringStartsWith("comprovantes/{$group->id}/", $quota->payment_proof_path);
        Storage::disk('local')->assertExists($quota->payment_proof_path);
        $this->assertNull($quota->payment_note);

        $summary = $this->withToken($this->token($creditor))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago=0")
            ->assertOk()->json();

        $this->assertTrue($summary['expenses'][0]['paid']);
        $this->assertNotNull($summary['expenses'][0]['paymentProofUrl']);
    }

    public function test_in_cash_with_a_photo_and_a_note_stores_both(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        $response = $this->withToken($this->token($creditor))
            ->post('/api/expenses', $this->body($group, $creditor, $creditor, $debtor) + [
                'comprovante' => UploadedFile::fake()->image('comprovante.jpg'),
                'descricao_pagamento' => 'Pix agendado',
            ], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $quota = Quota::where('expense_id', $response->json('expense_id'))->firstOrFail();

        $this->assertNotNull($quota->payment_proof_path);
        $this->assertSame('Pix agendado', $quota->payment_note);
    }

    public function test_the_note_zero_is_a_valid_payment_note(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        $response = $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, ['descricao_pagamento' => '0']))
            ->assertStatus(201)
            ->assertJsonPath('payment_registered', true);

        $quota = Quota::where('expense_id', $response->json('expense_id'))->firstOrFail();
        $this->assertTrue($quota->paid);
        $this->assertSame('0', $quota->payment_note);
    }

    public function test_a_payment_note_over_500_characters_is_refused(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $before = $this->counts();

        $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, ['descricao_pagamento' => str_repeat('a', 501)]))
            ->assertStatus(422)->assertJsonValidationErrors('descricao_pagamento');

        $this->assertSame($before, $this->counts());
    }

    public function test_a_client_request_id_that_is_not_a_uuid_is_refused(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, ['client_request_id' => 'nao-e-uuid']))
            ->assertStatus(422)->assertJsonValidationErrors('client_request_id');
    }

    // --- CA-09: Fixa paga só a ocorrência inicial -----------------------------------------

    public function test_fixed_pays_only_the_initial_occurrence(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        $response = $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, [
                'expense_type' => 'FIXED',
                'total_value' => 90,
                'descricao_pagamento' => 'Débito automático',
            ]))
            ->assertStatus(201)
            ->assertJsonPath('payment_registered', true);

        $expense = Expense::findOrFail($response->json('expense_id'));

        $this->assertSame(1, $expense->quotas()->count());
        $this->assertTrue($expense->quotas()->first()->paid);

        // A ocorrência do mês seguinte é projetada e nasce pendente.
        $september = app(CycleSummaryService::class)
            ->entries($group->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))
            ->firstWhere(fn (array $entry) => $entry['expense']->id === $expense->id);

        $this->assertNotNull($september);
        $this->assertFalse($september['paid']);
    }

    // --- CA-12: a ocorrência inicial não pode ser futura ---------------------------------

    public function test_an_initial_occurrence_in_a_future_competence_with_payment_is_refused_and_nothing_is_created(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $before = $this->counts();

        $body = $this->body($group, $creditor, $creditor, $debtor, ['date_payment' => '2026-09-20']);

        $this->withToken($this->token($creditor))
            ->post('/api/expenses', $body + ['comprovante' => UploadedFile::fake()->image('c.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $body + ['descricao_pagamento' => 'Pago antecipado'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Não é possível registrar o pagamento de uma competência que ainda não começou. Retire o comprovante e a descrição do pagamento ou use uma data de hoje ou anterior.');

        $this->assertSame($before, $this->counts());
    }

    public function test_the_same_future_expense_without_payment_is_still_accepted(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, ['date_payment' => '2026-09-20']))
            ->assertStatus(201)
            ->assertJsonPath('payment_registered', false);
    }

    // --- CA-14: competência fechada segue com a recusa de hoje ----------------------------

    public function test_in_cash_and_fixed_in_a_closed_competence_keep_todays_refusal_with_payment(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario('2026-10-19 12:00:00');
        $before = $this->counts();

        foreach (['IN_CASH', 'FIXED'] as $type) {
            $this->withToken($this->token($creditor))
                ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, [
                    'expense_type' => $type,
                    'date_payment' => '2026-08-10',
                    'descricao_pagamento' => 'Pago',
                ]))
                ->assertStatus(422)
                ->assertJsonPath('error', 'Não é possível alterar dados de uma competência já fechada.');
        }

        $this->assertSame($before, $this->counts());
    }

    // --- CA-15: a carência ainda aceita ---------------------------------------------------

    public function test_in_cash_in_the_grace_window_is_created_and_paid(): void
    {
        // 2026-09-03: agosto terminou em 31/08 e só fecha em 05/09.
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario('2026-09-03 12:00:00');

        $response = $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, [
                'date_payment' => '2026-08-20',
                'descricao_pagamento' => 'Pago na carência',
            ]))
            ->assertStatus(201)
            ->assertJsonPath('payment_registered', true);

        $this->assertTrue(Quota::where('expense_id', $response->json('expense_id'))->firstOrFail()->paid);
    }

    // --- CA-16: a foto inválida nunca é descartada ----------------------------------------

    public function test_an_invalid_photo_is_refused_even_with_a_valid_note_and_nothing_is_created(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $before = $this->counts();

        $notAnImage = UploadedFile::fake()->create('documento.pdf', 10, 'application/pdf');
        $tooBig = UploadedFile::fake()->image('grande.jpg')->size(6000);

        foreach ([$notAnImage, $tooBig] as $file) {
            $this->withToken($this->token($creditor))
                ->post('/api/expenses', $this->body($group, $creditor, $creditor, $debtor) + [
                    'comprovante' => $file,
                    'descricao_pagamento' => 'Descrição válida',
                ], ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonValidationErrors('comprovante');
        }

        $this->assertSame($before, $this->counts());
    }

    // --- CA-27: JSON e multipart dão o mesmo estado ---------------------------------------

    public function test_json_and_multipart_produce_the_same_state(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $token = $this->token($creditor);

        $json = $this->body($group, $creditor, $creditor, $debtor, ['description' => 'Via JSON', 'descricao_pagamento' => 'Pago']);
        $form = array_map(fn ($value) => is_array($value) ? array_map('strval', $value) : (string) $value, $this->body($group, $creditor, $creditor, $debtor, ['description' => 'Via formulário', 'descricao_pagamento' => 'Pago']));

        $viaJson = $this->withToken($token)->postJson('/api/expenses', $json)->assertStatus(201)->json('expense_id');
        $viaForm = $this->withToken($token)->post('/api/expenses', $form, ['Accept' => 'application/json'])->assertStatus(201)->json('expense_id');

        $state = function (int $id): array {
            $expense = Expense::with('quotas', 'payers')->findOrFail($id);

            return [
                'type' => $expense->expense_type,
                'installments' => (int) $expense->installments,
                'total' => (float) $expense->total_value,
                'payers' => $expense->payers->pluck('id')->sort()->values()->all(),
                'quotas' => $expense->quotas->map(fn (Quota $quota) => [
                    'number' => $quota->number,
                    'date' => $quota->date_expected->toDateString(),
                    'value' => (float) $quota->value_quota,
                    'paid' => $quota->paid,
                    'paid_by' => $quota->paid_by,
                    'note' => $quota->payment_note,
                ])->all(),
            ];
        };

        $this->assertEquals($state($viaJson), $state($viaForm));
    }
}
