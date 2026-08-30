<?php

namespace App\Support\WhatsApp;

use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP puro da Meta WhatsApp Cloud API — uma responsabilidade só:
 * mandar uma mensagem `type: template` para um número. Sem lógica de
 * domínio (quem recebe, o que a mensagem diz) — isso é do `WhatsAppNotifier`.
 *
 * Lança `Illuminate\Http\Client\RequestException` em resposta não-2xx; quem
 * chama (`WhatsAppNotifier`) é quem decide engolir e logar.
 */
class MetaCloudClient
{
    /**
     * @param  array<int, array<string, mixed>>  $components  componentes do template (body, button, ...)
     */
    public static function sendTemplate(string $toPhone, string $templateName, string $languageCode, array $components): void
    {
        $config = config('services.whatsapp');

        $template = [
            'name' => $templateName,
            'language' => ['code' => $languageCode],
        ];

        if ($components !== []) {
            $template['components'] = $components;
        }

        Http::withToken($config['token'])
            ->acceptJson()
            ->timeout(5)
            ->post(
                sprintf(
                    'https://graph.facebook.com/%s/%s/messages',
                    $config['api_version'],
                    $config['phone_number_id']
                ),
                [
                    'messaging_product' => 'whatsapp',
                    'to' => $toPhone,
                    'type' => 'template',
                    'template' => $template,
                ]
            )
            ->throw();
    }
}
