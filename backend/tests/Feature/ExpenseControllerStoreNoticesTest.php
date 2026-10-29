<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Notification;
use App\Models\SettlementConfirmation;
use App\Models\User;
use App\Services\CycleSummaryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O que acontece em volta de um cadastro com pagamento (feature
 * 20261027-pagamento-no-cadastro-despesa, specify.md R14, R21, CA-24, CA-25 e
 * CA-26): um só conjunto de avisos por cadastro (nunca um por parcela), o aviso
 * ao credor quando outra pessoa registrou o pagamento em nome dele, o WhatsApp
 * do comprovante uma vez, e nenhum acerto quitado nem ciclo selado de graça.
 */
class ExpenseControllerStoreNoticesTest extends TestCase
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
    private function scenario(): array
    {
        Carbon::setTestNow('2026-08-19 12:00:00');
        Storage::fake('local');

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'República']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        return ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor];
    }

    /** @return array<string, mixed> */
    private function body(Group $group, User $creator, User $creditor, User $debtor, array $overrides = []): array
    {
        return array_merge([
            'date_payment' => '2026-08-10',
            'description' => 'Geladeira',
            'expense_type' => 'IN_INSTALLMENTS',
            'installments' => 3,
            'total_value' => 300,
            'group_id' => $group->id,
            'user_creator_id' => $creator->id,
            'user_payer_id' => $creditor->id,
            'payers' => [$creditor->id, $debtor->id],
        ], $overrides);
    }

    private function token(User $user): string
    {
        return auth('api')->login($user);
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

    private function optIn(User $user, string $whatsapp): void
    {
        $user->whatsapp = $whatsapp;
        $user->notify_whatsapp = true;
        $user->save();
    }

    // --- CA-25: um conjunto de avisos por cadastro --------------------------------------

    public function test_expense_paid_is_sent_once_with_the_total_of_the_installment_purchase(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, ['descricao_pagamento' => 'Cartão']))
            ->assertStatus(201);

        $rows = Notification::where('type', 'expense_paid')->get();

        // Três parcelas pagas, um aviso só (para o único devedor), com o valor da compra inteira.
        $this->assertCount(1, $rows);
        $this->assertSame($debtor->id, $rows->first()->user_id);
        $this->assertSame('300.00', $rows->first()->data['amount']);
        $this->assertSame($creditor->name, $rows->first()->data['actorName']);

        $this->assertSame(1, Notification::where('type', 'expense_created')->count());
    }

    public function test_no_expense_paid_notice_without_a_payment(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor))
            ->assertStatus(201);

        $this->assertSame(0, Notification::where('type', 'expense_paid')->count());
        $this->assertSame(0, Notification::where('type', 'expense_paid_on_behalf')->count());
        $this->assertSame(1, Notification::where('type', 'expense_created')->count());
    }

    public function test_the_creditor_is_told_once_when_someone_else_registers_the_payment_in_their_name(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $creator] = $this->scenario();

        $this->withToken($this->token($creator))
            ->postJson('/api/expenses', $this->body($group, $creator, $creditor, $creator, ['descricao_pagamento' => 'Cartão da Ana']))
            ->assertStatus(201);

        $rows = Notification::where('type', 'expense_paid_on_behalf')->get();

        $this->assertCount(1, $rows);
        $this->assertSame($creditor->id, $rows->first()->user_id);
        $this->assertSame($creator->name, $rows->first()->data['actorName']);
        $this->assertSame('Geladeira', $rows->first()->data['expenseDescription']);
        $this->assertSame(3, $rows->first()->data['quotasCount']);
    }

    public function test_the_creditor_is_not_told_when_they_register_the_payment_themselves(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, ['descricao_pagamento' => 'Cartão']))
            ->assertStatus(201);

        $this->assertSame(0, Notification::where('type', 'expense_paid_on_behalf')->count());
    }

    /**
     * Achado N5 da revisão de segurança (TASK-532): num `multipart` de verdade todo valor chega
     * como string, e o `store()` gravava `user_payer_id` como veio. As comparações estritas do
     * `Notifier` (`$id === $expense->user_creator_id`) falhavam, e o credor que cadastra com foto
     * recebia avisos sobre ele mesmo. O harness de teste mantém os tipos, por isso o corpo aqui é
     * convertido para string à mão.
     */
    public function test_a_creditor_registering_with_a_photo_through_a_real_multipart_form_is_not_told_about_themselves(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();

        $form = array_map(
            fn ($value) => is_array($value) ? array_map('strval', $value) : (string) $value,
            $this->body($group, $creditor, $creditor, $debtor, ['descricao_pagamento' => 'Cartão'])
        );

        $this->withToken($this->token($creditor))
            ->post('/api/expenses', $form + ['comprovante' => UploadedFile::fake()->image('cartao.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $this->assertSame(0, Notification::where('type', 'expense_paid_on_behalf')->count(), 'o credor não é avisado de que ele mesmo registrou');
        $this->assertSame(0, Notification::where('type', 'expense_paid')->where('user_id', $creditor->id)->count(), 'nem recebe o aviso de despesa paga sobre ele mesmo');
        $this->assertSame(1, Notification::where('type', 'expense_paid')->where('user_id', $debtor->id)->count());
    }

    /**
     * Achado N1 da revisão de segurança (TASK-532 → TASK-545): quem cadastra em nome do credor
     * é quem os outros devedores devem ver como autor do registro, e ele mesmo não recebe o
     * aviso do que fez. O credor continua sabendo pelo `expense_paid_on_behalf`.
     */
    public function test_when_someone_else_registers_the_payment_the_debtors_are_told_it_was_them_not_the_creditor(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $creator] = $this->scenario();
        $third = User::factory()->create();
        $group->members()->attach($third->id);

        $this->withToken($this->token($creator))
            ->postJson('/api/expenses', [
                'user_payer_id' => $creditor->id,
                'payers' => [$creditor->id, $creator->id, $third->id],
                'descricao_pagamento' => 'Cartão da Ana',
            ] + $this->body($group, $creator, $creditor, $creator))
            ->assertStatus(201);

        $rows = Notification::where('type', 'expense_paid')->get();

        $this->assertSame([$third->id], $rows->pluck('user_id')->all(), 'só o terceiro: nem o credor nem quem registrou');
        $this->assertSame($creator->name, $rows->first()->data['actorName']);
        $this->assertSame('300.00', $rows->first()->data['amount']);
        $this->assertSame(1, Notification::where('type', 'expense_paid_on_behalf')->where('user_id', $creditor->id)->count());
    }

    public function test_no_whatsapp_proof_notice_when_someone_else_registers_the_payment(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $creator] = $this->scenario();
        $third = User::factory()->create();
        $group->members()->attach($third->id);
        Http::fake();
        $this->enableWhatsApp();
        $this->optIn($third, '(11) 90000-0003');

        $this->withToken($this->token($creator))
            ->post('/api/expenses', [
                'user_payer_id' => $creditor->id,
                'payers' => [$creditor->id, $creator->id, $third->id],
            ] + $this->body($group, $creator, $creditor, $creator) + [
                'comprovante' => UploadedFile::fake()->image('cartao.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(201);

        // O texto do WhatsApp diz "<pagador> pagou": seria o credor, que não pagou nada.
        Http::assertNothingSent();
        $this->assertSame(1, Notification::where('type', 'expense_paid')->where('user_id', $third->id)->count(), 'o aviso dentro do app segue saindo');
    }

    // --- CA-25: o WhatsApp do comprovante, uma vez ---------------------------------------

    public function test_the_whatsapp_proof_notice_is_sent_once_for_a_whole_installment_purchase_with_a_photo(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200)]);
        $this->enableWhatsApp();
        $this->optIn($debtor, '(11) 90000-0002');

        $this->withToken($this->token($creditor))
            ->post('/api/expenses', $this->body($group, $creditor, $creditor, $debtor) + [
                'comprovante' => UploadedFile::fake()->image('cartao.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(201);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['to'] === '5511900000002'
            && $request['template']['name'] === 'tpl_e'
            && $request['template']['components'][0]['parameters'] === [
                ['type' => 'text', 'text' => $creditor->name],
                ['type' => 'text', 'text' => 'Geladeira'],
                ['type' => 'text', 'text' => 'Parcelada'],
                ['type' => 'text', 'text' => 'R$ 100,00'],
                ['type' => 'text', 'text' => 'ago/2026'],
            ]);
    }

    public function test_no_whatsapp_notice_when_only_a_note_is_sent(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        Http::fake();
        $this->enableWhatsApp();
        $this->optIn($debtor, '(11) 90000-0002');

        $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $debtor, ['descricao_pagamento' => 'Cartão']))
            ->assertStatus(201);

        Http::assertNothingSent();
    }

    public function test_the_whatsapp_provider_being_down_does_not_break_the_registration(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => 'down'], 500)]);
        $this->enableWhatsApp();
        $this->optIn($debtor, '(11) 90000-0002');

        $response = $this->withToken($this->token($creditor))
            ->post('/api/expenses', $this->body($group, $creditor, $creditor, $debtor) + [
                'comprovante' => UploadedFile::fake()->image('cartao.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('payment_registered', true);

        $this->assertSame(3, Expense::findOrFail($response->json('expense_id'))->quotas()->where('paid', true)->count());
    }

    // --- CA-26: o cadastro não sela o ciclo ------------------------------------------------

    public function test_the_registration_does_not_seal_the_cycle_even_when_everything_ends_up_settled(): void
    {
        ['group' => $group, 'creditor' => $creditor] = $this->scenario();

        // O credor é o único participante: pago, não sobra nenhum acerto, o ciclo estaria quitado.
        $this->withToken($this->token($creditor))
            ->postJson('/api/expenses', $this->body($group, $creditor, $creditor, $creditor, [
                'expense_type' => 'IN_CASH',
                'installments' => 1,
                'total_value' => 100,
                'payers' => [$creditor->id],
                'descricao_pagamento' => 'Pago',
            ]))
            ->assertStatus(201);

        $this->assertTrue(app(CycleSummaryService::class)->isFullySettled($group, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')));
        $this->assertSame(0, GroupCycleSnapshot::where('group_id', $group->id)->count(), 'nenhum snapshot gravado');
        $this->assertSame(0, Notification::where('type', 'cycle_settled')->count());
    }

    // --- CA-24: nenhum acerto é quitado ----------------------------------------------------

    public function test_settlements_and_balances_are_identical_to_the_same_expense_without_payment(): void
    {
        ['group' => $withPayment, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $withoutPayment = Group::create(['name' => 'República (sem pagamento)']);
        $withoutPayment->members()->attach([$creditor->id, $debtor->id]);
        $token = $this->token($creditor);

        $this->withToken($token)->postJson('/api/expenses', $this->body($withPayment, $creditor, $creditor, $debtor, ['descricao_pagamento' => 'Cartão']))->assertStatus(201);
        $this->withToken($token)->postJson('/api/expenses', $this->body($withoutPayment, $creditor, $creditor, $debtor))->assertStatus(201);

        $service = app(CycleSummaryService::class);
        $start = Carbon::parse('2026-08-01');
        $end = Carbon::parse('2026-08-31');

        $paid = $service->compute($withPayment, $start, $end);
        $pending = $service->compute($withoutPayment, $start, $end);

        $this->assertEquals($pending['settlements'], $paid['settlements']);
        $this->assertEquals($pending['balances'], $paid['balances']);
        $this->assertEquals(0, SettlementConfirmation::count(), 'nenhuma confirmação de acerto criada');

        // O devedor segue devendo 50,00 (100,00 da parcela, em dois); só o pendente cai.
        $this->assertEqualsWithDelta(50.0, $paid['settlements'][0]['amount'], 0.001);
        $this->assertEqualsWithDelta(100.0, $pending['totals']['pending'], 0.001);
        $this->assertEqualsWithDelta(0.0, $paid['totals']['pending'], 0.001);
        $this->assertFalse($service->isFullySettled($withPayment, $start, $end), 'pago, mas o acerto do devedor segue em aberto');
    }
}
