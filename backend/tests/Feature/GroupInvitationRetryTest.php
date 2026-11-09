<?php

namespace Tests\Feature;

use App\Mail\UserInvitedMail;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use App\Services\GroupInvitationService;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A4 (revisão de segurança, TASK-576): dois convites novos em grupos diferentes podem cair na
 * mesma lacuna do índice `unique(group_id, user_id)` e dar deadlock (erro 1213). O
 * `GroupInvitationService::invite` refaz a transação até 3 vezes.
 *
 * **Sem `DatabaseTransactions`, de propósito:** dentro da transação do teste o Laravel não
 * retenta (só o nível mais externo retenta; no aninhado ele relança), então a retentativa só se
 * prova com a transação do service no nível zero. Por isso o que este teste grava é comitado e
 * a limpeza é feita à mão em `tearDown`: o grupo (que leva convites, vínculos e avisos em
 * cascata) e os usuários. Os dados têm e-mail e nome únicos.
 */
class GroupInvitationRetryTest extends TestCase
{
    /** @var list<int> */
    private array $groupIds = [];

    /** @var list<string> */
    private array $emails = [];

    protected function tearDown(): void
    {
        GroupInvitation::flushEventListeners();

        if ($this->groupIds !== []) {
            Group::whereIn('id', $this->groupIds)->delete();
        }
        if ($this->emails !== []) {
            User::whereIn('email', $this->emails)->delete();
            foreach ($this->emails as $email) {
                Cache::forget('invitation-token:'.$email);
            }
        }

        parent::tearDown();
    }

    /** @return array{0: Group, 1: User, 2: string} grupo com um membro (quem convida) e o e-mail a convidar */
    private function groupMemberAndNewEmail(): array
    {
        $inviter = User::factory()->create();
        $group = Group::create(['name' => 'Casa retentativa '.uniqid()]);
        $group->members()->attach($inviter->id);
        $email = 'retentativa.'.uniqid().'@example.com';

        $this->groupIds[] = $group->id;
        $this->emails[] = $inviter->email;
        $this->emails[] = $email;

        return [$group, $inviter, $email];
    }

    private function deadlock(): QueryException
    {
        return new QueryException(
            'mysql',
            'insert into `ex_group_invitations` (`group_id`, `user_id`) values (?, ?)',
            [],
            new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction')
        );
    }

    public function test_a_deadlock_while_creating_the_invitation_is_retried(): void
    {
        Mail::fake();
        [$group, $inviter, $email] = $this->groupMemberAndNewEmail();
        $thrown = 0;
        GroupInvitation::creating(function () use (&$thrown) {
            if ($thrown === 0) {
                $thrown++;

                throw $this->deadlock();
            }
        });

        $invitation = app(GroupInvitationService::class)->invite($group, $email, $inviter);

        $this->assertSame(1, $thrown, 'o deadlock simulado aconteceu uma vez');
        $this->assertNotNull($invitation);
        $this->assertSame(1, GroupInvitation::where('group_id', $group->id)->count());
        $this->assertSame(1, User::where('email', $email)->count());
        // A conta da primeira tentativa foi desfeita e a da segunda é a que vale: um e-mail só.
        app()->terminate();
        Mail::assertSent(UserInvitedMail::class, 1);
    }

    public function test_it_gives_up_after_three_attempts(): void
    {
        Mail::fake();
        [$group, $inviter, $email] = $this->groupMemberAndNewEmail();
        $thrown = 0;
        GroupInvitation::creating(function () use (&$thrown) {
            $thrown++;

            throw $this->deadlock();
        });

        try {
            app(GroupInvitationService::class)->invite($group, $email, $inviter);
            $this->fail('três deadlocks seguidos deveriam estourar');
        } catch (QueryException) {
            // esperado
        }

        $this->assertSame(3, $thrown);
        $this->assertSame(0, User::where('email', $email)->count(), 'a conta de cada tentativa foi desfeita');
        $this->assertSame(0, GroupInvitation::where('group_id', $group->id)->count());
        app()->terminate();
        Mail::assertNothingSent();
    }

    /**
     * Entre as duas tentativas outra requisição criou a mesma conta. Como a conta já existia
     * quando a repetição rodou, ela não é "nossa": quem convida não manda e-mail de ativação a
     * uma conta que não criou (o `$created` da tentativa desfeita não pode vazar).
     */
    public function test_the_account_of_a_rolled_back_attempt_does_not_leak_into_the_retry(): void
    {
        Mail::fake();
        [$group, $inviter, $email] = $this->groupMemberAndNewEmail();
        $thrown = 0;
        GroupInvitation::creating(function () use (&$thrown) {
            if ($thrown === 0) {
                $thrown++;

                throw $this->deadlock();
            }
        });
        $concurrentAccount = false;
        Event::listen(TransactionRolledBack::class, function () use (&$concurrentAccount, $email) {
            if (! $concurrentAccount) {
                $concurrentAccount = true;
                DB::table('ex_users')->insert([
                    'name' => 'criada por outra requisição',
                    'email' => $email,
                    'password' => null,
                    'role' => 'user',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $invitation = app(GroupInvitationService::class)->invite($group, $email, $inviter);

        $this->assertTrue($concurrentAccount);
        $this->assertNotNull($invitation);
        $this->assertSame(1, User::where('email', $email)->count());
        app()->terminate();
        Mail::assertNothingSent();
    }
}
