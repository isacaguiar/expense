<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Notification;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Notificações do convite pendente (feature 20261103-convite-pendente-no-grupo, plan §6):
 * `group_invitation` (ao convidado, sem grupo) e `group_invitation_accepted` (a quem
 * convidou, com grupo). Quem chama o `Notifier` ainda não existe; aqui só o serviço.
 */
class NotifierInvitationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_group_invitation_notifies_the_invitee_without_a_group_id(): void
    {
        $group = Group::create(['name' => 'Casa']);
        $invitee = User::factory()->create();

        $written = Notifier::groupInvitation($group, $invitee->id, 'Ana');

        $this->assertTrue($written);
        $rows = Notification::where('type', 'group_invitation')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($invitee->id, $rows->first()->user_id);
        // Sem group_id e sem groupId: o sino só navega quando há grupo, e o convidado ainda
        // não é membro (abrir o grupo daria 404). Mesmo desenho do group_member_removed.
        $this->assertNull($rows->first()->group_id);
        $data = $rows->first()->data;
        // O MySQL reordena as chaves de uma coluna json: confere o conjunto, não a ordem.
        $this->assertEqualsCanonicalizing(['actorName', 'groupName'], array_keys($data));
        $this->assertSame('Ana', $data['actorName']);
        $this->assertSame('Casa', $data['groupName']);
    }

    public function test_group_invitation_notifies_only_the_invitee(): void
    {
        $group = Group::create(['name' => 'Casa']);
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        $group->members()->attach($inviter->id);

        Notifier::groupInvitation($group, $invitee->id, $inviter->name);

        $this->assertSame(1, Notification::where('type', 'group_invitation')->count());
        $this->assertSame(0, Notification::where('user_id', $inviter->id)->count());
    }

    public function test_group_invitation_swallows_a_write_failure_and_reports_false(): void
    {
        Log::spy();

        $group = Group::create(['name' => 'Casa']);
        $invitee = User::factory()->create();

        // actorName com byte inválido em UTF-8 → json_encode do cast 'data' lança.
        $written = Notifier::groupInvitation($group, $invitee->id, "Jo\xE3o");

        $this->assertFalse($written);
        $this->assertSame(0, Notification::where('type', 'group_invitation')->count());
        $this->assertWarningNamesType('group_invitation');
    }

    public function test_group_invitation_accepted_notifies_the_inviter_with_the_group(): void
    {
        $group = Group::create(['name' => 'Casa']);
        $inviter = User::factory()->create();

        Notifier::groupInvitationAccepted($group, $inviter->id, 'Bruno');

        $rows = Notification::where('type', 'group_invitation_accepted')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($inviter->id, $rows->first()->user_id);
        // Quem convidou é membro e pode abrir o grupo: leva group_id e groupId.
        $this->assertSame($group->id, $rows->first()->group_id);
        $data = $rows->first()->data;
        $this->assertEqualsCanonicalizing(['actorName', 'groupId', 'groupName'], array_keys($data));
        $this->assertSame('Bruno', $data['actorName']);
        $this->assertSame($group->id, $data['groupId']);
        $this->assertSame('Casa', $data['groupName']);
    }

    public function test_group_invitation_accepted_swallows_a_write_failure(): void
    {
        Log::spy();

        $group = Group::create(['name' => 'Casa']);
        $inviter = User::factory()->create();

        Notifier::groupInvitationAccepted($group, $inviter->id, "Jo\xE3o");

        $this->assertSame(0, Notification::where('type', 'group_invitation_accepted')->count());
        $this->assertWarningNamesType('group_invitation_accepted');
    }

    /** O aviso de falha cita o tipo que não foi gravado, para o log dizer o que se perdeu. */
    private function assertWarningNamesType(string $type): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => ($context['type'] ?? null) === $type)
            ->once();
    }
}
