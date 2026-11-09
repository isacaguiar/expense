<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMemberResponsible;
use App\Models\Notification;
use App\Models\SettlementConfirmation;
use App\Models\User;
use App\Services\MemberResponsibleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSettlementScenarios;
use Tests\TestCase;

/**
 * Pagamento do acerto por um responsável (feature 20261109-responsavel-do-devedor): o acerto
 * continua do devedor (`from_user_id`), e `confirmed_by` registra quem enviou o comprovante,
 * nulo quando foi o próprio devedor (specify R7, R8, R10).
 */
class SettlementConfirmationOnBehalfTest extends TestCase
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
     * Grupo com o credor, o devedor e um responsável do devedor, e o acerto do devedor ao credor
     * (R$ 100,00) com a competência de agosto/2026 fechada, salvo `$closeCycle = false`.
     *
     * @return array{0: Group, 1: User, 2: User, 3: User} grupo, credor, devedor, responsável
     */
    private function scenario(bool $closeCycle = true): array
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-08-19');

        [$creditor, $debtor, $responsible] = User::factory()->count(3)->create()->all();
        $group = Group::create(['name' => 'Casa']);
        $group->members()->attach([$creditor->id, $debtor->id, $responsible->id]);
        $this->makeSettlement($group, $creditor, $debtor, 200, $closeCycle);
        GroupMemberResponsible::create([
            'group_id' => $group->id,
            'user_id' => $debtor->id,
            'responsible_user_id' => $responsible->id,
        ]);

        return [$group, $creditor, $debtor, $responsible];
    }

    /** `$caller` envia o comprovante do acerto ao credor; `$fromUserId` só vai quando é por outra pessoa. */
    private function confirm(User $caller, Group $group, User $creditor, ?int $fromUserId = null): TestResponse
    {
        $body = ['to_user_id' => $creditor->id, 'comprovante' => UploadedFile::fake()->image('comprovante.jpg')];

        if ($fromUserId !== null) {
            $body['from_user_id'] = $fromUserId;
        }

        return $this->withToken($this->tokenFor($caller))->post("/api/groups/{$group->id}/settlements/confirm", $body);
    }

    /** @return list<string> */
    private function proofFiles(Group $group): array
    {
        return Storage::disk('local')->allFiles("comprovantes/{$group->id}");
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

    // --- TASK-587: o responsável envia o comprovante do devedor -----------

    public function test_responsible_confirms_the_debtors_settlement(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->scenario();

        $this->confirm($responsible, $group, $creditor, $debtor->id)
            ->assertOk()
            ->assertJsonPath('amount', 100)
            ->assertJsonPath('confirmed_by', $responsible->id);

        $row = SettlementConfirmation::where('group_id', $group->id)->firstOrFail();
        $this->assertSame($debtor->id, (int) $row->from_user_id, 'o acerto continua do devedor');
        $this->assertSame($creditor->id, (int) $row->to_user_id);
        $this->assertSame($responsible->id, (int) $row->confirmed_by);
        Storage::disk('local')->assertExists($row->proof_path);
        $this->assertSame(0, SettlementConfirmation::where('from_user_id', $responsible->id)->count(), 'nada fica em nome do responsável');
    }

    public function test_debtor_confirmation_keeps_confirmed_by_null(): void
    {
        [$group, $creditor, $debtor] = $this->scenario();

        $this->confirm($debtor, $group, $creditor)->assertOk()->assertJsonPath('confirmed_by', null);

        $this->assertNull(SettlementConfirmation::where('group_id', $group->id)->firstOrFail()->confirmed_by);
    }

    public function test_explicit_from_user_id_equal_to_the_caller_behaves_as_today(): void
    {
        [$group, $creditor, $debtor] = $this->scenario();

        $this->confirm($debtor, $group, $creditor, $debtor->id)->assertOk();

        $row = SettlementConfirmation::where('group_id', $group->id)->firstOrFail();
        $this->assertSame($debtor->id, (int) $row->from_user_id);
        $this->assertNull($row->confirmed_by, 'quem se confirma não precisa de vínculo nem deixa `confirmed_by`');
    }

    public function test_resend_by_the_debtor_after_the_responsible_resets_confirmed_by_and_leaves_one_file(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->scenario();
        $this->confirm($responsible, $group, $creditor, $debtor->id)->assertOk();
        $row = SettlementConfirmation::where('group_id', $group->id)->firstOrFail();
        $firstPath = $row->proof_path;
        $this->assertSame($responsible->id, (int) $row->confirmed_by);

        $this->confirm($debtor, $group, $creditor)->assertOk();

        $row = $row->fresh();
        $this->assertNull($row->confirmed_by, 'o último a enviar vence: agora foi o devedor');
        $this->assertNotSame($firstPath, $row->proof_path);
        Storage::disk('local')->assertMissing($firstPath);
        $this->assertCount(1, $this->proofFiles($group), 'sobra um arquivo só');
        $this->assertSame(1, SettlementConfirmation::where('group_id', $group->id)->count());
    }

    public function test_resend_by_the_responsible_after_the_debtor_replaces_the_proof_and_sets_confirmed_by(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->scenario();
        $this->confirm($debtor, $group, $creditor)->assertOk();
        $row = SettlementConfirmation::where('group_id', $group->id)->firstOrFail();
        $firstPath = $row->proof_path;
        $this->assertNull($row->confirmed_by);

        $this->confirm($responsible, $group, $creditor, $debtor->id)->assertOk();

        $row = $row->fresh();
        $this->assertSame($responsible->id, (int) $row->confirmed_by);
        $this->assertNotSame($firstPath, $row->proof_path);
        Storage::disk('local')->assertMissing($firstPath);
        $this->assertCount(1, $this->proofFiles($group));
        $this->assertSame(1, SettlementConfirmation::where('group_id', $group->id)->count());
    }

    public function test_responsible_removed_from_the_list_loses_the_right_at_once_and_a_new_one_passes(): void
    {
        [$group, $creditor, $debtor, $old] = $this->scenario();
        $newcomer = User::factory()->create();
        $group->members()->attach($newcomer->id);
        app(MemberResponsibleService::class)->replace($group, $debtor->id, [$newcomer->id]);

        $this->confirm($old, $group, $creditor, $debtor->id)->assertForbidden();
        $this->assertSame(0, SettlementConfirmation::count(), 'o 403 não grava nada');
        $this->assertSame([], $this->proofFiles($group), 'nem arquivo');

        $this->confirm($newcomer, $group, $creditor, $debtor->id)->assertOk();

        $this->assertSame($newcomer->id, (int) SettlementConfirmation::where('group_id', $group->id)->firstOrFail()->confirmed_by);
    }

    public function test_two_responsibles_can_each_confirm_for_the_same_debtor(): void
    {
        [$group, $creditor, $debtor, $first] = $this->scenario();
        $second = User::factory()->create();
        $group->members()->attach($second->id);
        app(MemberResponsibleService::class)->replace($group, $debtor->id, [$first->id, $second->id]);

        $this->confirm($first, $group, $creditor, $debtor->id)->assertOk();
        $this->confirm($second, $group, $creditor, $debtor->id)->assertOk();

        $this->assertSame(1, SettlementConfirmation::where('group_id', $group->id)->count());
        $this->assertSame($second->id, (int) SettlementConfirmation::where('group_id', $group->id)->firstOrFail()->confirmed_by, 'o último vence');
        $this->assertCount(1, $this->proofFiles($group));
    }

    public function test_confirmation_keeps_confirmed_by_after_the_link_is_removed(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->scenario();
        $this->confirm($responsible, $group, $creditor, $debtor->id)->assertOk();

        app(MemberResponsibleService::class)->replace($group, $debtor->id, []);

        $this->assertSame(0, GroupMemberResponsible::where('group_id', $group->id)->count());
        $row = SettlementConfirmation::where('group_id', $group->id)->firstOrFail();
        $this->assertSame($responsible->id, (int) $row->confirmed_by, 'o histórico de quem enviou fica');
    }

    public function test_responsible_who_is_also_the_creditor_is_accepted(): void
    {
        [$group, $creditor, $debtor] = $this->scenario();
        app(MemberResponsibleService::class)->replace($group, $debtor->id, [$creditor->id]);

        $this->confirm($creditor, $group, $creditor, $debtor->id)->assertOk();

        $row = SettlementConfirmation::where('group_id', $group->id)->firstOrFail();
        $this->assertSame($debtor->id, (int) $row->from_user_id);
        $this->assertSame($creditor->id, (int) $row->to_user_id);
        $this->assertSame($creditor->id, (int) $row->confirmed_by);
    }

    public function test_responsible_confirm_requires_a_closed_cycle(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->scenario(closeCycle: false);

        $this->confirm($responsible, $group, $creditor, $debtor->id)
            ->assertStatus(422)
            ->assertJsonPath('error', 'O acerto só pode ser confirmado depois que a competência é fechada.');
        $this->assertSame(0, SettlementConfirmation::count());
        $this->assertSame([], $this->proofFiles($group));

        // Controle: com a competência fechada, a mesma chamada passa.
        $this->closeAugustCycle($group);
        $this->confirm($responsible, $group, $creditor, $debtor->id)->assertOk();
    }

    // --- R10, R11: avisos ao credor ---------------------------------------

    public function test_creditor_is_notified_with_paid_by_name_and_the_debtor_as_actor(): void
    {
        [$group, $creditor, $debtor, $responsible] = $this->scenario();

        $this->confirm($responsible, $group, $creditor, $debtor->id)->assertOk();

        $rows = Notification::where('type', 'settlement_confirmed')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($creditor->id, $rows->first()->user_id);
        $data = $rows->first()->data;
        $this->assertSame($debtor->name, $data['actorName'], 'o acerto é do devedor');
        $this->assertSame($responsible->name, $data['paidByName']);
        $this->assertSame('100.00', $data['amount']);
        // O MySQL reordena as chaves de uma coluna json: confere o conjunto, não a ordem.
        $this->assertEqualsCanonicalizing(
            ['actorName', 'paidByName', 'groupId', 'groupName', 'amount', 'cycleLabel'],
            array_keys($data)
        );
    }

    public function test_debtor_confirmation_notification_has_no_paid_by_name(): void
    {
        [$group, $creditor, $debtor] = $this->scenario();

        $this->confirm($debtor, $group, $creditor)->assertOk();

        $data = Notification::where('type', 'settlement_confirmed')->firstOrFail()->data;
        $this->assertSame($debtor->name, $data['actorName']);
        $this->assertEqualsCanonicalizing(
            ['actorName', 'groupId', 'groupName', 'amount', 'cycleLabel'],
            array_keys($data),
            'sem `paidByName` quando quem enviou foi o próprio devedor'
        );
    }

    public function test_whatsapp_to_the_creditor_still_names_the_debtor(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200)]);
        $this->enableWhatsApp();
        [$group, $creditor, $debtor, $responsible] = $this->scenario();
        $creditor->whatsapp = '(11) 90000-0001';
        $creditor->notify_whatsapp = true;
        $creditor->save();

        $this->confirm($responsible, $group, $creditor, $debtor->id)->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['template']['components'][0]['parameters'] === [
            ['type' => 'text', 'text' => $debtor->name],
            ['type' => 'text', 'text' => 'R$ 100,00'],
            ['type' => 'text', 'text' => 'ago/2026'],
        ]);
    }
}
