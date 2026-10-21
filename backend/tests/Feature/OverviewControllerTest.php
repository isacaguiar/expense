<?php

namespace Tests\Feature;

use App\Http\Controllers\OverviewController;
use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Notification;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * `GET /api/me/overview`: a visão pessoal por grupo, no ciclo em foco de cada
 * um. A derivação em si é coberta por PersonalOverviewServiceTest; aqui ficam o
 * contrato HTTP, a autenticação, o escopo (grupos alheios) e a ausência de
 * efeitos colaterais. Ver docs/feature/20261010-painel-financeiro-pessoal/plan.md §1.1 e §1.4.
 *
 * "Agora" é 15/10/2026 com `closing_day` nulo: setembro (1 ciclo atrás) já
 * fechou por data em 05/10 e outubro é o ciclo vigente.
 */
class OverviewControllerTest extends TestCase
{
    use DatabaseTransactions;

    private User $creditor;

    private User $debtor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15');

        $this->creditor = User::factory()->create(['name' => 'Ana Credora', 'email' => 'ana@example.test', 'pix' => 'ana-pix']);
        $this->debtor = User::factory()->create(['name' => 'Beto Devedor', 'email' => 'beto@example.test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function overviewAs(User $user)
    {
        return $this->withToken(auth('api')->login($user))->getJson('/api/me/overview');
    }

    private function summaryAs(User $user, Group $group, int $cyclesAgo)
    {
        return $this->withToken(auth('api')->login($user))
            ->getJson("/api/groups/{$group->id}/expenses/summary?cycles_ago={$cyclesAgo}");
    }

    private function group(string $name, User ...$members): Group
    {
        $group = Group::create(['name' => $name]);
        $group->members()->attach(array_map(fn (User $user) => $user->id, $members));

        return $group;
    }

    /**
     * Despesa À Vista de `$creditor`, dividida entre ele e `$others`, em setembro.
     *
     * @param  array<int, User>  $others
     */
    private function septemberExpense(Group $group, User $creditor, array $others, float $total, bool $paid = false): Expense
    {
        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-09-10',
            'description' => 'Despesa de setembro',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => $total,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);
        $expense->payers()->sync(array_merge([$creditor->id], array_map(fn (User $user) => $user->id, $others)));
        $expense->quotas()->create(['date_expected' => '2026-09-10', 'number' => 1, 'paid' => $paid, 'value_quota' => $total]);

        return $expense;
    }

    private function confirmSeptember(Group $group, User $from, User $to, float $amount): SettlementConfirmation
    {
        return SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-09-01',
            'cycle_end' => '2026-09-30',
            'from_user_id' => $from->id,
            'to_user_id' => $to->id,
            'amount' => $amount,
            'proof_path' => 'comprovantes/'.$group->id.'/x.jpg',
            'confirmed_at' => now(),
        ]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/me/overview')->assertStatus(401);
    }

