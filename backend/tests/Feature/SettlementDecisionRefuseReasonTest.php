<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\Concerns\BuildsThirdPartyProofScenarios;
use Tests\TestCase;

/**
 * Motivo opcional da recusa (feature 20261114-historico-de-comprovantes-e-justificativa, plan §3, TASK-620,
 * D4-A): `POST .../settlements/refuse` aceita `reason` (até 500) e o grava em `refusal_reason`, na linha
 * atual; o sino segue sem o motivo e a resposta não muda.
 */
class SettlementDecisionRefuseReasonTest extends TestCase
{
    use BuildsSettlementScenarios;
    use BuildsThirdPartyProofScenarios;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $extra */
    private function refuse(User $caller, Group $group, User $debtor, User $creditor, array $extra = []): TestResponse
    {
        return $this->withToken($this->tokenFor($caller))
            ->postJson("/api/groups/{$group->id}/settlements/refuse", $this->pairBody($debtor, $creditor, $extra));
    }

    private function storedReason(int $proofId): ?string
    {
        return DB::table('ex_settlement_confirmations')->where('id', $proofId)->value('refusal_reason');
    }

    public function test_the_reason_is_saved_with_the_refusal(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario();

        $this->refuse($creditor, $group, $debtor, $creditor, ['reason' => 'a foto está cortada'])->assertOk();

        $this->assertSame('a foto está cortada', $this->storedReason($proof->id));
        $this->assertNotNull($proof->fresh()->refused_at);
    }

    public function test_without_a_reason_the_refusal_works_as_before(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario();

        $this->refuse($creditor, $group, $debtor, $creditor)->assertOk();

        $this->assertNull($this->storedReason($proof->id));
        $this->assertNotNull($proof->fresh()->refused_at);
    }

    /** @return array<string, array{0: mixed}> */
    public static function emptyReasons(): array
    {
        return [
            'only spaces' => ['    '],
            'empty string' => [''],
            'explicit null' => [null],
        ];
    }

    /** @dataProvider emptyReasons */
    public function test_an_empty_reason_is_stored_as_null(mixed $reason): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario();

        $this->refuse($creditor, $group, $debtor, $creditor, ['reason' => $reason])->assertOk();

        $this->assertNull($this->storedReason($proof->id));
    }

    public function test_the_reason_is_trimmed(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario();

        $this->refuse($creditor, $group, $debtor, $creditor, ['reason' => "  não é deste acerto \n"])->assertOk();

        $this->assertSame('não é deste acerto', $this->storedReason($proof->id));
    }

    public function test_five_hundred_characters_are_accepted(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario();
        $reason = str_repeat('á', 500);

        $this->refuse($creditor, $group, $debtor, $creditor, ['reason' => $reason])->assertOk();

        $this->assertSame($reason, $this->storedReason($proof->id));
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidReasons(): array
    {
        return [
            'more than 500 characters' => [str_repeat('a', 501)],
            'an array' => [['a', 'b']],
        ];
    }

    /** @dataProvider invalidReasons */
    public function test_an_invalid_reason_is_a_422_and_nothing_changes(mixed $reason): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario();
        $before = $proof->fresh()->getAttributes();

        $this->refuse($creditor, $group, $debtor, $creditor, ['reason' => $reason])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame($before, $proof->fresh()->getAttributes());
        $this->assertSame(0, Notification::where('type', 'settlement_refused')->count());
    }

    public function test_the_response_is_the_same_with_or_without_a_reason(): void
    {
        [$group, $creditor, $debtor] = $this->thirdPartyScenario();

        $this->refuse($creditor, $group, $debtor, $creditor, ['reason' => 'motivo qualquer'])
            ->assertOk()
            ->assertExactJson(['message' => 'Comprovante recusado.', 'confirmationStatus' => 'refused']);
    }

    public function test_the_bell_notice_does_not_carry_the_reason(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->thirdPartyScenario();

        $this->refuse($creditor, $group, $debtor, $creditor, ['reason' => 'texto privado da recusa'])->assertOk();

        $notices = Notification::where('type', 'settlement_refused')->get();
        $this->assertEqualsCanonicalizing([$responsible->id, $debtor->id], $notices->pluck('user_id')->all());
        foreach ($notices as $notice) {
            $this->assertEqualsCanonicalizing(
                ['actorName', 'debtorName', 'groupId', 'groupName', 'amount', 'cycleLabel'],
                array_keys($notice->data),
                'o aviso segue com os mesmos dados de antes (D4-A)'
            );
            $this->assertStringNotContainsString('texto privado', json_encode($notice->data));
        }
    }

    public function test_a_blocked_refusal_does_not_save_the_reason(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->thirdPartyScenario();
        $this->refuse($creditor, $group, $debtor, $creditor, ['reason' => 'o primeiro motivo'])->assertOk();

        $this->refuse($creditor, $group, $debtor, $creditor, ['reason' => 'outro motivo'])
            ->assertStatus(422)
            ->assertJson(['error' => 'Este comprovante já foi recusado.']);

        $this->assertSame('o primeiro motivo', $this->storedReason($proof->id));
    }

    public function test_only_the_creditor_refuses_and_a_403_leaves_no_reason(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof] = $this->thirdPartyScenario();

        foreach ([$debtor, $responsible] as $caller) {
            $this->refuse($caller, $group, $debtor, $creditor, ['reason' => 'não pode'])->assertForbidden();
        }

        $this->assertNull($this->storedReason($proof->id));
        $this->assertNull($proof->fresh()->refused_at);
    }
}
