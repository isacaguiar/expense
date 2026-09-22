# Tasks — E-mail verificado obrigatório

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260922

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-336 | Depreciar `POST /register` (responder 410, parar de criar `User`) | backend | plan.md §1 | nenhum | Concluída |
| TASK-337 | Teste que prova que `POST /register` responde 410 e não cria conta | backend | plan.md §1 | nenhum | Concluída |
| TASK-338 | Gatear `login()` por `email_verified_at` nulo (403 + invalida token + mensagem) | backend | plan.md §2 | nenhum | Concluída |
| TASK-339 | Teste que prova login recusado para conta não verificada e mantido para conta verificada | backend | plan.md §2 | nenhum | Concluída |
| TASK-340 | Revisão de segurança (`security-reviewer`) antes do PR | doc | plan.md §1, §2 | antes do merge | Pendente |

## Critérios de aceite

- **TASK-336**: `AuthController::register()` (`backend/app/Http/Controllers/AuthController.php:13-29`) não chama mais `User::create()` e responde `410` com corpo `{"message": "Este endpoint foi descontinuado. Use /api/pre-register."}` para qualquer request, sem validar o payload antes. Rota `backend/routes/api.php:18` continua registrada, sem mudança. `./vendor/bin/pint --test app/Http/Controllers/AuthController.php` limpo.

- **TASK-337**: novo teste de feature (`backend/tests/Feature/AuthControllerRegisterDeprecatedTest.php`) que faz `postJson('/api/register', [...])` com payload válido e afirma `assertStatus(410)` e que `User::count()` não mudou antes/depois da chamada. `php artisan test --filter=AuthControllerRegisterDeprecatedTest` verde. Reverter TASK-336 localmente faz este teste falhar.

- **TASK-338**: em `AuthController::login()` (`:31-55`), depois de `Auth::guard('api')->attempt($credentials)` ter sucesso, se `Auth::guard('api')->user()->email_verified_at` for nulo: chama `Auth::guard('api')->logout()`, loga `Log::warning` (mesmo padrão de `:44`, incluindo o e-mail) e responde `403` com `{"error": "E-mail não verificado. Use \"Esqueci minha senha\" para confirmar seu e-mail e definir uma nova senha."}` — sem chamar `respondWithToken()`. Login de conta com `email_verified_at` preenchido não muda de comportamento. `./vendor/bin/pint --test app/Http/Controllers/AuthController.php` limpo.

- **TASK-339**: novo teste de feature (`backend/tests/Feature/AuthControllerLoginRequiresVerifiedEmailTest.php`) com dois casos: (1) `User::factory()->unverified()->create()` + credenciais corretas → `postJson('/api/login', ...)` retorna `403` e o corpo não contém `access_token`; (2) `User::factory()->create()` (verificado, default da factory) + credenciais corretas → continua `200` com `access_token` presente. `php artisan test --filter=AuthControllerLoginRequiresVerifiedEmailTest` verde. `php artisan test --filter=AuthControllerLoginLogTest` (suíte existente) continua verde, sem alteração. Reverter TASK-338 localmente faz o caso (1) falhar.

- **TASK-340**: agent `security-reviewer` executado sobre o diff final de `AuthController.php` antes de abrir o PR, sem achado bloqueante pendente — achado não-bloqueante vira item de backlog, não trava esta feature.
