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

    /**
     * @param User  $user
     * @param Group $group
     * @param string $token
     */
    public function __construct(User $user, Group $group, string $token)
    {
        $this->user  = $user;
        $this->group = $group;
        $this->token = $token;
    }

    public function build()
    {
        $inviteUrl = url("/password/reset/{$this->token}?email={$this->user->email}");

        return $this->subject("Você foi convidado para o grupo “{$this->group->name}”")
                    ->view('emails.user_invited')
                    ->with([
                        'userName'  => $this->user->name,
                        'groupName' => $this->group->name,
                        'inviteUrl' => $inviteUrl,
                    ]);    
    }
}
