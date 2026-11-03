<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsPurchaseScenarios;
use Tests\TestCase;

/**
 * Item 086, TASK-556 (CA-11): reverificação, por teste, dos cenários da revisão do
 * `security-reviewer` sobre a saída de membro (docs/feature/concluidas/202610/20261030-saida-e-remocao-de-membro).
 *
 * O revisor montou um grupo, fez dois membros saírem e olhou o que sobrava para quem ficou.
 * Os achados A1 (contato do ex-membro em `show`, `update` e `gross-debts`), A2 (e-mail do
 * criador que saiu em `GET /groups`), A3 (dono promovendo um ex-membro a credor) e B1 (foto)
 * foram corrigidos nas TASK-562 a 565, cada uma com seus testes por campo. Aqui o fluxo dele
 * roda de ponta a ponta, por HTTP, sobre a branch completa: nenhuma resposta de leitura leva
 * e-mail, Pix, WhatsApp ou foto de quem saiu, e quem saiu leva 404 em toda rota do grupo.
 */
class FormerMemberSecurityReviewTest extends TestCase
{
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

    /** Contato e fotos (própria e do Google) que não podem sair depois da saída. */
    private function withContactAndPhoto(User $user, string $tag): User
    {
        $user->forceFill([
            'email' => "{$tag}@vazamento.test",
            'pix' => "pix-{$tag}@vazamento.test",
            'whatsapp' => '55119'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT),
            'photo_path' => "photos/{$tag}.jpg",
            'avatar_url' => "https://vazamento.test/{$tag}.png",
        ])->save();

