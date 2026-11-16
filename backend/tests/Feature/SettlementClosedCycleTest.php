<?php

namespace Tests\Feature;

use App\Exceptions\SettlementDecisionRefused;
use App\Models\GroupCycleSnapshot;
use App\Services\SettlementConfirmationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\Concerns\BuildsThirdPartyProofScenarios;
use Tests\TestCase;

/**
 * `SettlementConfirmationService::closedCycle` (feature 20261114-historico-de-comprovantes-e-justificativa,
 * plan §5, TASK-625): o teste "o ciclo está fechado" sai de `decidableCycle`, **sem** olhar o selo, para o
 * complemento (D7-B) poder usá-lo em ciclo encerrado. `decidableCycle` passa a chamá-lo e continua com as
 * **mesmas duas mensagens** de antes (ciclo não fechado e ciclo já encerrado).
 */
class SettlementClosedCycleTest extends TestCase
{
    use BuildsSettlementScenarios;
    use BuildsThirdPartyProofScenarios;
    use DatabaseTransactions;

    private const NOT_CLOSED = 'O acerto só pode ser confirmado depois que a competência é fechada.';

    private const SEALED = 'Esta competência já foi encerrada.';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function service(): SettlementConfirmationService
    {
        return app(SettlementConfirmationService::class);
    }

    private function seal($group): void
    {
        GroupCycleSnapshot::where('group_id', $group->id)->where('cycle_start', '2026-08-01')->update(['settled_at' => $this->proofSentAt()]);
    }

    // --- closedCycle -------------------------------------------------------

    public function test_a_manually_closed_cycle_is_closed(): void
    {
        [$group] = $this->thirdPartyScenario();

        $cycle = $this->service()->closedCycle($group, 0);

        $this->assertSame('2026-08-01', $cycle['start']->toDateString());
        $this->assertSame('2026-08-31', $cycle['end']->toDateString());
    }

    public function test_a_cycle_closed_by_date_is_closed(): void
    {
        [$group] = $this->thirdPartyScenario(closeCycle: false);
        Carbon::setTestNow('2026-09-20');

        $cycle = $this->service()->closedCycle($group, 1);

        $this->assertSame('2026-08-01', $cycle['start']->toDateString());
        $this->assertSame('closed', $cycle['status']);
    }

    public function test_a_sealed_cycle_is_still_closed(): void
    {
        [$group] = $this->thirdPartyScenario(paid: true);
        $this->seal($group);

        $cycle = $this->service()->closedCycle($group, 0);

        $this->assertSame('2026-08-01', $cycle['start']->toDateString());
    }

    public function test_an_open_cycle_is_not_closed(): void
    {
        [$group] = $this->thirdPartyScenario(closeCycle: false);

        $this->expectException(SettlementDecisionRefused::class);
        $this->expectExceptionMessage(self::NOT_CLOSED);

        $this->service()->closedCycle($group, 0);
    }

    public function test_a_manual_closing_that_was_reopened_is_not_closed(): void
    {
        [$group] = $this->thirdPartyScenario();
        GroupCycleSnapshot::where('group_id', $group->id)->where('cycle_start', '2026-08-01')->update(['reopened_at' => Carbon::now()->addMinute()]);

        $this->expectException(SettlementDecisionRefused::class);
        $this->expectExceptionMessage(self::NOT_CLOSED);

        $this->service()->closedCycle($group, 0);
    }

    // --- decidableCycle: o mesmo comportamento de antes --------------------

    public function test_decidable_cycle_returns_the_same_cycle_when_it_is_closed_and_not_sealed(): void
    {
        [$group] = $this->thirdPartyScenario();

        $decidable = $this->service()->decidableCycle($group, 0);
        $closed = $this->service()->closedCycle($group, 0);

        $this->assertSame($closed['start']->toDateString(), $decidable['start']->toDateString());
        $this->assertSame($closed['end']->toDateString(), $decidable['end']->toDateString());
        $this->assertSame($closed['status'], $decidable['status']);
    }

    public function test_decidable_cycle_still_refuses_an_open_cycle_with_the_same_message(): void
    {
        [$group] = $this->thirdPartyScenario(closeCycle: false);

        $this->expectException(SettlementDecisionRefused::class);
        $this->expectExceptionMessage(self::NOT_CLOSED);

        $this->service()->decidableCycle($group, 0);
    }

    public function test_decidable_cycle_still_refuses_a_sealed_cycle_with_the_same_message(): void
    {
        [$group] = $this->thirdPartyScenario(paid: true);
        $this->seal($group);

        $this->expectException(SettlementDecisionRefused::class);
        $this->expectExceptionMessage(self::SEALED);

        $this->service()->decidableCycle($group, 0);
    }

    public function test_the_not_closed_check_comes_before_the_sealed_check(): void
    {
        [$group] = $this->thirdPartyScenario(closeCycle: false);
        // Um selo num ciclo que nem fechou (não acontece de verdade): vale a mensagem do fechamento.
        GroupCycleSnapshot::updateOrCreate(
            ['group_id' => $group->id, 'cycle_start' => '2026-08-01'],
            ['cycle_end' => '2026-08-31', 'settled_at' => $this->proofSentAt(), 'totals' => [], 'expenses' => [], 'balances' => [], 'settlements' => []]
        );

        $this->expectException(SettlementDecisionRefused::class);
        $this->expectExceptionMessage(self::NOT_CLOSED);

        $this->service()->decidableCycle($group, 0);
    }
}
