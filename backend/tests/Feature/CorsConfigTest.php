<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsConfigTest extends TestCase
{
    /**
     * `config/cors.php` precisa incluir a origem do frontend de produção
     * (`FRONTEND_URL`, gravada no `.env` pelo `deploy-backend.yml`) em
     * `allowed_origins` — senão o preflight de `expense.novemax.com.br`
     * bate na API e é bloqueado. Ver
     * `docs/bugfix/concluidos/202608/20260829-cors-nao-le-frontend-url.md`.
     */
    public function test_frontend_url_env_is_an_allowed_cors_origin(): void
    {
        $origin = 'https://cors-test.example';

        $_SERVER['FRONTEND_URL'] = $origin;
        $_ENV['FRONTEND_URL'] = $origin;
        putenv("FRONTEND_URL={$origin}");

        try {
            $cors = require base_path('config/cors.php');

            $this->assertContains($origin, $cors['allowed_origins']);
        } finally {
            unset($_SERVER['FRONTEND_URL'], $_ENV['FRONTEND_URL']);
            putenv('FRONTEND_URL');
        }
    }

    public function test_frontend_network_url_env_is_still_an_allowed_cors_origin(): void
    {
        $origin = 'http://192.168.0.10:3000';

        $_SERVER['FRONTEND_NETWORK_URL'] = $origin;
        $_ENV['FRONTEND_NETWORK_URL'] = $origin;
        putenv("FRONTEND_NETWORK_URL={$origin}");

        try {
            $cors = require base_path('config/cors.php');

            $this->assertContains($origin, $cors['allowed_origins']);
        } finally {
            unset($_SERVER['FRONTEND_NETWORK_URL'], $_ENV['FRONTEND_NETWORK_URL']);
            putenv('FRONTEND_NETWORK_URL');
        }
    }
}
