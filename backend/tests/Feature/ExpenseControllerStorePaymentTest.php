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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FailsToWriteProofs;
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
    use FailsToWriteProofs;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        // Os ouvintes de eventos de modelo sobrevivem ao teste (a mesma aplicação é reaproveitada).
        Event::forget('eloquent.creating: '.Quota::class);
        Event::forget('eloquent.creating: '.Notification::class);

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

    // --- CA-07: Parcelada paga todas as parcelas (compra no cartão) -----------------------

    public function test_in_installments_pays_every_non_born_paid_quota_with_the_same_file_and_note(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        $response = $this->withToken($this->token($creditor))
            ->post('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 3,
                'total_value' => 300,
            ]) + [
                'comprovante' => UploadedFile::fake()->image('cartao.jpg'),
                'descricao_pagamento' => 'Cartão final 1234',
            ], ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('payment_registered', true);

        $expense = Expense::findOrFail($response->json('expense_id'));
        $quotas = $expense->quotas()->orderBy('number')->get();

        $this->assertCount(3, $quotas);
        $this->assertCount(1, $quotas->pluck('payment_proof_path')->unique(), 'um arquivo só para a compra inteira');
        $this->assertCount(1, $quotas->pluck('paid_at')->unique(), 'o mesmo paid_at em todas as quotas');
        $this->assertSame(1, $this->counts()['files']);

        foreach ($quotas as $quota) {
            $this->assertTrue($quota->paid);
            $this->assertSame($creditor->id, $quota->paid_by);
            $this->assertSame('Cartão final 1234', $quota->payment_note);
            $this->assertNotNull($quota->payment_proof_path);
            $this->assertFalse($quota->born_paid);
        }

        // O valor de cada parcela não muda (rateio de sempre: 100,00 por mês).
        $this->assertSame([100.0, 100.0, 100.0], $quotas->map(fn (Quota $quota) => (float) $quota->value_quota)->all());
    }

    public function test_an_installment_expense_paid_at_registration_is_locked_by_the_existing_rules(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $token = $this->token($creditor);

        $id = $this->withToken($token)
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 2,
                'total_value' => 200,
                'descricao_pagamento' => 'Cartão',
            ]))->assertStatus(201)->json('expense_id');

        $this->withToken($token)->putJson("/api/expenses/{$id}", ['description' => 'Outro nome'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Não é possível editar uma despesa parcelada depois que a primeira parcela foi paga.');

        $this->withToken($token)->deleteJson("/api/expenses/{$id}")
            ->assertStatus(422)
            ->assertJsonPath('error', 'Não é possível excluir uma despesa já paga. Desfaça o pagamento primeiro.');
    }

    // --- CA-08: a ordem e o número das quotas do cliente não importam ---------------------

    public function test_installment_quotas_from_the_client_out_of_order_with_repeated_number_are_all_paid(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        $quotas = [];
        for ($i = 0; $i < 12; $i++) {
            // `number` repetido e fora de ordem: nenhuma delas é "a primeira" para o servidor.
            $quotas[] = [
                'date_expected' => Carbon::parse('2026-08-10')->addMonthsNoOverflow($i)->toDateString(),
                'number' => 1,
                'value_quota' => 100,
            ];
        }
        $quotas = array_reverse($quotas);

        $response = $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 12,
                'total_value' => 1200,
                'quotas' => $quotas,
                'descricao_pagamento' => 'Cartão em 12x',
            ]))
            ->assertStatus(201)
            ->assertJsonPath('payment_registered', true);

        $stored = Expense::findOrFail($response->json('expense_id'))->quotas;

        $this->assertCount(12, $stored);
        $this->assertSame(12, $stored->where('paid', true)->count());
        $this->assertSame(0, $stored->where('born_paid', true)->count());
    }

    // --- 120 parcelas com foto: uma transação só ------------------------------------------

    public function test_a_120_installment_purchase_with_a_photo_is_created_and_paid_in_one_go(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        $response = $this->withToken($this->token($creditor))
            ->post('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 120,
                'total_value' => 12000,
            ]) + ['comprovante' => UploadedFile::fake()->image('cartao.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $stored = Expense::findOrFail($response->json('expense_id'))->quotas;

        $this->assertCount(120, $stored);
        $this->assertSame(120, $stored->where('paid', true)->count());
        $this->assertCount(1, $stored->pluck('payment_proof_path')->unique());
        $this->assertSame(1, $this->counts()['files']);
    }

    // --- CA-13: a retroativa mantém o que já nasce quitada --------------------------------

    public function test_a_retroactive_installment_expense_keeps_born_paid_quotas_and_pays_the_rest(): void
    {
        // 2026-09-20, closing_day nulo: junho, julho e agosto já fechados -> 3 quotas born_paid.
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario('2026-09-20 12:00:00');

        $quotas = [];
        foreach ([1 => '2026-06-05', 2 => '2026-07-05', 3 => '2026-08-05', 4 => '2026-09-05', 5 => '2026-10-05', 6 => '2026-11-05'] as $number => $date) {
            $quotas[] = ['number' => $number, 'date_expected' => $date, 'value_quota' => 100];
        }

        $response = $this->withToken($this->token($creditor))
            ->post('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, [
                'date_payment' => '2026-06-05',
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 6,
                'total_value' => 600,
                'quotas' => $quotas,
            ]) + [
                'comprovante' => UploadedFile::fake()->image('cartao.jpg'),
                'descricao_pagamento' => 'Cartão final 1234',
            ], ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('payment_registered', true);

        $stored = Expense::findOrFail($response->json('expense_id'))->quotas()->orderBy('number')->get();

        // As fechadas seguem como hoje: quitadas de nascença, sem arquivo nem descrição.
        foreach ($stored->take(3) as $quota) {
            $this->assertTrue($quota->born_paid, "parcela {$quota->number}");
            $this->assertTrue($quota->paid);
            $this->assertNull($quota->payment_proof_path);
            $this->assertNull($quota->payment_note);
        }

        // As demais recebem o pagamento do cadastro.
        foreach ($stored->slice(3) as $quota) {
            $this->assertFalse($quota->born_paid, "parcela {$quota->number}");
            $this->assertTrue($quota->paid);
            $this->assertNotNull($quota->payment_proof_path);
            $this->assertSame('Cartão final 1234', $quota->payment_note);
        }

        $this->assertSame(1, $this->counts()['files']);
    }

    public function test_a_retroactive_installment_expense_without_payment_is_the_one_of_today(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario('2026-09-20 12:00:00');

        $quotas = [];
        foreach ([1 => '2026-06-05', 2 => '2026-07-05', 3 => '2026-08-05', 4 => '2026-09-05'] as $number => $date) {
            $quotas[] = ['number' => $number, 'date_expected' => $date, 'value_quota' => 100];
        }

        $response = $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, [
                'date_payment' => '2026-06-05',
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 4,
                'total_value' => 400,
                'quotas' => $quotas,
            ]))
            ->assertStatus(201)
            ->assertJsonPath('payment_registered', false);

        $stored = Expense::findOrFail($response->json('expense_id'))->quotas()->orderBy('number')->get();

        $this->assertSame([true, true, true, false], $stored->pluck('born_paid')->all());
        $this->assertSame([true, true, true, false], $stored->pluck('paid')->all());
    }

    // --- CA-10 e CA-11: quem cadastra pode não ser o credor ------------------------------

    public function test_a_creator_who_is_not_the_creditor_registers_the_payment_in_the_creditors_name(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $creator] = $this->scenario();

        $response = $this->withToken($this->token($creator))
            ->postJson('/api/expenses', $this->body($group, $creator, $creditor, $creator, [
                'descricao_pagamento' => 'Ana pagou no cartão',
            ]))
            ->assertStatus(201)
            ->assertJsonPath('payment_registered', true);

        $expense = Expense::findOrFail($response->json('expense_id'));
        $quota = $expense->quotas()->firstOrFail();

        // O pagamento é do credor; o criador continua sendo só o criador da despesa.
        $this->assertSame($creator->id, $expense->user_creator_id);
        $this->assertSame($creditor->id, $expense->user_payer_id);
        $this->assertTrue($quota->paid);
        $this->assertSame($creditor->id, $quota->paid_by, 'paid_by é o credor, não quem cadastrou');
        $this->assertSame('Ana pagou no cartão', $quota->payment_note);
    }

    public function test_a_creator_who_is_not_the_creditor_also_pays_every_installment_in_the_creditors_name(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $creator] = $this->scenario();

        $response = $this->withToken($this->token($creator))
            ->postJson('/api/expenses', $this->body($group, $creator, $creditor, $creator, [
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 3,
                'total_value' => 300,
                'descricao_pagamento' => 'Cartão da Ana',
            ]))
            ->assertStatus(201);

        $quotas = Expense::findOrFail($response->json('expense_id'))->quotas;

        $this->assertSame(3, $quotas->where('paid', true)->count());
        $this->assertSame([$creditor->id], $quotas->pluck('paid_by')->unique()->values()->all());
    }

    public function test_a_creator_who_is_not_the_creditor_without_payment_data_is_the_expense_of_today(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $creator] = $this->scenario();

        $response = $this->withToken($this->token($creator))
            ->postJson('/api/expenses', $this->body($group, $creator, $creditor, $creator))
            ->assertStatus(201)
            ->assertJsonPath('payment_registered', false);

        $quota = Quota::where('expense_id', $response->json('expense_id'))->firstOrFail();

        $this->assertFalse($quota->paid);
        $this->assertNull($quota->paid_by);
        $this->assertSame(0, Notification::where('type', 'expense_paid_on_behalf')->count());
    }

    public function test_a_non_member_gets_the_same_refusal_with_payment_and_nothing_is_stored(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $outsider = User::factory()->create();
        $before = $this->counts();

        $body = $this->body($group, $outsider, $creditor, $debtor);

        $withoutPayment = $this->withToken($this->token($outsider))->postJson('/api/expenses', $body)->getStatusCode();
        $withPayment = $this->withToken($this->token($outsider))
            ->post('/api/expenses', $body + [
                'comprovante' => UploadedFile::fake()->image('c.jpg'),
                'descricao_pagamento' => 'Pago',
            ], ['Accept' => 'application/json'])->getStatusCode();

        $this->assertSame(404, $withoutPayment);
        $this->assertSame($withoutPayment, $withPayment);
        $this->assertSame($before, $this->counts(), 'nenhum arquivo gravado para quem não é do grupo');
    }

    public function test_a_payer_outside_the_group_is_refused_with_payment_and_nothing_is_stored(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $outsider = User::factory()->create();
        $before = $this->counts();

        $this->withToken($this->token($creditor))
            ->post('/api/expenses', $this->body($group, $creditor, $outsider, $debtor) + [
                'comprovante' => UploadedFile::fake()->image('c.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('user_payer_id');

        $this->assertSame($before, $this->counts());
    }

    // --- CA-17 a CA-19: falhas não deixam meio-termo --------------------------------------

    public function test_a_failure_to_write_the_file_stops_before_the_database_and_nothing_is_created(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $this->failProofWrites();
        $before = $this->counts();

        $response = $this->withToken($this->token($creditor))
            ->post('/api/expenses', $this->body($group, $creditor, $creditor, $debtor) + [
                'comprovante' => UploadedFile::fake()->image('c.jpg'),
                'descricao_pagamento' => 'Pago',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(500);
        $response->assertJsonMissingPath('details');
        $this->assertSame($before, $this->counts());
    }

    public function test_a_database_failure_after_the_file_was_written_rolls_back_removes_the_file_and_hides_details(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $before = $this->counts();

        Event::listen('eloquent.creating: '.Quota::class, fn () => throw new \RuntimeException('falha forçada no banco'));
        Log::spy();

        $response = $this->withToken($this->token($creditor))
            ->post('/api/expenses', $this->body($group, $creditor, $creditor, $debtor) + [
                'comprovante' => UploadedFile::fake()->image('c.jpg'),
                'descricao_pagamento' => 'Pago',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(500)->assertJsonPath('error', 'Erro ao criar despesa');
        $response->assertJsonMissingPath('details');
        $this->assertStringNotContainsString('falha forçada', $response->getContent(), 'a mensagem da exceção não vaza');

        $this->assertSame($before, $this->counts(), 'rollback: nem despesa, nem quota, nem arquivo órfão');
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_database_failure_without_payment_also_hides_details(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $before = $this->counts();

        Event::listen('eloquent.creating: '.Quota::class, fn () => throw new \RuntimeException('falha forçada no banco'));

        $response = $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor));

        $response->assertStatus(500)->assertJsonPath('error', 'Erro ao criar despesa');
        $response->assertJsonMissingPath('details');
        $this->assertSame($before, $this->counts());
    }

    public function test_a_failure_in_a_step_after_the_commit_keeps_the_expense_and_the_payment(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        Event::listen('eloquent.creating: '.Notification::class, fn () => throw new \RuntimeException('notificação fora do ar'));

        $response = $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, ['descricao_pagamento' => 'Pago']))
            ->assertStatus(201)
            ->assertJsonPath('payment_registered', true);

        $quota = Quota::where('expense_id', $response->json('expense_id'))->firstOrFail();
        $this->assertTrue($quota->paid);
        $this->assertSame('Pago', $quota->payment_note);
    }

    // --- CA-12 e CA-14 também valem para a Parcelada -------------------------------------

    public function test_an_installment_expense_that_starts_in_a_future_competence_with_payment_is_refused(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $before = $this->counts();

        $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, [
                'date_payment' => '2026-09-20',
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 3,
                'total_value' => 300,
                'descricao_pagamento' => 'Cartão',
            ]))
            ->assertStatus(422);

        $this->assertSame($before, $this->counts());
    }

    public function test_an_installment_expense_entirely_in_closed_competences_keeps_todays_refusal_with_payment(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario('2026-12-19 12:00:00');
        $before = $this->counts();

        $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, [
                'date_payment' => '2026-06-10',
                'expense_type' => 'IN_INSTALLMENTS',
                'installments' => 3,
                'total_value' => 300,
                'descricao_pagamento' => 'Cartão',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'Esta despesa parcelada está inteira em competências já fechadas. Para registrá-la, ao menos a última parcela precisa cair num ciclo ainda aberto.');

        $this->assertSame($before, $this->counts());
    }
}
