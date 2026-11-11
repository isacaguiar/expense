<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMemberResponsible;
use App\Models\Notification;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\Concerns\BuildsThirdPartyProofScenarios;
use Tests\TestCase;

/**
 * Os dois achados MÉDIOS da revisão do `security-reviewer` (feature
 * 20261110-quem-paga-se-declara-e-credor-recusa, TASK-605), ambos consequência do vínculo sem aceite do
 * devedor (D1-A): antes só quem o devedor escolhera podia enviar o comprovante por ele; agora, qualquer
 * membro.
 *
 * 1. Só o devedor substitui o comprovante dele: quem envia por outra pessoa não sobrescreve um comprovante
 *    "enviado" (o do próprio devedor), que valia para o selo, nem apaga o arquivo dele.
 * 2. O WhatsApp ao credor segue o sino: para o envio de outra pessoa, só na primeira vez e depois de uma
 *    recusa; o devedor que reenvia o próprio comprovante avisa de novo, como sempre.
 *
 * Nos testes de WhatsApp há uma requisição por teste e a linha já nasce no banco: os callbacks
 * `afterResponse` do cliente de teste se acumulam entre requisições e esconderiam a contagem.
 */
class SettlementProofOwnershipTest extends TestCase
{
    use BuildsSettlementScenarios;
    use BuildsThirdPartyProofScenarios;
    use DatabaseTransactions;

    private const OWN_PROOF_ERROR = 'O devedor já enviou o comprovante deste acerto.';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * O cenário vinculado, mas com o comprovante do **próprio devedor** (estado "enviado"), que já vale
     * para o selo.
     *
     * @return array{0: Group, 1: User, 2: User, 3: User, 4: SettlementConfirmation}
     */
    private function debtorsOwnProofScenario(): array
    {
        $scenario = $this->linkedScenario();
        $scenario[4]->update(['confirmed_by' => null]);

        return $scenario;
    }

    /** @return list<string> */
    private function proofFiles(Group $group): array
    {
        return Storage::disk('local')->allFiles("comprovantes/{$group->id}");
    }

    private function settlementNotices(): int
    {
        return Notification::where('type', 'settlement_confirmed')->count();
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

    // --- Achado 1: só o devedor substitui o comprovante dele ---------------

    /** @return array<string, array{0: string}> */
    public static function sendersWhoAreNotTheDebtor(): array
    {
        return [
            'quem paga por ele' => ['responsible'],
            'o credor vinculado como responsável' => ['creditor'],
        ];
    }

    /** @dataProvider sendersWhoAreNotTheDebtor */
    public function test_nobody_but_the_debtor_replaces_the_debtors_own_proof(string $who): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->debtorsOwnProofScenario();
        if ($who === 'creditor') {
            GroupMemberResponsible::create(['group_id' => $group->id, 'user_id' => $debtor->id, 'responsible_user_id' => $creditor->id]);
        }
        $sender = $who === 'creditor' ? $creditor : $responsible;
        $before = $proof->fresh()->toArray();

        DB::enableQueryLog();
        $this->confirmAs($sender, $group, $creditor, $debtor->id)
            ->assertStatus(422)
            ->assertJson(['error' => self::OWN_PROOF_ERROR]);
        $queries = collect(DB::getQueryLog())->pluck('query');

        // Barra antes de gravar o arquivo: a rota nem abre o caminho de escrita, que lê o par com lock.
        $this->assertFalse($queries->contains(fn ($q) => str_contains($q, 'for update')), 'decidiu antes do caminho de escrita');
        $this->assertSame($before, $proof->fresh()->toArray(), 'a linha do devedor não muda');
        $this->assertSame([$proof->proof_path], $this->proofFiles($group), 'o arquivo dele segue e nenhum novo aparece');
        $this->assertSame(0, $this->settlementNotices());
        $this->assertSame(SettlementConfirmation::STATUS_SENT, $proof->fresh()->status());
        $this->assertTrue(SettlementConfirmation::countsForSettlement()->whereKey($proof->id)->exists(), 'segue valendo para o selo');
    }

