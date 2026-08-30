<?php

namespace Tests\Unit\WhatsApp;

use App\Models\Expense;
use App\Models\Quota;
use App\Models\SettlementConfirmation;
use App\Models\User;
use App\Support\WhatsApp\WhatsAppNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WhatsAppNotifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);

        config()->set('services.whatsapp', [
            'enabled' => true,
            'token' => 'tok',
            'phone_number_id' => '999',
            'api_version' => 'v21.0',
            'locale' => 'pt_BR',
            'templates' => ['expense_proof' => 'tpl_e', 'settlement_proof' => 'tpl_s'],
        ]);
    }

    private function user(int $id, string $name, ?string $whatsapp, bool $optIn): User
    {
        return (new User)->forceFill([
            'id' => $id,
            'name' => $name,
            'whatsapp' => $whatsapp,
            'notify_whatsapp' => $optIn,
        ]);
    }

    private function quota(Expense $expense, float $value, string $dateExpected): Quota
    {
        $quota = (new Quota)->forceFill([
            'id' => 77,
            'expense_id' => $expense->id,
            'value_quota' => $value,
            'date_expected' => $dateExpected,
        ]);
        $quota->setRelation('expense', $expense);

        return $quota;
    }

    public function test_expense_proof_notifica_so_pagadores_opt_in_menos_o_credor(): void
    {
        $credor = $this->user(1, 'Ana', '(11) 90000-0001', true);
        $optIn = $this->user(2, 'Bia', '(11) 90000-0002', true);
        $semWhats = $this->user(3, 'Caio', null, true);
        $optOut = $this->user(4, 'Duda', '(11) 90000-0004', false);

        $expense = (new Expense)->forceFill([
            'id' => 50, 'group_id' => 9, 'description' => 'Mercado',
            'expense_type' => 'IN_CASH', 'user_payer_id' => 1,
        ]);
        $expense->setRelation('payer', $credor);
        $expense->setRelation('payers', collect([$credor, $optIn, $semWhats, $optOut]));

        WhatsAppNotifier::expenseProofPaid($this->quota($expense, 1234.5, '2026-09-10'));

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request['to'] === '5511900000002'
                && $request['template']['name'] === 'tpl_e'
                && $request['template']['components'][0]['parameters'] === [
                    ['type' => 'text', 'text' => 'Ana'],
                    ['type' => 'text', 'text' => 'Mercado'],
                    ['type' => 'text', 'text' => 'À Vista'],
                    ['type' => 'text', 'text' => 'R$ 1.234,50'],
                    ['type' => 'text', 'text' => 'set/2026'],
                ]
                && $request['template']['components'][1]['parameters'][0]['text'] === 'groups/9/expenses/50';
        });
    }

    public function test_expense_proof_nao_envia_quando_ninguem_opta(): void
    {
        $credor = $this->user(1, 'Ana', '(11) 90000-0001', true);
        $expense = (new Expense)->forceFill([
            'id' => 50, 'group_id' => 9, 'description' => 'Mercado',
            'expense_type' => 'FIXED', 'user_payer_id' => 1,
        ]);
        $expense->setRelation('payer', $credor);
        $expense->setRelation('payers', collect([$credor, $this->user(2, 'Bia', null, false)]));

        WhatsAppNotifier::expenseProofPaid($this->quota($expense, 10.0, '2026-09-10'));

        Http::assertNothingSent();
    }

    public function test_settlement_proof_notifica_o_credor_opt_in(): void
    {
        $confirmation = (new SettlementConfirmation)->forceFill([
            'id' => 1, 'group_id' => 9, 'amount' => 500.0,
            'cycle_start' => '2026-09-01', 'from_user_id' => 2, 'to_user_id' => 1,
        ]);
        $confirmation->setRelation('toUser', $this->user(1, 'Ana', '(11) 90000-0001', true));
        $confirmation->setRelation('fromUser', $this->user(2, 'Bia', '(11) 90000-0002', true));

        WhatsAppNotifier::settlementProofConfirmed($confirmation);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request['to'] === '5511900000001'
                && $request['template']['name'] === 'tpl_s'
                && $request['template']['components'][0]['parameters'] === [
                    ['type' => 'text', 'text' => 'Bia'],
                    ['type' => 'text', 'text' => 'R$ 500,00'],
                    ['type' => 'text', 'text' => 'set/2026'],
                ]
                && $request['template']['components'][1]['parameters'][0]['text'] === 'groups/9/payments';
        });
    }

    public function test_settlement_proof_ignora_credor_sem_opt_in(): void
    {
        $confirmation = (new SettlementConfirmation)->forceFill([
            'id' => 1, 'group_id' => 9, 'amount' => 500.0,
            'cycle_start' => '2026-09-01', 'from_user_id' => 2, 'to_user_id' => 1,
        ]);
        $confirmation->setRelation('toUser', $this->user(1, 'Ana', '(11) 90000-0001', false));
        $confirmation->setRelation('fromUser', $this->user(2, 'Bia', '(11) 90000-0002', true));

        WhatsAppNotifier::settlementProofConfirmed($confirmation);

        Http::assertNothingSent();
    }

    public function test_no_op_quando_a_feature_esta_desligada(): void
    {
        config()->set('services.whatsapp.enabled', false);

        $credor = $this->user(1, 'Ana', '(11) 90000-0001', true);
        $expense = (new Expense)->forceFill([
            'id' => 50, 'group_id' => 9, 'description' => 'Mercado',
            'expense_type' => 'IN_CASH', 'user_payer_id' => 1,
        ]);
        $expense->setRelation('payer', $credor);
        $expense->setRelation('payers', collect([$credor, $this->user(2, 'Bia', '(11) 90000-0002', true)]));

        WhatsAppNotifier::expenseProofPaid($this->quota($expense, 10.0, '2026-09-10'));

        Http::assertNothingSent();
    }

    public function test_destinatario_com_telefone_invalido_e_logado_sem_travar_os_demais(): void
    {
        Log::spy();

        $credor = $this->user(1, 'Ana', '(11) 90000-0001', true);
        $invalido = $this->user(2, 'Bia', '(11) 9999', true);
        $ok = $this->user(3, 'Caio', '(11) 90000-0003', true);

        $expense = (new Expense)->forceFill([
            'id' => 50, 'group_id' => 9, 'description' => 'Mercado',
            'expense_type' => 'IN_CASH', 'user_payer_id' => 1,
        ]);
        $expense->setRelation('payer', $credor);
        $expense->setRelation('payers', collect([$credor, $invalido, $ok]));

        WhatsAppNotifier::expenseProofPaid($this->quota($expense, 10.0, '2026-09-10'));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['to'] === '5511900000003');
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains($msg, 'inválido'))->once();
    }
}
