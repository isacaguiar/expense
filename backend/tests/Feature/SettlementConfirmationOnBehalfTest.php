<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Pagamento do acerto por um responsável (feature 20261109-responsavel-do-devedor): o acerto
 * continua do devedor (`from_user_id`), e `confirmed_by` registra quem enviou o comprovante,
 * nulo quando foi o próprio devedor (specify R7, R8, R10).
 */
class SettlementConfirmationOnBehalfTest extends TestCase
{
    use DatabaseTransactions;

    /** Uma confirmação direto pelo model, sem passar pela rota. */
    private function makeConfirmation(Group $group, User $debtor, User $creditor, array $overrides = []): SettlementConfirmation
    {
        return SettlementConfirmation::create(array_merge([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'amount' => 100,
            'proof_path' => 'comprovantes/teste.jpg',
            'confirmed_at' => now(),
        ], $overrides));
    }

    // --- TASK-586: a coluna e a relação ----------------------------------

    public function test_confirmed_by_defaults_to_null_and_becomes_null_when_the_user_is_deleted(): void
    {
        [$debtor, $creditor, $sender] = User::factory()->count(3)->create()->all();
        $group = Group::create(['name' => 'Casa']);

        $byDebtor = $this->makeConfirmation($group, $debtor, $creditor);
        $this->assertNull($byDebtor->fresh()->confirmed_by, 'sem `confirmed_by`, o próprio devedor enviou');

        $byResponsible = $this->makeConfirmation($group, $debtor, $creditor, [
            'cycle_start' => '2026-09-01',
            'cycle_end' => '2026-09-30',
            'confirmed_by' => $sender->id,
        ]);
        $this->assertSame($sender->id, (int) $byResponsible->fresh()->confirmed_by);

        $sender->delete();

        $afterDelete = $byResponsible->fresh();
        $this->assertNotNull($afterDelete, 'a confirmação fica quando a conta de quem enviou some');
        $this->assertNull($afterDelete->confirmed_by, 'nullOnDelete: o campo vira nulo');
        $this->assertSame($debtor->id, $afterDelete->from_user_id, 'o acerto segue sendo do devedor');
    }

    public function test_confirmed_by_user_relation_returns_the_sender(): void
    {
        [$debtor, $creditor] = User::factory()->count(2)->create()->all();
        $sender = User::factory()->create(['name' => 'Maria']);
        $group = Group::create(['name' => 'Casa']);

        $byResponsible = $this->makeConfirmation($group, $debtor, $creditor, ['confirmed_by' => $sender->id]);
        $byDebtor = $this->makeConfirmation($group, $debtor, $creditor, [
            'cycle_start' => '2026-09-01',
            'cycle_end' => '2026-09-30',
        ]);

        $this->assertTrue($byResponsible->fresh()->confirmedByUser->is($sender));
        $this->assertSame('Maria', $byResponsible->fresh()->confirmedByUser->name);
        $this->assertNull($byDebtor->fresh()->confirmedByUser);
    }
}
