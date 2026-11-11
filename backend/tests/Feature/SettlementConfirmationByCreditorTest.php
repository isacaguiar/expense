<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMemberResponsible;
use App\Models\Notification;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\Concerns\BuildsThirdPartyProofScenarios;
use Tests\TestCase;

/**
 * O credor que envia o comprovante do próprio acerto (feature 20261110-quem-paga-se-declara-e-credor-recusa,
 * TASK-602, item 143, specify R12): sendo ele o responsável do devedor, o aviso "X confirmou um pagamento"
 * (sino e WhatsApp) seria sobre a ação que ele mesmo acabou de fazer. O envio vale como sempre; só o aviso
 * some. Para o devedor e para outro responsável, os avisos saem como hoje.
 */
class SettlementConfirmationByCreditorTest extends TestCase
{
    use BuildsSettlementScenarios;
    use BuildsThirdPartyProofScenarios;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * O cenário com o comprovante do responsável **apagado** (o próximo envio é o primeiro do par) e o
     * credor também vinculado como responsável do devedor, o único jeito de ele enviar o comprovante.
     *
     * @return array{0: Group, 1: User, 2: User, 3: User, 4: SettlementConfirmation}
     */
    private function scenario(): array
    {
        $scenario = $this->linkedScenario();
        [$group, $creditor, $debtor, , $proof] = $scenario;
        $proof->delete();

        GroupMemberResponsible::create([
            'group_id' => $group->id,
            'user_id' => $debtor->id,
            'responsible_user_id' => $creditor->id,
        ]);

        return $scenario;
    }

    /** O credor aceita WhatsApp, com a feature ligada e o envio simulado. */
    private function enableWhatsAppFor(User $creditor): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200)]);
        config()->set('services.whatsapp', [
            'enabled' => true,
            'token' => 'tok',
            'phone_number_id' => '999',
            'api_version' => 'v21.0',
            'locale' => 'pt_BR',
            'templates' => ['expense_proof' => 'tpl_e', 'settlement_proof' => 'tpl_s'],
        ]);

        $creditor->whatsapp = '(11) 90000-0001';
        $creditor->notify_whatsapp = true;
        $creditor->save();
    }

    private function settlementNotices(): int
    {
        return Notification::where('type', 'settlement_confirmed')->count();
    }

    public function test_the_creditor_sending_gets_no_bell_notice(): void
    {
        [$group, $creditor, $debtor] = $this->scenario();

        $this->confirmAs($creditor, $group, $creditor, $debtor->id)->assertOk();

        $this->assertSame(0, $this->settlementNotices(), 'nem o credor nem ninguém recebe o aviso');

        // O envio vale como sempre: só o aviso some.
        $row = SettlementConfirmation::where('group_id', $group->id)->sole();
        $this->assertSame($creditor->id, (int) $row->confirmed_by);
        $this->assertSame(SettlementConfirmation::STATUS_AWAITING_ACCEPTANCE, $row->status());
    }

    public function test_the_creditor_sending_triggers_no_whatsapp(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->scenario();
        $this->enableWhatsAppFor($creditor);

        $this->confirmAs($creditor, $group, $creditor, $debtor->id)->assertOk();
        Http::assertNothingSent();

        // Controle: com a mesma configuração, o primeiro envio de outra pessoa chega ao credor (reenviar por
        // cima de um comprovante que aguarda aceite não avisa de novo: `SettlementProofOwnershipTest`).
        SettlementConfirmation::where('group_id', $group->id)->delete();
        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();
        Http::assertSentCount(1);
    }

    public function test_the_creditor_resending_after_a_refusal_also_gets_no_notice(): void
    {
        [$group, $creditor, $debtor] = $this->scenario();
        $this->enableWhatsAppFor($creditor);
        $this->confirmAs($creditor, $group, $creditor, $debtor->id)->assertOk();
        SettlementConfirmation::where('group_id', $group->id)->update(['refused_at' => '2026-08-20 11:00:00']);

        $this->confirmAs($creditor, $group, $creditor, $debtor->id)->assertOk();

        $this->assertSame(0, $this->settlementNotices(), 'reenviar depois de recusar avisa o credor, menos quando o credor é quem reenvia');
        Http::assertNothingSent();
        $this->assertNull(SettlementConfirmation::where('group_id', $group->id)->sole()->refused_at);
    }

    public function test_the_debtor_and_a_responsible_still_notify_the_creditor(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->scenario();
        $this->enableWhatsAppFor($creditor);

        $this->confirmAs($debtor, $group, $creditor)->assertOk();
        $this->assertSame(1, $this->settlementNotices());
        Http::assertSentCount(1);

        SettlementConfirmation::where('group_id', $group->id)->delete();

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();
        $this->assertSame(2, $this->settlementNotices());
        Http::assertSentCount(2);
        $this->assertSame([$creditor->id], Notification::where('type', 'settlement_confirmed')->pluck('user_id')->unique()->map(fn ($id) => (int) $id)->all());
    }
}
