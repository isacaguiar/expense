<?php

namespace Tests\Feature;

use App\Mail\UserInvitedMail;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\Notification;
use App\Models\User;
use App\Services\GroupInvitationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsGroupInvitations;
use Tests\Concerns\CapturesTransactionQueries;
use Tests\TestCase;

/**
 * `POST /api/groups/{id}/members` cria (ou renova, ou reabre) um convite pendente em vez de
 * adicionar a pessoa: ninguém vira membro sem aceitar (feature
 * 20261103-convite-pendente-no-grupo, plan §2, CA-1, CA-2, CA-3, CA-9 e CA-10).
 */
class GroupInvitationCreateTest extends TestCase
{
    use BuildsGroupInvitations;
    use CapturesTransactionQueries;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Notification::flushEventListeners();

        parent::tearDown();
    }

    private function as(User $user, string $method, string $url, array $payload = []): TestResponse
    {
        return $this->withToken(auth('api')->login($user))->json($method, $url, $payload);
    }

    private function invite(User $by, Group $group, string $email): TestResponse
    {
        return $this->as($by, 'POST', "/api/groups/{$group->id}/members", ['email' => $email]);
    }

    /** @return array{0: Group, 1: User} o grupo "Casa" e o único membro, que é quem convida */
    private function groupWithMember(): array
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Casa']);
        $group->members()->attach($member->id);

        return [$group, $member];
    }

    private function invitationOf(Group $group, string $email): ?GroupInvitation
    {
        $user = User::where('email', $email)->first();

        return $user === null
            ? null
            : GroupInvitation::where('group_id', $group->id)->where('user_id', $user->id)->first();
    }

    // --- CA-1: convite, não membro --------------------------------------

    public function test_inviting_an_existing_user_creates_a_pending_invitation_and_not_a_member(): void
    {
        [$group, $member] = $this->groupWithMember();
        $invitee = User::factory()->create();
        $pivotRowsBefore = DB::table('ex_groups_members')->count();

        $response = $this->invite($member, $group, $invitee->email);

        $response->assertStatus(201)
            ->assertJsonPath('pending', true)
            ->assertJsonPath('invitation.email', $invitee->email)
            ->assertJsonStructure(['message', 'pending', 'invitation' => ['id', 'email', 'expires_at']]);
        $this->assertSame($pivotRowsBefore, DB::table('ex_groups_members')->count());
        $this->assertFalse($group->members()->where('user_id', $invitee->id)->exists());
        $this->assertDatabaseHas('ex_group_invitations', [
            'group_id' => $group->id,
            'user_id' => $invitee->id,
            'invited_by' => $member->id,
            'status' => GroupInvitation::STATUS_PENDING,
        ]);
    }

    public function test_the_invitee_stays_out_of_every_member_list_and_does_not_see_the_group(): void
    {
        [$group, $member] = $this->groupWithMember();
        $invitee = User::factory()->create();
        $this->invite($member, $group, $invitee->email)->assertStatus(201);

        $listed = collect($this->as($member, 'GET', "/api/groups/{$group->id}/members")->assertOk()->json());
        $this->assertSame([$member->id], $listed->pluck('id')->all());

        $groups = $this->as($member, 'GET', '/api/groups')->assertOk()->json();
        $this->assertSame([$member->id], collect($groups[0]['members'])->pluck('id')->all());

        $this->assertSame([], $this->as($invitee, 'GET', '/api/groups')->assertOk()->json());
    }

    public function test_a_new_invitation_is_valid_for_seven_days(): void
    {
        Carbon::setTestNow('2026-11-03 12:00:00');
        [$group, $member] = $this->groupWithMember();
        $invitee = User::factory()->create();

        $this->invite($member, $group, $invitee->email)->assertStatus(201);

        $invitation = $this->invitationOf($group, $invitee->email);
        $this->assertSame('2026-11-10 12:00:00', $invitation->expires_at->format('Y-m-d H:i:s'));
    }

    // --- CA-2: resposta uniforme, 409 e 404 -----------------------------

    public function test_the_response_is_the_same_for_an_email_with_and_without_an_account(): void
    {
        Mail::fake();
        [$group, $member] = $this->groupWithMember();
        $existing = User::factory()->create();

        $withAccount = $this->invite($member, $group, $existing->email);
        $withoutAccount = $this->invite($member, $group, 'novo.'.uniqid().'@example.com');

        $withAccount->assertStatus(201);
        $withoutAccount->assertStatus(201);
        $this->assertSame('Convite enviado.', $withAccount->json('message'));
        $this->assertSame($withAccount->json('message'), $withoutAccount->json('message'));
        $this->assertSame(array_keys($withAccount->json()), array_keys($withoutAccount->json()));
        $this->assertSame(array_keys($withAccount->json('invitation')), array_keys($withoutAccount->json('invitation')));
    }

    public function test_inviting_a_member_returns_409_and_creates_no_invitation(): void
    {
        $other = User::factory()->create();
        [$group, $member] = $this->groupWithMember();
        $group->members()->attach($other->id);

        $this->invite($member, $group, $other->email)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Usuário já é membro deste grupo.');
        // O próprio e-mail de quem convida também é de um membro.
        $this->invite($member, $group, $member->email)->assertStatus(409);

        $this->assertSame(0, GroupInvitation::count());
    }

    public function test_a_non_member_gets_404_and_nothing_is_created(): void
    {
        Mail::fake();
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Casa']);
        $newEmail = 'novo.'.uniqid().'@example.com';

        $this->invite($outsider, $group, $newEmail)->assertStatus(404);

        $this->assertDatabaseMissing('ex_users', ['email' => $newEmail]);
        $this->assertSame(0, GroupInvitation::count());
        Mail::assertNothingSent();
    }

    public function test_a_deleted_group_gets_404_and_nothing_is_created(): void
    {
        Mail::fake();
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Casa', 'deleted' => true]);
        $group->members()->attach($member->id);
        $newEmail = 'novo.'.uniqid().'@example.com';

        $this->invite($member, $group, $newEmail)->assertStatus(404);

        $this->assertDatabaseMissing('ex_users', ['email' => $newEmail]);
        $this->assertSame(0, GroupInvitation::count());
        Mail::assertNothingSent();
    }

    public function test_the_email_must_be_valid(): void
    {
        [$group, $member] = $this->groupWithMember();

        $this->invite($member, $group, 'isto-nao-e-um-email')
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(0, GroupInvitation::count());
    }

    // --- CA-3 e D5: repetir o convite -----------------------------------

    public function test_repeating_the_invitation_does_not_duplicate_it_and_renews_the_expiry(): void
    {
        Carbon::setTestNow('2026-11-03 12:00:00');
        [$group, $member] = $this->groupWithMember();
        $invitee = User::factory()->create();

        $first = $this->invite($member, $group, $invitee->email)->assertStatus(201);
        Carbon::setTestNow('2026-11-06 12:00:00');
        $second = $this->invite($member, $group, $invitee->email)->assertStatus(201);

        $this->assertSame(1, GroupInvitation::where('group_id', $group->id)->where('user_id', $invitee->id)->count());
        $this->assertSame($first->json('invitation.id'), $second->json('invitation.id'));
        $invitation = $this->invitationOf($group, $invitee->email);
        $this->assertSame('2026-11-13 12:00:00', $invitation->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_a_repeated_invitation_does_not_notify_again_within_24_hours(): void
    {
        Carbon::setTestNow('2026-11-03 12:00:00');
        [$group, $member] = $this->groupWithMember();
        $invitee = User::factory()->create();

        $this->invite($member, $group, $invitee->email)->assertStatus(201);
        Carbon::setTestNow('2026-11-04 11:00:00');
        $this->invite($member, $group, $invitee->email)->assertStatus(201);

        $this->assertSame(1, Notification::where('type', 'group_invitation')->count());
        $this->assertSame(
            '2026-11-03 12:00:00',
            $this->invitationOf($group, $invitee->email)->notified_at->format('Y-m-d H:i:s')
        );
    }

    public function test_a_repeated_invitation_notifies_again_after_24_hours(): void
    {
        Carbon::setTestNow('2026-11-03 12:00:00');
        [$group, $member] = $this->groupWithMember();
        $invitee = User::factory()->create();

        $this->invite($member, $group, $invitee->email)->assertStatus(201);
        Carbon::setTestNow('2026-11-04 13:00:00');
        $this->invite($member, $group, $invitee->email)->assertStatus(201);

        $this->assertSame(2, Notification::where('type', 'group_invitation')->where('user_id', $invitee->id)->count());
        $this->assertSame(
            '2026-11-04 13:00:00',
            $this->invitationOf($group, $invitee->email)->notified_at->format('Y-m-d H:i:s')
        );
    }

    public function test_two_members_inviting_the_same_person_keep_one_invitation_from_the_first_inviter(): void
    {
        Carbon::setTestNow('2026-11-03 12:00:00');
        [$group, $first] = $this->groupWithMember();
        $second = User::factory()->create();
        $group->members()->attach($second->id);
        $invitee = User::factory()->create();

        $this->invite($first, $group, $invitee->email)->assertStatus(201);
        Carbon::setTestNow('2026-11-03 13:00:00');
        $this->invite($second, $group, $invitee->email)->assertStatus(201);

        $this->assertSame(1, GroupInvitation::where('user_id', $invitee->id)->count());
        $invitation = $this->invitationOf($group, $invitee->email);
        $this->assertSame($first->id, $invitation->invited_by);
        $this->assertSame('2026-11-10 13:00:00', $invitation->expires_at->format('Y-m-d H:i:s'));
    }

    // --- R13 e D9-A: reabrir um convite já respondido -------------------

    /**
     * @return array<string, array{0: string, 1: bool}> estado do convite antigo e se ele já tinha vencido
     */
    public static function reopenableInvitations(): array
    {
        return [
            'recusado' => [GroupInvitation::STATUS_DECLINED, false],
            'cancelado' => [GroupInvitation::STATUS_CANCELLED, false],
            'aceito e depois a pessoa saiu' => [GroupInvitation::STATUS_ACCEPTED, false],
            'pendente que venceu' => [GroupInvitation::STATUS_PENDING, true],
        ];
    }

    /**
     * @dataProvider reopenableInvitations
     */
    public function test_an_answered_or_expired_invitation_is_reopened_for_a_new_invite(string $oldStatus, bool $expired): void
    {
        Carbon::setTestNow('2026-11-03 12:00:00');
        [$group, $member] = $this->groupWithMember();
        $previousInviter = User::factory()->create();
        $invitee = User::factory()->create();
        $old = $this->makeInvitation($group, $invitee, $oldStatus, [
            'invited_by' => $previousInviter->id,
            'expires_at' => $expired ? now()->subDay() : now()->addDays(2),
            // Avisado há 1 h: só o reset do `notified_at` na reabertura faz o aviso sair de novo.
            'notified_at' => now()->subHour(),
        ]);

        $this->invite($member, $group, $invitee->email)->assertStatus(201);

        $this->assertSame(1, GroupInvitation::where('group_id', $group->id)->where('user_id', $invitee->id)->count());
        $invitation = $old->fresh();
        $this->assertSame(GroupInvitation::STATUS_PENDING, $invitation->status);
        $this->assertSame($member->id, $invitation->invited_by);
        $this->assertNull($invitation->responded_at);
        $this->assertSame('2026-11-10 12:00:00', $invitation->expires_at->format('Y-m-d H:i:s'));
        // O aviso foi zerado e o convite reaberto avisa de novo: a data do aviso é a de agora.
        $this->assertSame('2026-11-03 12:00:00', $invitation->notified_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, Notification::where('type', 'group_invitation')->where('user_id', $invitee->id)->count());
        $this->assertFalse($group->members()->where('user_id', $invitee->id)->exists());
    }

    // --- Releitura sob lock ----------------------------------------------

    public function test_the_service_rereads_the_group_under_the_lock_and_refuses_one_deleted_meanwhile(): void
    {
        Mail::fake();
        [$group, $member] = $this->groupWithMember();
        $newEmail = 'novo.'.uniqid().'@example.com';
        $stale = Group::find($group->id); // o que o controller leu antes de outra requisição excluir o grupo
        Group::where('id', $group->id)->update(['deleted' => true]);

        try {
            app(GroupInvitationService::class)->invite($stale, $newEmail, $member);
            $this->fail('o grupo excluído no meio deveria dar 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertDatabaseMissing('ex_users', ['email' => $newEmail]);
        $this->assertSame(0, GroupInvitation::count());
        Mail::assertNothingSent();
    }

    // --- CA-9: aviso in-app ---------------------------------------------

    public function test_the_invitee_with_an_account_receives_a_notification_without_a_group(): void
    {
        [$group, $member] = $this->groupWithMember();
        $invitee = User::factory()->create();

        $this->invite($member, $group, $invitee->email)->assertStatus(201);

        $rows = Notification::where('type', 'group_invitation')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($invitee->id, $rows->first()->user_id);
        $this->assertNull($rows->first()->group_id);
        $this->assertEqualsCanonicalizing(['actorName', 'groupName'], array_keys($rows->first()->data));
        $this->assertSame($member->name, $rows->first()->data['actorName']);
        $this->assertSame('Casa', $rows->first()->data['groupName']);
        $this->assertNotNull($this->invitationOf($group, $invitee->email)->notified_at);
    }

    public function test_a_notifier_failure_does_not_break_the_response_and_a_later_invite_still_notifies(): void
    {
        Log::spy();
        [$group, $member] = $this->groupWithMember();
        $invitee = User::factory()->create();
        Notification::creating(function () {
            throw new \RuntimeException('falha simulada ao gravar a notificação');
        });

        $this->invite($member, $group, $invitee->email)->assertStatus(201);

        $invitation = $this->invitationOf($group, $invitee->email);
        $this->assertNotNull($invitation, 'o convite existe mesmo sem o aviso');
        $this->assertNull($invitation->notified_at);
        $this->assertSame(0, Notification::where('type', 'group_invitation')->count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => ($context['type'] ?? null) === 'group_invitation')
            ->once();

        // F4: como o aviso não saiu, o `notified_at` segue nulo e um novo convite ainda tenta avisar.
        Notification::flushEventListeners();
        $this->invite($member, $group, $invitee->email)->assertStatus(201);

        $this->assertSame(1, Notification::where('type', 'group_invitation')->count());
        $this->assertNotNull($invitation->fresh()->notified_at);
    }

    // --- CA-10 e R15: e-mail sem conta ----------------------------------

    public function test_a_new_email_creates_the_account_the_token_and_the_mail_but_no_membership(): void
    {
        Mail::fake();
        [$group, $member] = $this->groupWithMember();
        $newEmail = 'novo.'.uniqid().'@example.com';

        $this->invite($member, $group, $newEmail)->assertStatus(201)->assertJsonPath('pending', true);

        $user = User::where('email', $newEmail)->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Cache::has('invitation-token:'.$newEmail));
        Mail::assertSent(UserInvitedMail::class, 1);
        Mail::assertSent(
            UserInvitedMail::class,
            fn (UserInvitedMail $mail) => $mail->hasTo($newEmail) && $mail->group->is($group) && $mail->inviter->is($member)
        );
        $this->assertFalse($group->members()->where('user_id', $user->id)->exists());
        $this->assertSame(GroupInvitation::STATUS_PENDING, $this->invitationOf($group, $newEmail)->status);
        $this->assertSame(1, Notification::where('type', 'group_invitation')->where('user_id', $user->id)->count());
    }

    public function test_repeating_an_invitation_to_a_new_email_does_not_create_another_account_or_resend_the_mail(): void
    {
        Mail::fake();
        [$group, $member] = $this->groupWithMember();
        $newEmail = 'novo.'.uniqid().'@example.com';

        $this->invite($member, $group, $newEmail)->assertStatus(201);
        $this->invite($member, $group, $newEmail)->assertStatus(201);

        $this->assertSame(1, User::where('email', $newEmail)->count());
        Mail::assertSent(UserInvitedMail::class, 1);
    }

    public function test_a_mail_failure_does_not_break_the_response_nor_orphan_the_invitation(): void
    {
        Log::spy();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp fora do ar'));
        [$group, $member] = $this->groupWithMember();
        $newEmail = 'novo.'.uniqid().'@example.com';

        $this->invite($member, $group, $newEmail)->assertStatus(201)->assertJsonPath('pending', true);

        $user = User::where('email', $newEmail)->first();
        $this->assertNotNull($user, 'a conta existe: o convidado ainda pode usar "Esqueci minha senha"');
        $this->assertSame(GroupInvitation::STATUS_PENDING, $this->invitationOf($group, $newEmail)->status);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'e-mail de convite'))
            ->once();
    }

    // --- Revisão de segurança (TASK-576) -----------------------------------

    /**
     * Domínio de exatamente `$length` caracteres (de 4 a 253), de rótulos de até 60, terminado
     * em ".com". Cada rótulo conta "tamanho + 1" (o ponto) e o ".com" fecha com mais 3.
     */
    private function domainOfLength(int $length): string
    {
        $units = $length - 3;
        $count = (int) ceil($units / 61);
        $labels = array_fill(0, $count - 1, str_repeat('b', 60));
        $labels[] = str_repeat('b', $units - 61 * ($count - 1) - 1);

        $domain = implode('.', $labels).'.com';
        $this->assertSame($length, strlen($domain), 'o gerador de domínio errou o comprimento');

        return $domain;
    }

    /**
     * A6: a regra `email` do Laravel aceita endereços de até uns 318 caracteres, e a coluna
     * `ex_users.email` tem 255. Sem o `max`, um endereço novo e longo dava 500 no `User::create`.
     */
    public function test_an_email_longer_than_the_column_is_a_422_and_creates_nothing(): void
    {
        Mail::fake();
        [$group, $member] = $this->groupWithMember();
        $email = str_repeat('a', 64).'@'.$this->domainOfLength(200);
        // A premissa do teste: a regra `email` sozinha aceita este endereço de mais de 255 caracteres.
        $this->assertGreaterThan(255, strlen($email));
        $this->assertTrue(Validator::make(['email' => $email], ['email' => 'required|email'])->passes());

        $this->invite($member, $group, $email)->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame(0, User::where('email', $email)->count());
        $this->assertSame(0, GroupInvitation::count());
        Mail::assertNothingSent();
    }

    public function test_an_email_of_exactly_255_characters_is_still_accepted(): void
    {
        Mail::fake();
        [$group, $member] = $this->groupWithMember();
        $email = str_repeat('a', 64).'@'.$this->domainOfLength(190);
        $this->assertSame(255, strlen($email));

        $this->invite($member, $group, $email)->assertStatus(201);

        $this->assertSame(1, User::where('email', $email)->count());
    }

    /**
     * A1: o `bcrypt` de custo 12 (~370 ms) só rodava para e-mail sem conta e denunciava, pelo
     * tempo, quem já usa o sistema. A senha provisória é um bcrypt de custo 4 (~2 ms).
     *
     * O ambiente de teste fixa `BCRYPT_ROUNDS=4`, então sem este ajuste o teste não distinguiria
     * "o service escolheu o custo 4" de "o padrão já é 4": configura-se o custo de produção (12) e
     * descartam-se os drivers de hash já resolvidos.
     */
    public function test_the_account_created_by_an_invitation_gets_a_cheap_bcrypt_placeholder_password(): void
    {
        Mail::fake();
        config(['hashing.bcrypt.rounds' => 12]);
        app('hash')->forgetDrivers();
        [$group, $member] = $this->groupWithMember();
        $newEmail = 'novo.'.uniqid().'@example.com';

        $this->invite($member, $group, $newEmail)->assertStatus(201);

        $hash = DB::table('ex_users')->where('email', $newEmail)->value('password');
        // Nunca nula: `Hash::check` lançaria e o login dessa conta daria 500 (ver o teste de ativação).
        $this->assertNotNull($hash);
        $info = password_get_info($hash);
        $this->assertSame('bcrypt', $info['algoName']);
        $this->assertSame(4, $info['options']['cost']);
    }

    /**
     * A1: o SMTP síncrono também só rodava no caminho de e-mail novo. O e-mail de ativação (e o
     * token) saem depois da resposta, no término da requisição. Chamar o service direto não
     * envia resposta nenhuma, então nada termina até `app()->terminate()`, que é o que o kernel
     * faz depois de enviá-la.
     */
    public function test_the_activation_mail_leaves_after_the_request_and_not_during_it(): void
    {
        Mail::fake();
        [$group, $member] = $this->groupWithMember();
        $newEmail = 'novo.'.uniqid().'@example.com';

        app(GroupInvitationService::class)->invite($group, $newEmail, $member);

        Mail::assertNothingSent();
        $this->assertFalse(Cache::has('invitation-token:'.$newEmail));

        app()->terminate();

        Mail::assertSent(UserInvitedMail::class, 1);
        Mail::assertSent(UserInvitedMail::class, fn (UserInvitedMail $mail) => $mail->hasTo($newEmail));
        $this->assertTrue(Cache::has('invitation-token:'.$newEmail));

        // Os callbacks de término não são descartados depois de executados: um segundo término no
        // mesmo processo (nos testes, a cada requisição) não pode mandar o e-mail de novo.
        app()->terminate();
        Mail::assertSent(UserInvitedMail::class, 1);
    }

    public function test_an_invited_account_without_a_password_can_still_be_activated_and_log_in(): void
    {
        Mail::fake();
        [$group, $member] = $this->groupWithMember();
        $newEmail = 'novo.'.uniqid().'@example.com';
        $this->invite($member, $group, $newEmail)->assertStatus(201);

        // Antes de ativar nenhuma senha serve, e a resposta é 401, não um erro do servidor.
        $this->postJson('/api/login', ['email' => $newEmail, 'password' => 'qualquer-senha'])->assertStatus(401);

        $token = Cache::get('invitation-token:'.$newEmail);
        $this->assertNotNull($token);
        $this->postJson('/api/invitations/verify', [
            'email' => $newEmail,
            'token' => $token,
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ])->assertOk();

        $this->postJson('/api/login', ['email' => $newEmail, 'password' => 'senha-nova-123'])
            ->assertOk()
            ->assertJsonStructure(['access_token']);
    }

    /**
     * Lacuna da TASK-568 (levada ao usuário na TASK-572): a ordem dos locks de `invite()` não
     * tinha prova. A primeira consulta da transação é o lock do grupo e o segundo `for update` é o
     * do convite, a mesma ordem de `accept()` (ordem diferente dá deadlock).
     */
    public function test_inviting_opens_its_transaction_locking_the_group_and_then_the_invitation(): void
    {
        [$group, $member] = $this->groupWithMember();
        $invitee = User::factory()->create();

        $queries = $this->queriesAfterTheTransactionOpens(
            fn () => $this->invite($member, $group, $invitee->email)->assertStatus(201)
        );

        $this->assertStringContainsString('`ex_groups`', $queries[0]);
        $this->assertStringContainsString(' for update', $queries[0]);
        $locks = array_values(array_filter($queries, fn (string $sql) => str_contains($sql, ' for update')));
        $this->assertCount(2, $locks);
        $this->assertStringContainsString('`ex_group_invitations`', $locks[1]);
    }
}