    public function test_the_controller_answers_401_when_there_is_no_user(): void
    {
        // TASK-431 (achado S4): o jwt.auth já barra quem não tem token, mas o
        // controller resolve o usuário de novo; se a conta some no meio do
        // caminho, a resposta tem de ser 401, não um TypeError (500).
        auth('api')->forgetUser();

        try {
            app(OverviewController::class)->show();
            $this->fail('esperava HttpException 401');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
    }

    public function test_the_route_sits_behind_the_jwt_auth_middleware(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/me/overview' && in_array('GET', $route->methods(), true));

        $this->assertNotNull($route, 'a rota GET api/me/overview não está registrada');
        $this->assertContains('jwt.auth', $route->gatherMiddleware());
    }

    // --- throttle por usuário (TASK-429, decisão D10) --------------------------
    // O limiter global `api` (60/min) chaveia por IP (guard padrão `web`). O da
    // rota é 30/min por usuário. Atenção: o Laravel executa o ThrottleRequests
    // ANTES do jwt.auth (middlewarePriority), não importa a ordem declarada; por
    // isso a proteção contra contar chamada sem token está no próprio limiter, e
    // quem a prova é o teste de chamadas não autenticadas, não a ordem da rota.
    // Com "agora" congelado, a janela de 1 minuto não vira durante o teste.

    public function test_the_route_declares_the_per_user_throttle(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/me/overview' && in_array('GET', $route->methods(), true));

        $this->assertContains('throttle:overview', $route->gatherMiddleware());
    }

    public function test_the_31st_call_of_the_same_user_within_a_minute_gets_429(): void
    {
        foreach (range(1, 30) as $call) {
            $this->overviewAs($this->debtor)->assertOk();
        }

        $this->overviewAs($this->debtor)->assertStatus(429);
    }

    public function test_another_user_on_the_same_ip_is_not_affected(): void
    {
        foreach (range(1, 31) as $call) {
            $this->overviewAs($this->debtor);
        }

        $this->overviewAs($this->creditor)->assertOk();
    }

    public function test_unauthenticated_calls_do_not_consume_the_overview_limit(): void
    {
        // Se o throttle da rota viesse antes do jwt.auth, a 31ª chamada sem
        // token seria 429 (chave por IP); com ele depois, é sempre 401.
        foreach (range(1, 31) as $call) {
            $this->getJson('/api/me/overview')->assertStatus(401);
        }
    }

    public function test_calls_with_an_invalid_token_do_not_consume_the_overview_limit(): void
    {
        // Teste separado do anterior: juntos passariam de 60 chamadas do mesmo
        // IP e bateriam no limiter global `api`, não no da rota.
        foreach (range(1, 31) as $call) {
            $this->withToken('token-invalido')->getJson('/api/me/overview')->assertStatus(401);
        }
    }

    public function test_other_routes_are_not_bound_by_the_overview_limit(): void
    {
        foreach (range(1, 31) as $call) {
            $this->withToken(auth('api')->login($this->debtor))->getJson('/api/me')->assertOk();
        }
    }

    public function test_returns_the_documented_structure(): void
    {
        $group = $this->group('Residencial', $this->creditor, $this->debtor);
        $this->septemberExpense($group, $this->creditor, [$this->debtor], 100);

        $this->overviewAs($this->debtor)
            ->assertOk()
            ->assertJsonPath('user.id', $this->debtor->id)
            ->assertJsonPath('user.name', 'Beto Devedor')
            ->assertJsonCount(1, 'groups')
            ->assertJsonPath('groups.0.group.name', 'Residencial')
            ->assertJsonPath('groups.0.cycle.cycles_ago', 1)
            ->assertJsonPath('truncated', false)
            ->assertJsonStructure([
                'user' => ['id', 'name'],
                'truncated',
                'groups' => ['*' => [
                    'group' => ['id', 'name'],
                    'cycle' => ['start', 'end', 'closes_at', 'status', 'settled', 'in_grace', 'cycles_ago'],
                    'owe' => ['total', 'confirmed', 'open'],
                    'receive' => ['total', 'confirmed', 'open'],
                    'to_pay' => ['*' => [
                        'to_user' => ['id', 'name', 'email', 'pix', 'avatar_url'],
                        'amount', 'confirmed_at', 'confirmed_proof_url', 'can_confirm',
                    ]],
                    'to_receive',
                    'my_items' => ['*' => [
                        'expense_id', 'description', 'date', 'value', 'my_share', 'paid', 'role',
                        'creditor' => ['id', 'name'],
                        'installment', 'can_pay', 'can_unpay',
                    ]],
                    'pending_items_count',
                    'has_open',
                ]],
            ]);
    }

    public function test_a_user_without_groups_gets_an_empty_list(): void
    {
        $lonely = User::factory()->create(['name' => 'Sem grupo']);

        $this->overviewAs($lonely)
            ->assertOk()
            ->assertExactJson(['user' => ['id' => $lonely->id, 'name' => 'Sem grupo'], 'groups' => [], 'truncated' => false]);
    }

    public function test_never_shows_the_group_of_someone_else(): void
    {
        $mine = $this->group('Meu grupo', $this->creditor, $this->debtor);
        $this->septemberExpense($mine, $this->creditor, [$this->debtor], 100);

        $stranger = User::factory()->create(['name' => 'Estranho Alheio', 'email' => 'estranho@example.test']);
        $other = User::factory()->create(['name' => 'Outro Alheio', 'email' => 'outro@example.test']);
        $foreign = $this->group('Grupo alheio', $stranger, $other);
        $this->septemberExpense($foreign, $stranger, [$other], 500);

        $response = $this->overviewAs($this->debtor)->assertOk();

        $response->assertJsonCount(1, 'groups');
        $groupIds = collect($response->json('groups'))->pluck('group.id')->all();
        $this->assertSame([$mine->id], $groupIds);
        $this->assertNotContains($foreign->id, $groupIds);

        // Nada do grupo alheio vaza: nem o nome do grupo, nem os dados de quem está nele.
        $body = $response->getContent();
        $this->assertStringNotContainsString('Grupo alheio', $body);
        $this->assertStringNotContainsString('estranho@example.test', $body);
        $this->assertStringNotContainsString('outro@example.test', $body);
    }

    public function test_reading_the_overview_writes_nothing_even_where_the_summary_endpoint_would_seal(): void
    {
        // Setembro todo pago e com o único acerto confirmado, sem snapshot.
        $group = $this->group('Grupo', $this->creditor, $this->debtor);
        $this->septemberExpense($group, $this->creditor, [$this->debtor], 200, true);
        $this->confirmSeptember($group, $this->debtor, $this->creditor, 100);

        $snapshotsBefore = GroupCycleSnapshot::count();
        $notificationsBefore = Notification::count();

        $this->overviewAs($this->debtor)->assertOk();
        $this->overviewAs($this->creditor)->assertOk();

        $this->assertSame($snapshotsBefore, GroupCycleSnapshot::count());
        $this->assertSame($notificationsBefore, Notification::count());

        // Controle: o mesmo cenário lido por GET /summary sela. Sem este passo, o
        // teste acima passaria mesmo num cenário em que nada seria selado.
        $this->summaryAs($this->creditor, $group, 1)->assertOk()->assertJsonPath('cycle.settled', true);
        $this->assertSame($snapshotsBefore + 1, GroupCycleSnapshot::count());
    }

    public function test_matches_the_summary_endpoint_for_the_cycle_it_chose(): void
    {
        $third = User::factory()->create(['name' => 'Caio Devedor']);
        $group = $this->group('Grupo', $this->creditor, $this->debtor, $third);
        $this->septemberExpense($group, $this->creditor, [$this->debtor, $third], 300);
        $this->confirmSeptember($group, $this->debtor, $this->creditor, 100);

        // Como devedor.
        $item = $this->overviewAs($this->debtor)->assertOk()->json('groups.0');
        $summary = $this->summaryAs($this->debtor, $group, $item['cycle']['cycles_ago'])->assertOk()->json();

        foreach (['start', 'end', 'closes_at', 'status', 'settled'] as $key) {
            $this->assertSame($summary['cycle'][$key], $item['cycle'][$key], "cycle.{$key}");
        }

        $mine = collect($summary['settlements'])->where('from_user_id', $this->debtor->id)->values()->all();
        $this->assertCount(1, $mine);
        $this->assertCount(1, $item['to_pay']);
        $this->assertEquals($mine[0]['amount'], $item['to_pay'][0]['amount']);
        $this->assertSame($mine[0]['confirmedAt'], $item['to_pay'][0]['confirmed_at']);
        $this->assertSame($this->creditor->id, $item['to_pay'][0]['to_user']['id']);

        // Como credor: recebe dos dois, e só um está confirmado.
        $creditorItem = $this->overviewAs($this->creditor)->assertOk()->json('groups.0');
        $creditorSummary = $this->summaryAs($this->creditor, $group, $creditorItem['cycle']['cycles_ago'])->assertOk()->json();

        $incoming = collect($creditorSummary['settlements'])->where('to_user_id', $this->creditor->id)->values()->all();
        $this->assertCount(2, $incoming);
        $this->assertCount(2, $creditorItem['to_receive']);

        $normalise = fn (array $rows, string $from, string $confirmed) => collect($rows)
            ->map(fn (array $row) => [$from === 'summary' ? $row['from_user_id'] : $row['from_user']['id'], (float) $row['amount'], $row[$confirmed] !== null])
            ->sortBy(0)->values()->all();

        $this->assertEquals(
            $normalise($incoming, 'summary', 'confirmedAt'),
            $normalise($creditorItem['to_receive'], 'overview', 'confirmed_at')
        );
    }
}