        return $user;
    }

    /** @return list<string> o que identifica `$user` fora do nome: contato e as duas fotos */
    private function secretsOf(User $user): array
    {
        $user = $user->fresh();

        return [$user->email, $user->pix, $user->whatsapp, "user/{$user->id}/photo", 'https://vazamento.test/'];
    }

    /**
     * Grupo de quatro, como o do revisor: `$creator` (criou o grupo), `$left` (participante),
     * `$creditorWhoLeft` e `$stays`. `$left` e `$creator` saem pela rota; `$creditorWhoLeft`,
     * credor com parcela em aberto de outra pessoa, a rota recusaria (D3-B), então só sai por
     * dado antigo ou corrida, e aqui é desvinculado direto.
     *
     * @return array{group: Group, creator: User, left: User, creditorWhoLeft: User, stays: User, expense: Expense, owedToFormerCreditor: Expense}
     */
    private function groupAfterTheExits(): array
    {
        ['group' => $group, 'users' => [$creator, $left, $creditorWhoLeft, $stays]] = $this->makeGroup(4);
        $group->update(['created_by' => $creator->id]);
        $this->withContactAndPhoto($creator, 'criador');
        $this->withContactAndPhoto($left, 'participante');
        $this->withContactAndPhoto($creditorWhoLeft, 'credor');

        $expense = $this->makePurchase($group, $stays, [$stays, $left, $creator], 'IN_CASH', 90.00, '2026-10-05');
        $owedToFormerCreditor = $this->makePurchase($group, $creditorWhoLeft, [$creditorWhoLeft, $stays], 'IN_CASH', 60.00, '2026-10-06');

        $this->as($left, 'DELETE', "/api/groups/{$group->id}/members/{$left->id}")->assertOk();
        $this->as($creator, 'DELETE', "/api/groups/{$group->id}/members/{$creator->id}")->assertOk();
        $group->members()->detach($creditorWhoLeft->id);

        return compact('group', 'creator', 'left', 'creditorWhoLeft', 'stays', 'expense', 'owedToFormerCreditor');
    }

    /** @return list<string> as leituras que quem ficou faz sobre o grupo */
    private function readsOf(Group $group, Expense $expense, Expense $owedToFormerCreditor): array
    {
        $g = $group->id;

        return [
            "/api/expenses/{$expense->id}",
            "/api/expenses/{$owedToFormerCreditor->id}",
            '/api/groups',
            "/api/groups/{$g}",
            "/api/groups/{$g}/members",
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
            '/api/me/overview',
        ];
    }

    public function test_nothing_a_member_reads_carries_the_contact_or_photo_of_who_left(): void
    {
        $s = $this->groupAfterTheExits();
        $formerMembers = [$s['creator'], $s['left'], $s['creditorWhoLeft']];

        // A busca só vale se os dados existem de fato e se ela enxerga contato onde ele deve estar.
        foreach ($formerMembers as $former) {
            foreach ($this->secretsOf($former) as $secret) {
                $this->assertNotEmpty($secret);
            }
        }
        $members = json_encode($this->as($s['stays'], 'GET', "/api/groups/{$s['group']->id}/members")->assertOk()->json(), JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString($s['stays']->email, $members, 'membros veem o contato uns dos outros');

        $responses = [];
        foreach ($this->readsOf($s['group'], $s['expense'], $s['owedToFormerCreditor']) as $url) {
            $responses["GET {$url}"] = $this->as($s['stays'], 'GET', $url)->assertOk();
        }
        // A resposta do `update` também serializa os participantes (A1).
        $responses['PUT expense'] = $this->as($s['stays'], 'PUT', "/api/expenses/{$s['expense']->id}", ['description' => 'Editada'])->assertOk();

        foreach ($responses as $label => $response) {
            $body = json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            foreach ($formerMembers as $former) {
                foreach ($this->secretsOf($former) as $secret) {
                    $this->assertStringNotContainsString($secret, $body, "{$label} deixou sair '{$secret}' de quem saiu");
                }
            }
        }
    }

    public function test_the_creator_who_left_keeps_created_by_but_not_the_email_and_cannot_remove_anyone(): void
    {
        $s = $this->groupAfterTheExits();
        $g = $s['group']->id;

        $show = $this->as($s['stays'], 'GET', "/api/groups/{$g}")->assertOk();
        $this->assertSame($s['creator']->id, $show->json('created_by'));
        $this->assertNull($show->json('creator'));

        $this->as($s['creator'], 'DELETE', "/api/groups/{$g}/members/{$s['stays']->id}")->assertStatus(404);
        $this->assertTrue($s['group']->members()->where('user_id', $s['stays']->id)->exists());
    }

    public function test_the_owner_cannot_make_who_left_the_creditor_nor_add_a_stranger(): void
    {
        $s = $this->groupAfterTheExits();
        $stranger = User::factory()->create();
        $url = "/api/expenses/{$s['expense']->id}";

        // A3: quem saiu não vira credor, nem o que saiu pela rota nem o que saiu por dado antigo.
        $this->as($s['stays'], 'PUT', $url, ['user_payer_id' => $s['left']->id])->assertStatus(422);
        $this->as($s['stays'], 'PUT', $url, ['user_payer_id' => $s['creditorWhoLeft']->id])->assertStatus(422);
        $this->as($s['stays'], 'PUT', $url, ['user_payer_id' => $stranger->id])->assertStatus(422);
        $this->as($s['stays'], 'PUT', $url, ['payers' => [$s['stays']->id, $stranger->id]])->assertStatus(422);
        foreach ([true, '1.0', [$s['left']->id], null, 'abc'] as $malformed) {
            $this->as($s['stays'], 'PUT', $url, ['payers' => [$malformed]])->assertStatus(422);
        }

        $this->assertSame($s['stays']->id, $s['expense']->fresh()->user_payer_id);
        $this->assertEqualsCanonicalizing(
            [$s['stays']->id, $s['left']->id, $s['creator']->id],
            $s['expense']->payers()->pluck('ex_users.id')->all()
        );

        // O que segue valendo: o credor atual e os participantes que saíram continuam aceitos.
        $this->as($s['stays'], 'PUT', $url, ['user_payer_id' => $s['stays']->id, 'payers' => [$s['stays']->id, $s['left']->id]])->assertOk();
    }

    public function test_a_member_who_left_gets_404_on_every_route_of_the_group_and_sees_no_group(): void
    {
        $s = $this->groupAfterTheExits();
        $g = $s['group']->id;
        $e = $s['expense']->id;

        $reads = array_values(array_filter(
            $this->readsOf($s['group'], $s['expense'], $s['owedToFormerCreditor']),
            fn (string $url) => ! in_array($url, ['/api/groups', '/api/me/overview'], true)
        ));
        // Leituras primeiro e escritas por último, as que apagam depois das outras.
        $writes = [
            ['PUT', "/api/expenses/{$e}", ['description' => 'x']],
            ['POST', "/api/expenses/{$e}/pay", []],
            ['POST', "/api/expenses/{$e}/unpay", []],
            ['POST', "/api/expenses/{$e}/stop-recurrence", ['year' => 2026, 'month' => 10]],
            ['PUT', "/api/groups/{$g}", ['name' => 'invadido']],
            ['POST', "/api/groups/{$g}/members", ['email' => $s['stays']->email]],
            ['POST', "/api/groups/{$g}/expenses/close", []],
            ['POST', "/api/groups/{$g}/expenses/reopen", []],
            ['POST', "/api/groups/{$g}/settlements/confirm", ['to_user_id' => $s['stays']->id]],
            ['DELETE', "/api/groups/{$g}/members/{$s['stays']->id}", []],
            ['DELETE', "/api/expenses/{$e}", []],
            ['DELETE', "/api/groups/{$g}", []],
        ];

        foreach ($reads as $url) {
            // Controle: para quem ficou o caminho e os ids são válidos, então o 404 de baixo é da autorização.
            $this->assertNotSame(404, $this->as($s['stays'], 'GET', $url)->status(), "controle: GET {$url}");
        }

        foreach ([$s['left'], $s['creator']] as $former) {
            foreach ($reads as $url) {
                $this->assertSame(404, $this->as($former, 'GET', $url)->status(), "GET {$url}");
            }
            foreach ($writes as [$method, $url, $payload]) {
                app('router')->getRoutes()->match(Request::create($url, $method)); // lança se a rota não existe
                $this->assertSame(404, $this->as($former, $method, $url, $payload)->status(), "{$method} {$url}");
            }

            $this->assertSame([], $this->as($former, 'GET', '/api/groups')->assertOk()->json());
            $this->assertSame([], $this->as($former, 'GET', '/api/me/overview')->assertOk()->json('groups'));
        }

        // Nenhuma das escritas pegou: o grupo, a despesa e os membros seguem como estavam.
        $this->assertDatabaseHas('ex_groups', ['id' => $g, 'deleted' => 0]);
        $this->assertNotSame('invadido', $s['group']->fresh()->name);
        $this->assertDatabaseHas('ex_expenses', ['id' => $e, 'deleted' => 0]);
        $this->assertTrue($s['group']->members()->where('user_id', $s['stays']->id)->exists());
    }
}
