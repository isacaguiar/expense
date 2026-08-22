<?php

namespace Tests\Feature;

use App\Mail\UserInvitedMail;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GroupMemberInvitationMailTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    // Sem Mail::fake() de propósito -- MAIL_MAILER=array (phpunit.xml) ainda renderiza
    // a view de verdade antes de guardar a mensagem em memória, então é o jeito de
    // garantir que a view `email.invitation` existe e não quebra em runtime.
    public function test_new_member_invite_resolves_the_real_view(): void
    {
        $member = User::factory()->create(['name' => 'Convidante']);
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $newMemberEmail = 'novo.membro.'.uniqid().'@example.com';

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups/'.$group->id.'/members', ['email' => $newMemberEmail]);

        $response->assertStatus(201);
    }

    public function test_new_member_invite_mail_carries_inviter_and_group_name(): void
    {
        Mail::fake();

        $member = User::factory()->create(['name' => 'Convidante']);
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $newMemberEmail = 'novo.membro.'.uniqid().'@example.com';

        $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups/'.$group->id.'/members', ['email' => $newMemberEmail]);

        Mail::assertSent(UserInvitedMail::class, function (UserInvitedMail $mail) use ($member, $group, $newMemberEmail) {
            return $mail->hasTo($newMemberEmail)
                && $mail->inviter->is($member)
                && $mail->group->is($group);
        });
    }
}
