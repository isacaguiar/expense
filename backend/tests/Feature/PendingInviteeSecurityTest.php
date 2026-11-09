<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsGroupInvitations;
use Tests\Concerns\BuildsPurchaseScenarios;
use Tests\TestCase;

/**
 * Feature 20261103-convite-pendente-no-grupo, TASK-575 (CA-7, RK1, R10): a matriz de
 * autorização de quem tem um convite pendente, no molde de `FormerMemberSecurityReviewTest`.
 *
 * Quem foi convidado ainda não é membro: para todas as rotas do grupo ele é um estranho, e o
 * convite é a única porta, restrita ao nome do grupo e de quem convidou. Aqui o fluxo roda de
 * ponta a ponta, por HTTP, sobre a branch completa: 404 em toda rota de leitura e de escrita
 * (com controle de que a mesma rota, para um membro, não dá 404), nada muda no banco, o convite
 * de outra pessoa não se aceita, recusa nem cancela sem permissão, e só aceitar abre o acesso.
 */
class PendingInviteeSecurityTest extends TestCase
{
    use BuildsGroupInvitations;
    use BuildsPurchaseScenarios;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function as(User $user, string $method, string $url, array $payload = []): TestResponse
    {
        return $this->withToken(auth('api')->login($user))->json($method, $url, $payload);
    }

    /**
     * Grupo de três (`$creator` criou, `$inviter` e `$bystander` são membros comuns) com uma
     * despesa; `$ana` foi convidada pela API por `$inviter` e `$bruno` por `makeInvitation`.
     *
     * @return array{group: Group, creator: User, inviter: User, bystander: User, expense: Expense, ana: User, bruno: User, anaInvitation: GroupInvitation, brunoInvitation: GroupInvitation}
     */
    private function groupWithTwoPendingInvitees(): array
    {
        ['group' => $group, 'users' => [$creator, $inviter, $bystander]] = $this->makeGroup(3);
        $group->update(['created_by' => $creator->id]);
        $expense = $this->makePurchase($group, $inviter, [$inviter, $creator, $bystander], 'IN_CASH', 90.00, '2026-10-05');

        $ana = User::factory()->create();
        $bruno = User::factory()->create();
        $this->as($inviter, 'POST', "/api/groups/{$group->id}/members", ['email' => $ana->email])->assertStatus(201);
        $anaInvitation = GroupInvitation::where('group_id', $group->id)->where('user_id', $ana->id)->firstOrFail();
        $brunoInvitation = $this->makeInvitation($group, $bruno, GroupInvitation::STATUS_PENDING, ['invited_by' => $inviter->id]);

        return compact('group', 'creator', 'inviter', 'bystander', 'expense', 'ana', 'bruno', 'anaInvitation', 'brunoInvitation');
    }

    /** @return list<string> as leituras que um membro faz sobre o grupo */
    private function groupReads(Group $group, Expense $expense): array
    {
        $g = $group->id;

        return [
            "/api/expenses/{$expense->id}",
            "/api/groups/{$g}",
            "/api/groups/{$g}/members",
            "/api/groups/{$g}/invitations",
            "/api/groups/{$g}/expenses?year=2026&month=10",
            "/api/groups/{$g}/expenses/summary",
            "/api/groups/{$g}/expenses/closing-report?cycle_start=2026-10-01",
            "/api/groups/{$g}/expenses/gross-debts",
            "/api/groups/{$g}/expenses/cycles",
            "/api/groups/{$g}/expenses/pending-cycles",
            "/api/groups/{$g}/expenses/focus-cycle",
            "/api/groups/{$g}/expenses/monthly",
            "/api/groups/{$g}/expenses/report/2026",
            "/api/group/{$g}/report-monthly/2026",
        ];
    }

