<?php

namespace Tests\Feature;

use App\Models\SettlementConfirmation;
use App\Services\SettlementConfirmationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\Concerns\BuildsThirdPartyProofScenarios;
use Tests\Concerns\CapturesTransactionQueries;
use Tests\TestCase;

/**
 * Item 147 do backlog (feature 20261114-historico-de-comprovantes-e-justificativa, plan §8, TASK-618):
 * aceitar e recusar não podem reescrever `confirmed_at`, a data de **envio** do comprovante.
 *
 * `confirmed_at` é a primeira coluna `timestamp NOT NULL` da tabela; num MySQL com
 * `explicit_defaults_for_timestamp` desligado (a produção pode estar assim) ela ganha
 * `ON UPDATE CURRENT_TIMESTAMP`, e um `UPDATE` que não a liste a reescreve. O banco de teste tem a opção
 * ligada e não mostra o problema, então o que se afirma aqui, e vale em qualquer configuração, é o **SQL**:
 * o `UPDATE` lista `confirmed_at` no `SET` (atribuindo a ele mesmo), o que impede o `ON UPDATE`.
 */
class SettlementDecisionKeepsConfirmedAtTest extends TestCase
{
    use BuildsSettlementScenarios;
    use BuildsThirdPartyProofScenarios;
    use CapturesTransactionQueries;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * O `SET` do `UPDATE` de `ex_settlement_confirmations` que o `$action` executou, ou falha se não houve
     * exatamente um.
     *
     * @param  callable(): mixed  $action
     */
    private function updateSetClause(callable $action): string
    {
        $updates = array_values(array_filter(
            $this->queriesAfterTheTransactionOpens($action),
            fn (string $sql) => str_starts_with(strtolower($sql), 'update `ex_settlement_confirmations`')
        ));

        $this->assertCount(1, $updates, 'um UPDATE só na linha do comprovante: '.implode(' | ', $updates));
        $this->assertSame(1, preg_match('/ set (.*) where /is', $updates[0], $matches), $updates[0]);

        return $matches[1];
    }

    private function storedConfirmedAt(SettlementConfirmation $proof): string
    {
        return (string) DB::table('ex_settlement_confirmations')->where('id', $proof->id)->value('confirmed_at');
    }

    // --- o SQL lista `confirmed_at` ---------------------------------------

    public function test_accept_lists_confirmed_at_in_the_update_set(): void
    {
        [$group, $creditor, $debtor] = $this->thirdPartyScenario();

        $set = $this->updateSetClause(fn () => $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/groups/{$group->id}/settlements/accept", $this->pairBody($debtor, $creditor))
            ->assertOk());

        $this->assertStringContainsString('`confirmed_at`', $set, 'o UPDATE de aceitar precisa listar confirmed_at');
        $this->assertStringContainsString('`accepted_at`', $set);
    }

    public function test_refuse_lists_confirmed_at_in_the_update_set(): void
    {
        [$group, $creditor, $debtor] = $this->thirdPartyScenario();

        $set = $this->updateSetClause(fn () => $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/groups/{$group->id}/settlements/refuse", $this->pairBody($debtor, $creditor))
            ->assertOk());

        $this->assertStringContainsString('`confirmed_at`', $set, 'o UPDATE de recusar precisa listar confirmed_at');
        $this->assertStringContainsString('`refused_at`', $set);
    }

    // --- o valor não muda --------------------------------------------------

    public function test_accept_keeps_the_sent_date(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario();
        $this->assertSame($this->proofSentAt(), $this->storedConfirmedAt($proof));

        $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/groups/{$group->id}/settlements/accept", $this->pairBody($debtor, $creditor))
            ->assertOk();

        $this->assertSame($this->proofSentAt(), $this->storedConfirmedAt($proof));
        $this->assertNotNull($proof->fresh()->accepted_at);
    }

    public function test_refuse_keeps_the_sent_date(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario();

        $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/groups/{$group->id}/settlements/refuse", $this->pairBody($debtor, $creditor))
            ->assertOk();

        $this->assertSame($this->proofSentAt(), $this->storedConfirmedAt($proof));
        $this->assertNotNull($proof->fresh()->refused_at);
    }

    // --- o model devolvido e o selo ---------------------------------------

    public function test_the_service_returns_the_confirmation_with_the_new_values(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->thirdPartyScenario();
        $service = app(SettlementConfirmationService::class);

        $accepted = $service->accept($group, $debtor->id, $creditor->id, 0);

        $this->assertTrue($accepted->is($proof));
        $this->assertSame('2026-08-19 00:00:00', $accepted->accepted_at->toDateTimeString());
        $this->assertSame(SettlementConfirmation::STATUS_ACCEPTED, $accepted->status());
        $this->assertFalse($accepted->isDirty(), 'o model não fica com atributo pendente de gravação');
        $this->assertSame($accepted->accepted_at->toDateTimeString(), $proof->fresh()->accepted_at->toDateTimeString());
    }

    public function test_the_service_returns_the_refused_confirmation_with_the_new_values(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario();

        $refused = app(SettlementConfirmationService::class)->refuse($group, $debtor->id, $creditor->id, 0);

        $this->assertSame('2026-08-19 00:00:00', $refused->refused_at->toDateTimeString());
        $this->assertSame(SettlementConfirmation::STATUS_REFUSED, $refused->status());
        $this->assertFalse($refused->isDirty());
        $this->assertSame($refused->refused_at->toDateTimeString(), $proof->fresh()->refused_at->toDateTimeString());
    }

    public function test_accepting_the_last_proof_still_seals_the_cycle(): void
    {
        [$group, $creditor, $debtor] = $this->thirdPartyScenario(paid: true);
        $this->assertNull($this->sealedSnapshot($group));

        $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/groups/{$group->id}/settlements/accept", $this->pairBody($debtor, $creditor))
            ->assertOk();

        $this->assertNotNull($this->sealedSnapshot($group));
    }

    // --- a premissa do plano ----------------------------------------------

    /**
     * Teste de caracterização do framework, e a razão de a correção não ser a que o backlog sugere: o
     * `update` do Eloquent só grava atributo **sujo**, então atribuir a `confirmed_at` o valor que ele já
     * tem não o põe no `SET`, e a proteção contra o `ON UPDATE` seria nula. Se uma versão futura do Laravel
     * mudar isso, este teste falha e o plano pode ser simplificado.
     */
    public function test_an_eloquent_update_with_the_same_value_does_not_list_the_column(): void
    {
        [, , , , $proof] = $this->thirdPartyScenario();

        $set = $this->updateSetClause(fn () => DB::transaction(
            fn () => $proof->update(['accepted_at' => Carbon::now(), 'confirmed_at' => $proof->confirmed_at])
        ));

        $this->assertStringNotContainsString('`confirmed_at`', $set);
    }
}
