<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Constitution §2.4: ao registrar rota (`apiResource` ou manual), todos os métodos
 * referenciados devem existir. `Route::apiResource('expenses', ...)` registrava
 * `GET /api/expenses` → `ExpenseController@index`, que não existe, e a chamada dava 500.
 * Ver `docs/bugfix/20261103-get-expenses-sem-index-no-controller.md` (item 132 do backlog).
 *
 * Não usa banco: só inspeciona as rotas registradas e o roteador.
 */
class RoutesPointToExistingControllerMethodsTest extends TestCase
{
    public function test_every_application_route_points_to_an_existing_controller_method(): void
    {
        $broken = [];
        $checked = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $action = $route->getActionName();

            // Fora do app (ex.: rota do Sanctum, classe em `vendor/`) e closures não entram.
            if (! str_starts_with($action, 'App\\Http\\Controllers\\') || ! str_contains($action, '@')) {
                continue;
            }

            $checked++;
            [$class, $method] = explode('@', $action, 2);

            if (! method_exists($class, $method)) {
                $broken[] = implode('|', $route->methods()).' '.$route->uri().' -> '.$action;
            }
        }

        $this->assertGreaterThan(0, $checked, 'nenhuma rota de controller do app foi inspecionada');
        $this->assertSame([], $broken, "Rotas registradas para um método que não existe:\n".implode("\n", $broken));
    }

    public function test_listing_all_expenses_is_not_a_route_and_answers_405_instead_of_500(): void
    {
        // Sem token de propósito: com `GET /api/expenses` registrado a rota casaria e o `jwt.auth`
        // responderia 401; sem ela o roteador responde 405, porque o mesmo URI aceita `POST` (`store`).
        $this->getJson('/api/expenses')->assertStatus(405);
    }
}
