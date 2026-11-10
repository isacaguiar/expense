<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMemberResponsible;
use App\Models\SettlementConfirmation;
use App\Models\User;
use App\Services\MemberResponsibleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\TestCase;

/**
 * Matriz de autorização do `from_user_id` em `POST /groups/{id}/settlements/confirm` (feature
 * 20261109-responsavel-do-devedor, specify R6 e R16, RK1 e F4): quem tenta pagar por outro membro sem
 * o vínculo exato (este grupo, esse devedor, quem chama) recebe 403, e **em todo caso de recusa nada é
 * gravado**: nem linha em `ex_settlement_confirmations`, nem arquivo.
 *
 * Cada caso fecha com um controle positivo (a mesma chamada passa quando o vínculo existe), para o teste
 * não passar só porque o cenário estava quebrado.
 */
class ResponsibleAuthorizationMatrixTest extends TestCase
{
    use BuildsSettlementScenarios;
    use DatabaseTransactions;

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
     * Grupo com o credor, o devedor e `$caller`, que **não** tem vínculo com o devedor, e o acerto do
     * devedor ao credor (R$ 100,00) com a competência de agosto/2026 fechada.
     *
     * @return array{0: Group, 1: User, 2: User, 3: User} grupo, credor, devedor, quem tenta pagar por ele
     */
    private function scenario(): array
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-08-19');

        [$creditor, $debtor, $caller] = User::factory()->count(3)->create()->all();
        $group = Group::create(['name' => 'Casa']);
        $group->members()->attach([$creditor->id, $debtor->id, $caller->id]);
        $this->makeSettlement($group, $creditor, $debtor, 200);

        return [$group, $creditor, $debtor, $caller];
    }

    private function link(Group $group, User $debtor, User $responsible): void
    {
        GroupMemberResponsible::create([
            'group_id' => $group->id,
            'user_id' => $debtor->id,
            'responsible_user_id' => $responsible->id,
        ]);
    }

    private function confirm(User $caller, Group $group, User $creditor, int|string $fromUserId): TestResponse
    {
        // `Accept: application/json`, como o axios do front: sem ele, o erro de validação vira redirect (302).
        return $this->withToken($this->tokenFor($caller))->withHeaders(['Accept' => 'application/json'])->post("/api/groups/{$group->id}/settlements/confirm", [
            'to_user_id' => $creditor->id,
            'from_user_id' => $fromUserId,
            'comprovante' => UploadedFile::fake()->image('comprovante.jpg'),
        ]);
    }

    /** Uma recusa não pode ter gravado nada: nem linha, nem arquivo em disco nenhum. */
    private function assertNothingWritten(): void
    {
        $this->assertSame(0, SettlementConfirmation::count(), 'nenhuma linha em ex_settlement_confirmations');
        $this->assertSame([], Storage::disk('local')->allFiles(), 'nenhum arquivo gravado');
    }

    public function test_without_any_link_returns_403(): void
    {
        [$group, $creditor, $debtor, $caller] = $this->scenario();

        $this->confirm($caller, $group, $creditor, $debtor->id)->assertForbidden();

        $this->assertNothingWritten();
        $this->link($group, $debtor, $caller); // controle
        $this->confirm($caller, $group, $creditor, $debtor->id)->assertOk();
    }

    public function test_link_in_another_group_returns_403(): void
    {
        [$group, $creditor, $debtor, $caller] = $this->scenario();
        $otherGroup = Group::create(['name' => 'Outra casa']);
        $otherGroup->members()->attach([$creditor->id, $debtor->id, $caller->id]);
        $this->link($otherGroup, $debtor, $caller); // o vínculo existe, mas é de outro grupo

        $this->confirm($caller, $group, $creditor, $debtor->id)->assertForbidden();

        $this->assertNothingWritten();
        $this->link($group, $debtor, $caller); // controle
        $this->confirm($caller, $group, $creditor, $debtor->id)->assertOk();
    }

    public function test_removed_link_returns_403(): void
    {
        [$group, $creditor, $debtor, $caller] = $this->scenario();
        $this->link($group, $debtor, $caller);
        $service = app(MemberResponsibleService::class);
        $this->assertTrue($service->canPayFor($group->id, $caller->id, $debtor->id), 'o vínculo existia');
        $service->replace($group, $debtor->id, []);

        $this->confirm($caller, $group, $creditor, $debtor->id)->assertForbidden();

        $this->assertNothingWritten();
        $this->link($group, $debtor, $caller); // controle
        $this->confirm($caller, $group, $creditor, $debtor->id)->assertOk();
    }

    /** Foco de revisão 2: o responsável de A manda o `from_user_id` de B, que também deve ao credor. */
    public function test_link_for_another_debtor_returns_403(): void
    {
        [$group, $creditor, $debtor, $caller] = $this->scenario();
        $otherDebtor = User::factory()->create();
        $group->members()->attach($otherDebtor->id);
        $this->makeSettlement($group, $creditor, $otherDebtor, 100, false); // B também tem acerto a pagar
        $this->link($group, $debtor, $caller); // $caller é responsável de A, não de B

        $this->confirm($caller, $group, $creditor, $otherDebtor->id)->assertForbidden();

        $this->assertNothingWritten();
        // Controle: por A, de quem é responsável, passa, e a linha fica em nome de A, não de B.
        $this->confirm($caller, $group, $creditor, $debtor->id)->assertOk();
        $this->assertSame($debtor->id, (int) SettlementConfirmation::firstOrFail()->from_user_id);
    }

    public function test_unknown_from_user_id_returns_422(): void
    {
        [$group, $creditor, $debtor, $caller] = $this->scenario();

        foreach ([999999999, 'abc', '0'] as $fromUserId) {
            $this->confirm($caller, $group, $creditor, $fromUserId)
                ->assertStatus(422)
                ->assertJsonValidationErrors('from_user_id');
        }

        $this->assertNothingWritten();
        $this->link($group, $debtor, $caller); // controle
        $this->confirm($caller, $group, $creditor, $debtor->id)->assertOk();
    }

    public function test_non_member_caller_gets_404(): void
    {
        [$group, $creditor, $debtor, $caller] = $this->scenario();
        $outsider = User::factory()->create();
        $this->link($group, $debtor, $caller);

        $this->confirm($outsider, $group, $creditor, $debtor->id)->assertNotFound();
        $this->confirm($outsider, $group, $creditor, $outsider->id)->assertNotFound();

        $this->assertNothingWritten();
        $this->confirm($caller, $group, $creditor, $debtor->id)->assertOk(); // controle
    }

    /** R16: quem saiu do grupo perde os vínculos (R12), então ninguém mais paga por ele. */
    public function test_former_member_as_debtor_cannot_be_paid_for(): void
    {
        [$group, $creditor, $debtor, $caller] = $this->scenario();
        $this->link($group, $debtor, $caller);
        $this->withToken($this->tokenFor($debtor))
            ->deleteJson("/api/groups/{$group->id}/members/{$debtor->id}")
            ->assertOk();
        $this->assertFalse($group->members()->where('user_id', $debtor->id)->exists(), 'o devedor saiu');
        $this->assertSame(0, GroupMemberResponsible::count(), 'a saída apagou o vínculo');

        $this->confirm($caller, $group, $creditor, $debtor->id)->assertForbidden();

        $this->assertNothingWritten();
    }
}
