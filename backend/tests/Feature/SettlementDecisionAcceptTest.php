<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Notification;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\TestCase;

/**
 * `POST /groups/{id}/settlements/accept` (feature 20261110-quem-paga-se-declara-e-credor-recusa, plan §4,
 * TASK-598): o credor do par aceita o comprovante que um responsável enviou. Só vale em ciclo fechado e
 * ainda não encerrado, e o aceite pode selar o ciclo.
 */
class SettlementDecisionAcceptTest extends TestCase
{
    use BuildsSettlementScenarios;
    use DatabaseTransactions;

    private const WHEN = '2026-08-20 10:00:00';

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
     * Grupo com o criador, o credor, o devedor e um responsável do devedor; o acerto do devedor ao credor
     * (R$ 100,00) com agosto/2026 fechado (salvo `$closeCycle = false`) e o comprovante que o **responsável**
     * enviou, ainda sem decisão. `$paid` marca a parcela como paga (sem isso o ciclo nunca sela).
     *
     * @return array{0: Group, 1: User, 2: User, 3: User, 4: SettlementConfirmation, 5: User} grupo, credor, devedor, responsável, comprovante, criador
     */
    private function scenario(bool $paid = false, bool $closeCycle = true): array
    {
        Carbon::setTestNow('2026-08-19');

        [$creator, $creditor, $debtor, $responsible] = User::factory()->count(4)->create()->all();
        $group = Group::create(['name' => 'Casa', 'created_by' => $creator->id]);
        $group->members()->attach([$creator->id, $creditor->id, $debtor->id, $responsible->id]);
        $expense = $this->makeSettlement($group, $creditor, $debtor, 200, $closeCycle);
        if ($paid) {
            $expense->quotas()->update(['paid' => true]);
        }

        $proof = SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'confirmed_by' => $responsible->id,
            'amount' => 100,
            'proof_path' => 'comprovantes/'.$group->id.'/x.jpg',
            'confirmed_at' => self::WHEN,
        ]);

        return [$group, $creditor, $debtor, $responsible, $proof, $creator];
    }

    private function accept(User $caller, Group|int $group, array $body): TestResponse
    {
        $groupId = $group instanceof Group ? $group->id : $group;

        return $this->withToken($this->tokenFor($caller))->postJson("/api/groups/{$groupId}/settlements/accept", $body);
    }

    private function pairBody(User $debtor, User $creditor, array $extra = []): array
    {
        return ['from_user_id' => $debtor->id, 'to_user_id' => $creditor->id] + $extra;
    }

    private function sealedSnapshot(Group $group): ?GroupCycleSnapshot
    {
        return GroupCycleSnapshot::where('group_id', $group->id)->where('cycle_start', '2026-08-01')->whereNotNull('settled_at')->first();
    }

    // --- o caminho feliz ---------------------------------------------------

    public function test_it_accepts_and_seals_the_cycle(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->scenario(paid: true);
        $this->assertNull($this->sealedSnapshot($group), 'antes do aceite o ciclo não está selado');

        $this->accept($creditor, $group, $this->pairBody($debtor, $creditor))
            ->assertOk()
            ->assertJson(['message' => 'Comprovante aceito.', 'confirmationStatus' => 'accepted']);

        $this->assertNotNull($proof->fresh()->accepted_at);
        $this->assertNull($proof->fresh()->refused_at);
        $this->assertNotNull($this->sealedSnapshot($group), 'o aceite do último comprovante sela o ciclo');
        $this->assertSame(4, Notification::where('type', 'cycle_settled')->where('group_id', $group->id)->count(), 'um aviso de ciclo selado por membro');
    }

    public function test_it_accepts_and_does_not_seal_while_something_is_missing(): void
    {
        // A parcela segue pendente: o ciclo não pode selar, mesmo com todos os comprovantes valendo.
        [$group, $creditor, $debtor, , $proof] = $this->scenario(paid: false);

        $this->accept($creditor, $group, $this->pairBody($debtor, $creditor))->assertOk();

        $this->assertNotNull($proof->fresh()->accepted_at, 'o aceite é gravado');
        $this->assertNull($this->sealedSnapshot($group));
        $this->assertSame(0, Notification::where('type', 'cycle_settled')->where('group_id', $group->id)->count());
    }

    // --- autorização -------------------------------------------------------

    public function test_it_returns_403_when_to_user_id_is_not_the_caller_before_any_lookup(): void
    {
        [$group, $creditor, $debtor, $responsible, $proof, $creator] = $this->scenario(paid: true);

        foreach ([$debtor, $responsible, $creator] as $caller) {
            $this->accept($caller, $group, $this->pairBody($debtor, $creditor))->assertForbidden();
        }

        // Antes de qualquer consulta: um par que nem tem comprovante também dá 403, não 404.
        $this->accept($responsible, $group, $this->pairBody($creator, $creditor))->assertForbidden();
        $this->assertNull($proof->fresh()->accepted_at, 'nada foi escrito');
        $this->assertNull($this->sealedSnapshot($group));
    }

    public function test_it_returns_404_for_a_non_member_before_the_403(): void
    {
        [$group, $creditor, $debtor] = $this->scenario(paid: true);
        $outsider = User::factory()->create();

        // `to_user_id` não é quem chama, e mesmo assim é 404 (não 403): quem não é membro não vê o grupo.
        $this->accept($outsider, $group, $this->pairBody($debtor, $creditor))->assertNotFound();
    }

    public function test_it_returns_404_for_a_missing_or_deleted_group(): void
    {
        [$group, $creditor, $debtor] = $this->scenario(paid: true);

        $this->accept($creditor, 99999999, $this->pairBody($debtor, $creditor))->assertNotFound();

        $group->update(['deleted' => true]);
        $this->accept($creditor, $group, $this->pairBody($debtor, $creditor))->assertNotFound();
    }

    public function test_a_group_id_with_trailing_text_is_a_404(): void
    {
        [$group, $creditor, $debtor] = $this->scenario(paid: true);

        // O MySQL compararia "5abc" como 5 e acharia o grupo 5: só dígitos conta como id.
        $this->withToken($this->tokenFor($creditor))
            ->postJson("/api/groups/{$group->id}abc/settlements/accept", $this->pairBody($debtor, $creditor))
            ->assertNotFound();
    }

    // --- o comprovante -----------------------------------------------------

    public function test_it_returns_404_when_there_is_no_confirmation_for_the_pair(): void
    {
        [$group, $creditor, , , , $creator] = $this->scenario(paid: true);

        // O criador do grupo é membro e nunca enviou nada.
        $this->accept($creditor, $group, $this->pairBody($creator, $creditor))->assertNotFound();
    }

    public function test_it_returns_422_for_a_debtor_proof(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->scenario(paid: true);
        $proof->update(['confirmed_by' => null]);

        $this->accept($creditor, $group, $this->pairBody($debtor, $creditor))
            ->assertStatus(422)
            ->assertJson(['error' => 'Só o comprovante enviado por quem paga por outra pessoa passa pelo aceite.']);

        $this->assertNull($proof->fresh()->accepted_at);
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function statesThatAreNotAwaiting(): array
    {
        return [
            'already accepted' => [['accepted_at' => self::WHEN], 'Este comprovante já foi aceito.'],
            'refused' => [['refused_at' => self::WHEN], 'Este comprovante foi recusado: quem enviou precisa reenviar.'],
        ];
    }

    /**
     * @dataProvider statesThatAreNotAwaiting
     *
     * @param  array<string, string>  $state
     */
    public function test_it_returns_422_when_the_proof_is_not_awaiting_acceptance(array $state, string $message): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->scenario(paid: true);
        $proof->update($state);
        $before = $proof->fresh()->only(['accepted_at', 'refused_at']);

        $this->accept($creditor, $group, $this->pairBody($debtor, $creditor))->assertStatus(422)->assertJson(['error' => $message]);

        $this->assertEquals($before, $proof->fresh()->only(['accepted_at', 'refused_at']), 'nada mudou');
    }

    // --- o ciclo -----------------------------------------------------------

    public function test_it_returns_422_for_a_sealed_cycle(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->scenario(paid: true);
        GroupCycleSnapshot::where('group_id', $group->id)->where('cycle_start', '2026-08-01')->update(['settled_at' => self::WHEN]);

        $this->accept($creditor, $group, $this->pairBody($debtor, $creditor))
            ->assertStatus(422)
            ->assertJson(['error' => 'Esta competência já foi encerrada.']);

        $this->assertNull($proof->fresh()->accepted_at, 'ciclo encerrado é imutável: nada é escrito');
    }

    public function test_it_returns_422_when_the_cycle_is_not_closed_yet(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->scenario(paid: true, closeCycle: false);

        $this->accept($creditor, $group, $this->pairBody($debtor, $creditor))
            ->assertStatus(422)
            ->assertJson(['error' => 'O acerto só pode ser confirmado depois que a competência é fechada.']);

        $this->assertNull($proof->fresh()->accepted_at);
    }

    public function test_cycles_ago_picks_a_previous_closed_cycle(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->scenario(paid: true, closeCycle: false);
        // Em 20/09 agosto já passou da carência e está fechado por data; o ciclo vigente é setembro.
        Carbon::setTestNow('2026-09-20');

        $this->accept($creditor, $group, $this->pairBody($debtor, $creditor, ['cycles_ago' => 1]))->assertOk();

        $this->assertNotNull($proof->fresh()->accepted_at);
        $this->assertNotNull($this->sealedSnapshot($group), 'agosto ficou quitado e foi selado');
    }

    // --- validação ---------------------------------------------------------

    public function test_the_body_is_validated(): void
    {
        [$group, $creditor, $debtor] = $this->scenario(paid: true);

        $this->accept($creditor, $group, ['to_user_id' => $creditor->id])->assertStatus(422)->assertJsonValidationErrors('from_user_id');
        $this->accept($creditor, $group, ['from_user_id' => $debtor->id, 'to_user_id' => 99999999])->assertStatus(422)->assertJsonValidationErrors('to_user_id');
        $this->accept($creditor, $group, $this->pairBody($debtor, $creditor, ['cycles_ago' => -1]))->assertStatus(422)->assertJsonValidationErrors('cycles_ago');
    }

    // --- o aviso -----------------------------------------------------------

    public function test_it_notifies_the_sender_but_not_the_creditor_himself(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->scenario(paid: false);

        $this->accept($creditor, $group, $this->pairBody($debtor, $creditor))->assertOk();

        $notice = Notification::where('type', 'settlement_accepted')->where('user_id', $responsible->id)->first();
        $this->assertNotNull($notice, 'quem enviou é avisado');
        $this->assertSame($group->id, (int) $notice->group_id);
        $this->assertSame($creditor->name, $notice->data['actorName']);
        $this->assertSame('100.00', $notice->data['amount']);
        $this->assertSame('agosto/2026', $notice->data['cycleLabel']);
        $this->assertSame($group->id, $notice->data['groupId']);
        $this->assertSame('Casa', $notice->data['groupName']);
        $this->assertSame(1, Notification::where('type', 'settlement_accepted')->count(), 'ninguém mais é avisado');
    }

    public function test_the_creditor_who_sent_the_proof_himself_accepts_without_a_notice_to_himself(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->scenario(paid: true);
        $proof->update(['confirmed_by' => $creditor->id]);

        $this->accept($creditor, $group, $this->pairBody($debtor, $creditor))->assertOk();

        $this->assertNotNull($proof->fresh()->accepted_at);
        $this->assertNotNull($this->sealedSnapshot($group), 'senão o ciclo nunca selaria nesse caso');
        $this->assertSame(0, Notification::where('type', 'settlement_accepted')->count());
    }

    // --- consistência ------------------------------------------------------

    public function test_a_second_accept_gets_422_and_changes_nothing(): void
    {
        [$group, $creditor, $debtor, , $proof] = $this->scenario(paid: false);
        $this->accept($creditor, $group, $this->pairBody($debtor, $creditor))->assertOk();
        $acceptedAt = $proof->fresh()->accepted_at;

        $this->accept($creditor, $group, $this->pairBody($debtor, $creditor))->assertStatus(422)->assertJson(['error' => 'Este comprovante já foi aceito.']);

        $this->assertEquals($acceptedAt, $proof->fresh()->accepted_at);
        $this->assertSame(1, Notification::where('type', 'settlement_accepted')->count(), 'o segundo aceite não avisa de novo');
    }

    public function test_it_locks_the_confirmation_row_while_deciding(): void
    {
        [$group, $creditor, $debtor] = $this->scenario(paid: false);

        DB::enableQueryLog();
        $this->accept($creditor, $group, $this->pairBody($debtor, $creditor))->assertOk();
        $locked = collect(DB::getQueryLog())->pluck('query')->filter(
            fn (string $sql) => str_contains($sql, 'ex_settlement_confirmations') && str_contains(strtolower($sql), 'for update')
        );

        $this->assertGreaterThanOrEqual(1, $locked->count(), 'a linha do comprovante é lida com `for update`, para dois aceites não se cruzarem');
    }
}
