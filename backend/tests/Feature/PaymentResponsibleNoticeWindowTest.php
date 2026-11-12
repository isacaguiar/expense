<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMemberResponsible;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Janela de 24 h contra o aviso repetido de "quem paga por você" (feature
 * 20261110-quem-paga-se-declara-e-credor-recusa, TASK-603, item 141, specify R11 e D11): o devedor que
 * tira e põe a mesma pessoa na lista não pode fazê-la receber um aviso a cada vez. A marca fica no cache,
 * por (grupo, devedor, responsável, tipo), e o aviso é best effort (R13).
 */
class PaymentResponsibleNoticeWindowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-19 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function groupWith(array $members): Group
    {
        $group = Group::create(['name' => 'Casa']);
        $group->members()->attach(array_map(fn (User $user) => $user->id, $members));

        return $group;
    }

    /** O devedor manda a própria lista inteira (a chamada entra no cache de login do instante atual). */
    private function setList(User $debtor, Group $group, array $ids): TestResponse
    {
        return $this->withToken(auth('api')->login($debtor))
            ->putJson("/api/groups/{$group->id}/members/{$debtor->id}/responsibles", ['responsible_user_ids' => $ids]);
    }

    private function assignedFor(User $user): int
    {
        return Notification::where('type', 'payment_responsible_assigned')->where('user_id', $user->id)->count();
    }

    public function test_toggling_the_list_within_24h_notifies_once(): void
    {
        [$debtor, $responsible] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$debtor, $responsible]);

        $this->setList($debtor, $group, [$responsible->id])->assertOk();
        $this->setList($debtor, $group, [])->assertOk();
        $this->setList($debtor, $group, [$responsible->id])->assertOk();
        $this->setList($debtor, $group, [])->assertOk();
        $this->setList($debtor, $group, [$responsible->id])->assertOk();

        $this->assertSame(1, $this->assignedFor($responsible), 'a pessoa recebe um aviso só, por mais que o devedor alterne');
        $this->assertSame(1, GroupMemberResponsible::where('group_id', $group->id)->count(), 'o vínculo vale como sempre');
    }

    public function test_after_24h_it_notifies_again(): void
    {
        [$debtor, $responsible] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$debtor, $responsible]);
        $this->setList($debtor, $group, [$responsible->id])->assertOk();
        $this->setList($debtor, $group, [])->assertOk();

        Carbon::setTestNow('2026-08-20 09:00:00'); // 23 h depois
        $this->setList($debtor, $group, [$responsible->id])->assertOk();
        $this->assertSame(1, $this->assignedFor($responsible), 'dentro da janela, sem aviso novo');
        $this->setList($debtor, $group, [])->assertOk();

        Carbon::setTestNow('2026-08-20 11:00:00'); // 25 h depois do primeiro aviso
        $this->setList($debtor, $group, [$responsible->id])->assertOk();

        $this->assertSame(2, $this->assignedFor($responsible), 'passada a janela, avisa de novo');
    }

    public function test_another_pair_is_not_affected(): void
    {
        [$debtor, $otherDebtor, $first, $second] = User::factory()->count(4)->create()->all();
        $group = $this->groupWith([$debtor, $otherDebtor, $first, $second]);
        $otherGroup = $this->groupWith([$debtor, $first]);
        $this->setList($debtor, $group, [$first->id])->assertOk();
        $this->assertSame(1, $this->assignedFor($first));

        // Outro responsável do mesmo devedor.
        $this->setList($debtor, $group, [$first->id, $second->id])->assertOk();
        $this->assertSame(1, $this->assignedFor($second));

        // O mesmo responsável, mas outro devedor.
        $this->setList($otherDebtor, $group, [$first->id])->assertOk();
        $this->assertSame(2, $this->assignedFor($first));

        // O mesmo par, mas em outro grupo.
        $this->setList($debtor, $otherGroup, [$first->id])->assertOk();
        $this->assertSame(3, $this->assignedFor($first));
    }

    public function test_a_failed_send_does_not_block_the_next_one(): void
    {
        [$debtor, $responsible] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$debtor, $responsible]);
        $attempts = 0;
        Notification::creating(function () use (&$attempts) {
            if ($attempts++ === 0) {
                throw new \RuntimeException('falha simulada ao gravar a notificação');
            }
        });

        $this->setList($debtor, $group, [$responsible->id])->assertOk();
        $this->assertSame(0, $this->assignedFor($responsible), 'o 1º aviso falhou');

        // A falha não deixou a marca: o próximo aviso sai, sem esperar 24 h.
        $this->setList($debtor, $group, [])->assertOk();
        $this->setList($debtor, $group, [$responsible->id])->assertOk();

        $this->assertSame(1, $this->assignedFor($responsible));
    }

    public function test_a_cache_failure_is_logged_and_the_action_still_succeeds(): void
    {
        Log::spy();
        [$debtor, $responsible] = User::factory()->count(2)->create()->all();
        $group = $this->groupWith([$debtor, $responsible]);
        // Um cache que lança só para a marca deste aviso: o resto (limite de requisições, JWT) segue normal.
        Cache::extend('exploding', fn () => Cache::repository(new class extends ArrayStore
        {
            public function get($key)
            {
                if (str_contains($key, 'payment-responsible:')) {
                    throw new \RuntimeException('cache fora do ar');
                }

                return parent::get($key);
            }
        }));
        config(['cache.stores.exploding' => ['driver' => 'exploding'], 'cache.default' => 'exploding']);

        $this->setList($debtor, $group, [$responsible->id])->assertOk();

        $this->assertSame([$responsible->id], GroupMemberResponsible::where('group_id', $group->id)->pluck('responsible_user_id')->map(fn ($id) => (int) $id)->all(), 'o vínculo existe mesmo sem o aviso');
        $this->assertSame(0, $this->assignedFor($responsible));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => ($context['type'] ?? null) === 'payment_responsible_assigned')
            ->once();
    }
}
