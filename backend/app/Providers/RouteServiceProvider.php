<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // `GET /me/overview` calcula o ciclo de cada grupo do usuário: limite
        // próprio, por usuário (o `api` acima, com o guard padrão `web`, acaba
        // chaveando por IP). Ver
        // docs/feature/concluidas/202610/20261010-painel-financeiro-pessoal/specify.md §5 (D10).
        //
        // Mesmo declarado depois do `jwt.auth` na rota, o `ThrottleRequests`
        // roda ANTES dele: o `middlewarePriority` do Kernel o ordena à frente
        // do `SubstituteBindings`, e o middleware do Tymon não está na lista.
        // Por isso o limiter resolve o usuário do token por conta própria e
        // não conta quem não tem token válido — o `jwt.auth` devolve 401 logo
        // em seguida, e uma chamada sem token não gasta o limite de ninguém.
        RateLimiter::for('overview', function (Request $request) {
            $userId = $request->user('api')?->id;

            return $userId ? Limit::perMinute(30)->by($userId) : Limit::none();
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