    /**
     * As escritas, as que apagam por último. Os payloads são válidos de propósito: sem a
     * autorização a requisição passaria e mudaria o banco.
     *
     * @param  array<string, mixed>  $s  o cenário de `groupWithTwoPendingInvitees`
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function groupWrites(array $s): array
    {
        $g = $s['group']->id;
        $e = $s['expense']->id;
        $ana = $s['ana'];

        return [
            ['POST', '/api/expenses', [
                'date_payment' => '2026-10-05', 'description' => 'invadida', 'expense_type' => 'IN_CASH',
                'installments' => 1, 'total_value' => 10, 'group_id' => $g,
                'user_creator_id' => $ana->id, 'user_payer_id' => $s['inviter']->id, 'payers' => [$s['inviter']->id],
                'quotas' => [['date_expected' => '2026-10-05', 'number' => 1, 'value_quota' => 10]],
            ]],
            ['PUT', "/api/expenses/{$e}", ['description' => 'x']],
            ['POST', "/api/expenses/{$e}/pay", []],
            ['POST', "/api/expenses/{$e}/unpay", []],
            ['POST', "/api/expenses/{$e}/undo-purchase", []],
            ['POST', "/api/expenses/{$e}/payment-proof", []],
            ['POST', "/api/expenses/{$e}/stop-recurrence", ['year' => 2026, 'month' => 10]],
            ['PUT', "/api/groups/{$g}", ['name' => 'invadido']],
            ['POST', "/api/groups/{$g}/members", ['email' => $s['bystander']->email]],
            ['POST', "/api/groups/{$g}/expenses/close", []],
            ['POST', "/api/groups/{$g}/expenses/reopen", []],
            ['POST', "/api/groups/{$g}/settlements/confirm", ['to_user_id' => $s['inviter']->id]],
            ['DELETE', "/api/groups/{$g}/invitations/{$s['brunoInvitation']->id}", []],
            ['DELETE', "/api/groups/{$g}/invitations/{$s['anaInvitation']->id}", []],
            ['DELETE', "/api/groups/{$g}/members/{$s['bystander']->id}", []],
            ['DELETE', "/api/groups/{$g}/members/{$ana->id}", []],
            ['DELETE', "/api/expenses/{$e}", []],
            ['DELETE', "/api/groups/{$g}", []],
        ];
    }

    /** O que um acesso indevido não pode mexer: grupos, despesas, parcelas, vínculos, convites e avisos. */
    private function snapshot(): array
    {
        $state = [];
        foreach ([
            'ex_groups', 'ex_expenses', 'ex_quotas', 'ex_expenses_payers', 'ex_groups_members',
            'ex_group_invitations', 'ex_notifications', 'ex_group_cycle_snapshots', 'ex_settlement_confirmations',
        ] as $table) {
            $state[$table] = DB::table($table)->get()->map(fn ($row) => json_encode($row))->sort()->values()->all();
        }

        return $state;
    }

    /** Falha se a rota não existe: sem isso um 404 de "rota inexistente" passaria por autorização. */
    private function assertRouteExists(string $method, string $url): void
    {
        app('router')->getRoutes()->match(Request::create($url, $method));
        $this->addToAssertionCount(1);
    }

    // --- CA-7: 404 em toda rota do grupo --------------------------------

    public function test_a_pending_invitee_gets_404_on_every_route_of_the_group_and_nothing_changes(): void
    {
        $s = $this->groupWithTwoPendingInvitees();
        $reads = $this->groupReads($s['group'], $s['expense']);

        // Controle: para um membro o caminho e os ids são válidos, então o 404 de baixo é da autorização.
        foreach ($reads as $url) {
            $this->assertNotSame(404, $this->as($s['bystander'], 'GET', $url)->status(), "controle: GET {$url}");
        }

        $before = $this->snapshot();
        foreach ($reads as $url) {
            $this->assertRouteExists('GET', $url);
            $this->assertSame(404, $this->as($s['ana'], 'GET', $url)->status(), "GET {$url}");
        }
        foreach ($this->groupWrites($s) as [$method, $url, $payload]) {
            $this->assertRouteExists($method, $url);
            $this->assertSame(404, $this->as($s['ana'], $method, $url, $payload)->status(), "{$method} {$url}");
        }

        $this->assertEquals($before, $this->snapshot(), 'o convidado pendente mexeu em alguma coisa');
        $this->assertTrue($s['group']->fresh()->members()->where('user_id', $s['bystander']->id)->exists());
        $this->assertFalse($s['group']->members()->where('user_id', $s['ana']->id)->exists());
    }

    public function test_the_group_does_not_appear_in_the_lists_of_a_pending_invitee(): void
    {
        $s = $this->groupWithTwoPendingInvitees();

        $this->assertSame([], $this->as($s['ana'], 'GET', '/api/groups')->assertOk()->json());
        $this->assertSame([], $this->as($s['ana'], 'GET', '/api/me/overview')->assertOk()->json('groups'));
        // Controle: o membro vê o grupo nas mesmas listas.
        $this->assertSame(
            [$s['group']->id],
            collect($this->as($s['bystander'], 'GET', '/api/groups')->assertOk()->json())->pluck('id')->all()
        );
    }

    // --- R10: a única porta é o convite, e ela é estreita ----------------

