<?php

namespace Tests\Unit\WhatsApp;

use App\Support\WhatsApp\PhoneNumber;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_converte_celular_br_para_formato_da_api(): void
    {
        $this->assertSame('5511912345678', PhoneNumber::toApiFormat('(11) 91234-5678'));
    }

    public function test_aceita_fixo_de_dez_digitos(): void
    {
        $this->assertSame('551133224455', PhoneNumber::toApiFormat('(11) 3322-4455'));
    }

    public function test_idempotente_para_numero_que_ja_tem_ddi(): void
    {
        $this->assertSame('5511912345678', PhoneNumber::toApiFormat('5511912345678'));
        $this->assertSame('5511912345678', PhoneNumber::toApiFormat('+55 11 91234-5678'));
    }

    public function test_retorna_null_para_entrada_invalida(): void
    {
        $this->assertNull(PhoneNumber::toApiFormat(null));
        $this->assertNull(PhoneNumber::toApiFormat(''));
        $this->assertNull(PhoneNumber::toApiFormat('abc'));
        $this->assertNull(PhoneNumber::toApiFormat('123'));
        $this->assertNull(PhoneNumber::toApiFormat('(11) 91234-5678 9'));
    }
}
