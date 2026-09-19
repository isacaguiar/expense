<?php

namespace Tests\Feature;

use App\Mail\PreRegisterCodeMail;
use Tests\TestCase;

class PreRegisterCodeMailTest extends TestCase
{
    // Sem Mail::fake()/Mail::shouldReceive de propósito -- nenhum dos dois chega a
    // resolver a Blade, então não pegariam um nome de view errado. Aqui a view é
    // renderizada de verdade via render(), que é o que garante que ela existe.

    public function test_renders_the_real_view_with_code_and_deadline(): void
    {
        $html = (new PreRegisterCodeMail('Maria', '123456', 15))->render();

        $this->assertStringContainsString('123456', $html);
        $this->assertStringContainsString('Maria', $html);
        $this->assertStringContainsString('15 minutos', $html);
    }

    public function test_subject_mentions_the_confirmation_code(): void
    {
        $mail = (new PreRegisterCodeMail('Maria', '123456', 15))->build();

        $this->assertStringContainsString('código de confirmação', $mail->subject);
    }
}