    public function test_the_invitation_shows_only_the_group_name_and_who_invited(): void
    {
        $s = $this->groupWithTwoPendingInvitees();

        $response = $this->as($s['ana'], 'GET', '/api/me/invitations')->assertOk();

        $item = $response->json(0);
        $this->assertSame(['id', 'group', 'invited_by', 'expires_at'], array_keys($item));
        $this->assertSame(['id', 'name'], array_keys($item['group']));
        $this->assertSame(['id', 'name'], array_keys($item['invited_by']));
        $this->assertCount(1, $response->json());
        $body = $response->getContent();
        foreach ([$s['creator'], $s['inviter'], $s['bystander']] as $member) {
            $this->assertStringNotContainsString($member->email, $body);
        }
        foreach ([$s['creator']->name, $s['bystander']->name, 'Compra de teste', '"members"', '"email"', '"expenses"'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_the_notification_of_the_invitation_cannot_lead_to_the_group(): void
    {
        $s = $this->groupWithTwoPendingInvitees();

        $rows = $this->as($s['ana'], 'GET', '/api/notifications')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('group_invitation', $rows[0]['type']);
        // Sem group_id e sem groupId: o sino só navega quando há grupo, e para ela seria um 404 (R6).
        $this->assertNull($rows[0]['group_id']);
        $this->assertArrayNotHasKey('groupId', $rows[0]['data']);
    }

    // --- RK1: o convite de outra pessoa ----------------------------------

    public function test_someone_elses_invitation_cannot_be_accepted_declined_or_cancelled_without_permission(): void
    {
        config(['app.debug' => false]);
        $s = $this->groupWithTwoPendingInvitees();
        $g = $s['group']->id;
        $bruno = $s['brunoInvitation']->id;
        $ana = $s['anaInvitation']->id;
        $before = $this->snapshot();

        // Quem tem outro convite, e até quem convidou, não responde pelo convidado.
        $this->as($s['ana'], 'POST', "/api/me/invitations/{$bruno}/accept")->assertStatus(404);
        $this->as($s['ana'], 'POST', "/api/me/invitations/{$bruno}/decline")->assertStatus(404);
        $this->as($s['inviter'], 'POST', "/api/me/invitations/{$ana}/accept")->assertStatus(404);
        $this->as($s['inviter'], 'POST', "/api/me/invitations/{$ana}/decline")->assertStatus(404);
        $this->as($s['bystander'], 'POST', "/api/me/invitations/{$bruno}/accept")->assertStatus(404);
        // Cancelar: o convidado é um estranho (404) e o membro que não convidou não tem permissão (403).
        $this->as($s['ana'], 'DELETE', "/api/groups/{$g}/invitations/{$bruno}")->assertStatus(404);
        $this->as($s['bystander'], 'DELETE', "/api/groups/{$g}/invitations/{$bruno}")->assertStatus(403);
        $this->as($s['bystander'], 'DELETE', "/api/groups/{$g}/invitations/{$ana}")->assertStatus(403);

        $this->assertEquals($before, $this->snapshot(), 'alguém mexeu no convite de outra pessoa');
    }

    // --- Só aceitar abre o acesso -----------------------------------------

    public function test_accepting_is_what_opens_the_access(): void
    {
        $s = $this->groupWithTwoPendingInvitees();
        $reads = $this->groupReads($s['group'], $s['expense']);
        foreach ($reads as $url) {
            $this->assertSame(404, $this->as($s['ana'], 'GET', $url)->status(), "antes de aceitar: GET {$url}");
        }

        $this->as($s['ana'], 'POST', "/api/me/invitations/{$s['anaInvitation']->id}/accept")->assertOk();

        foreach ($reads as $url) {
            $this->assertNotSame(404, $this->as($s['ana'], 'GET', $url)->status(), "depois de aceitar: GET {$url}");
        }
        $this->assertSame(
            [$s['group']->id],
            collect($this->as($s['ana'], 'GET', '/api/groups')->assertOk()->json())->pluck('id')->all()
        );
        // O convite de Bruno segue só de Bruno.
        $this->assertSame(GroupInvitation::STATUS_PENDING, $s['brunoInvitation']->fresh()->status);
        $this->assertFalse($s['group']->members()->where('user_id', $s['bruno']->id)->exists());
    }

    /** @return array<string, array{0: string}> */
    public static function waysTheInvitationEnds(): array
    {
        return [
            'recusado' => ['declined'],
            'cancelado' => ['cancelled'],
            'vencido' => ['expired'],
        ];
    }

    /**
     * Recusar, ser cancelado ou vencer não deixa acesso nenhum: segue um estranho, e o convite
     * já não se aceita nem se recusa.
     *
     * @dataProvider waysTheInvitationEnds
     */
    public function test_a_declined_cancelled_or_expired_invitation_leaves_the_invitee_a_stranger(string $how): void
    {
        $s = $this->groupWithTwoPendingInvitees();
        $id = $s['anaInvitation']->id;

        match ($how) {
            'declined' => $this->as($s['ana'], 'POST', "/api/me/invitations/{$id}/decline")->assertOk(),
            'cancelled' => $this->as($s['inviter'], 'DELETE', "/api/groups/{$s['group']->id}/invitations/{$id}")->assertOk(),
            'expired' => Carbon::setTestNow(now()->addDays(GroupInvitation::VALIDITY_DAYS + 1)),
        };

        foreach ($this->groupReads($s['group'], $s['expense']) as $url) {
            $this->assertSame(404, $this->as($s['ana'], 'GET', $url)->status(), "GET {$url}");
        }
        $this->as($s['ana'], 'POST', "/api/me/invitations/{$id}/accept")->assertStatus(404);
        $this->as($s['ana'], 'POST', "/api/me/invitations/{$id}/decline")->assertStatus(404);
        $this->as($s['ana'], 'GET', '/api/me/invitations')->assertOk()->assertExactJson([]);
        $this->assertFalse($s['group']->members()->where('user_id', $s['ana']->id)->exists());
    }
}
