<?php

namespace Tests\Feature;

use App\Mail\UserInvitedMail;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Texto do e-mail de convite depois que o convite virou pendente (feature
 * 20261103-convite-pendente-no-grupo, plan §7): ativar a conta não coloca a pessoa no
 * grupo, ela ainda aceita o convite em Meus Grupos.
 *
 * Renderiza o `UserInvitedMail` de verdade pelo transporte `array` (sem `Mail::fake()`).
 * É o que repõe a garantia do `GroupMemberInvitationMailTest::...resolves_the_real_view`:
 * desde o R15 o `store()` engole falha de e-mail, então uma view quebrada já não derruba
 * aquele teste.
 */
class InvitationMailPendingTextTest extends TestCase
{
    use DatabaseTransactions;

    /** Envia o convite pelo mailer `array` e devolve a mensagem que ele guardou. */
    private function sentInvitation(Group $group, User $inviter, string $token = 'token-de-teste'): Email
    {
        $invitee = User::factory()->create();
        $mailer = Mail::mailer('array');

        $mailer->to($invitee->email)->send(new UserInvitedMail($invitee, $group, $token, $inviter));

        return $mailer->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    }

    public function test_the_mail_says_the_invitation_is_pending_and_where_to_answer_it(): void
    {
        $inviter = User::factory()->create(['name' => 'Convidante']);
        $group = Group::create(['name' => 'Casa']);

        $html = $this->sentInvitation($group, $inviter)->getHtmlBody();

        // Na ordem em que a pessoa lê: o que é o convite, o grupo, e onde ela responde.
        $this->assertMatchesRegularExpression(
            '/convite pendente.*?só entra em <strong>Casa<\/strong> se aceitar.*?<strong>Meus Grupos<\/strong>/s',
            $html
        );
    }

    public function test_the_activation_button_the_link_and_the_footer_are_still_there(): void
    {
        $inviter = User::factory()->create(['name' => 'Convidante']);
        $group = Group::create(['name' => 'Casa']);
        $invitee = User::factory()->create();
        $mailer = Mail::mailer('array');

        $mailer->to($invitee->email)->send(new UserInvitedMail($invitee, $group, 'abc123', $inviter));
        $html = $mailer->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHtmlBody();

        $link = config('services.frontend_app_url')."/aceitar-convite?email={$invitee->email}&token=abc123";
        $this->assertStringContainsString('Ativar minha conta', $html);
        // O Blade escapa o `&` do link no HTML. O botão e o link em texto ("copie e cole")
        // levam ao mesmo endereço: um botão morto não pode passar só porque o link existe.
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(e($link), '/').'" class="button">/', $html);
        $this->assertSame(2, substr_count($html, 'href="'.e($link).'"'));
        $this->assertStringContainsString('válido para um único uso', $html);
        $this->assertStringContainsString('<strong>Convidante</strong>', $html);
    }

    public function test_the_subject_still_names_the_group(): void
    {
        $inviter = User::factory()->create();
        $group = Group::create(['name' => 'Casa']);

        $email = $this->sentInvitation($group, $inviter);

        $this->assertSame('Você foi convidado para o grupo “Casa”', $email->getSubject());
    }

    public function test_the_group_name_is_escaped_in_the_new_paragraph_too(): void
    {
        $inviter = User::factory()->create();
        $group = Group::create(['name' => '<script>alert(1)</script>']);

        $html = $this->sentInvitation($group, $inviter)->getHtmlBody();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertMatchesRegularExpression(
            '/só entra em <strong>&lt;script&gt;alert\(1\)&lt;\/script&gt;<\/strong> se aceitar/',
            $html
        );
    }
}
