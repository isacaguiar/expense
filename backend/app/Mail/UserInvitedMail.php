<?php

namespace App\Mail;

use App\Models\Group;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UserInvitedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;

    public $group;

    public $token;

    public $inviter;

    public function __construct(User $user, Group $group, string $token, User $inviter)
    {
        $this->user = $user;
        $this->group = $group;
        $this->token = $token;
        $this->inviter = $inviter;
    }

    public function build()
    {
        $activationLink = url("/aceitar-convite?email={$this->user->email}&token={$this->token}");

        return $this->subject("Você foi convidado para o grupo “{$this->group->name}”")
            ->view('email.invitation')
            ->with([
                'inviterName' => $this->inviter->name,
                'groupName' => $this->group->name,
                'inviteMessage' => null,
                'activationLink' => $activationLink,
            ]);
    }
}
