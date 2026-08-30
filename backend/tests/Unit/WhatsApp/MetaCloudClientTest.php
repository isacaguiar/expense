<?php

namespace Tests\Unit\WhatsApp;

use App\Support\WhatsApp\MetaCloudClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaCloudClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp', [
            'enabled' => true,
            'token' => 'tok-123',
            'phone_number_id' => '999',
            'api_version' => 'v21.0',
            'locale' => 'pt_BR',
            'templates' => ['expense_proof' => 'tpl_e', 'settlement_proof' => 'tpl_s'],
        ]);
    }

    public function test_faz_post_de_template_para_a_graph_api(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

        MetaCloudClient::sendTemplate('5511912345678', 'tpl_e', 'pt_BR', [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ana']]],
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v21.0/999/messages'
                && $request->hasHeader('Authorization', 'Bearer tok-123')
                && $request['messaging_product'] === 'whatsapp'
                && $request['to'] === '5511912345678'
                && $request['type'] === 'template'
                && $request['template']['name'] === 'tpl_e'
                && $request['template']['language']['code'] === 'pt_BR'
                && $request['template']['components'][0]['type'] === 'body';
        });
    }

    public function test_lanca_excecao_em_resposta_nao_2xx(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'bad']], 400)]);

        $this->expectException(RequestException::class);

        MetaCloudClient::sendTemplate('5511912345678', 'tpl_e', 'pt_BR', []);
    }
}