    public function test_a_row_that_names_the_debtor_as_the_sender_is_the_debtors_own_proof_too(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $proof->update(['confirmed_by' => $debtor->id]);
        $before = $proof->fresh()->toArray();

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)
            ->assertStatus(422)
            ->assertJson(['error' => self::OWN_PROOF_ERROR]);

        $this->assertSame($before, $proof->fresh()->toArray());
        $this->assertSame([$proof->proof_path], $this->proofFiles($group));
    }

    public function test_the_debtor_still_replaces_their_own_proof(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->debtorsOwnProofScenario();
        $oldPath = $proof->proof_path;

        $this->confirmAs($debtor, $group, $creditor)->assertOk();

        $row = $proof->fresh();
        $this->assertNull($row->confirmed_by);
        $this->assertNotSame($oldPath, $row->proof_path);
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertSame([$row->proof_path], $this->proofFiles($group));
    }

    public function test_a_responsible_still_replaces_a_proof_that_awaits_acceptance(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $oldPath = $proof->proof_path;

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();

        $row = $proof->fresh();
        $this->assertSame($responsible->id, (int) $row->confirmed_by);
        $this->assertNotSame($oldPath, $row->proof_path);
        $this->assertSame([$row->proof_path], $this->proofFiles($group));
    }

    public function test_a_responsible_still_sends_when_the_proof_was_refused_or_does_not_exist(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $proof->update(['refused_at' => '2026-08-20 11:00:00']);
        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();
        $this->assertSame(SettlementConfirmation::STATUS_AWAITING_ACCEPTANCE, $proof->fresh()->status());

        $proof->delete();
        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();
        $this->assertSame(1, SettlementConfirmation::where('group_id', $group->id)->count());
    }

    public function test_the_debtors_proof_that_appears_between_the_check_and_the_write_is_not_overwritten(): void
    {
        // O comprovante aguardava aceite na leitura do controller; o devedor o troca pelo dele e commita antes
        // de o reenvio travar a linha. O reenvio relê com lock, vê o comprovante do devedor e não grava.
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $swapped = false;

        DB::connection()->beforeExecuting(function (string $query) use (&$swapped, $proof) {
            if ($swapped || ! str_contains($query, 'ex_settlement_confirmations') || ! str_contains($query, 'for update')) {
                return;
            }

            $swapped = true;
            DB::table('ex_settlement_confirmations')->where('id', $proof->id)->update(['confirmed_by' => null]);
        });

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)
            ->assertStatus(422)
            ->assertJson(['error' => self::OWN_PROOF_ERROR]);

        $this->assertTrue($swapped, 'o reenvio releu a linha com lock');
        $this->assertNull($proof->fresh()->confirmed_by, 'a linha segue sendo a do devedor');
        $this->assertSame([$proof->proof_path], $this->proofFiles($group), 'o arquivo novo não fica órfão');
        $this->assertSame(0, $this->settlementNotices());
    }

    // --- Achado 2: o WhatsApp segue o sino para quem envia por outra pessoa -

    public function test_a_responsible_resending_over_a_proof_that_awaits_acceptance_does_not_page_the_creditor(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->linkedScenario();
        $this->enableWhatsAppFor($creditor);

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();

        Http::assertNothingSent();
        $this->assertSame(0, $this->settlementNotices(), 'o sino também não repete');
    }

    public function test_a_responsible_resending_after_a_refusal_pages_the_creditor_once(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $proof->update(['refused_at' => '2026-08-20 11:00:00']);
        $this->enableWhatsAppFor($creditor);

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(1, $this->settlementNotices());
    }

    public function test_a_responsibles_first_proof_pages_the_creditor_once(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->linkedScenario();
        $proof->delete();
        $this->enableWhatsAppFor($creditor);

        $this->confirmAs($responsible, $group, $creditor, $debtor->id)->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(1, $this->settlementNotices());
    }

    public function test_the_debtors_own_resend_still_pages_the_creditor(): void
    {
        [$group, $creditor, $debtor] = $this->debtorsOwnProofScenario();
        $this->enableWhatsAppFor($creditor);

        $this->confirmAs($debtor, $group, $creditor)->assertOk();

        Http::assertSentCount(1);
    }
}
