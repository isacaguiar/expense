# Implementation — E-mail verificado obrigatório

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260922

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum. Branch da feature `backend/20260922-email-verificado-obrigatorio`, criada a partir de `dev` atualizada (`origin/dev` já estava em dia no momento da criação).

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-336 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`) | `./vendor/bin/pint app/Http/Controllers/AuthController.php` — 1 arquivo, fixed (unused imports `User`/`Hash`, unary operator spacing pré-existente); `./vendor/bin/pint --test app/Http/Controllers/AuthController.php` — PASS | `register()` agora só responde `410` com mensagem apontando pro `/api/pre-register`; nenhum client conhecido chamando essa rota hoje (confirmado: sem match de `/api/register` em `frontend/src`) |
| TASK-337 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`) | `php artisan test --filter=AuthControllerRegisterDeprecatedTest` — 1 passed (3 assertions); prova de regressão: `git stash` só do `AuthController.php` + re-rodar o filtro → `FAIL`, "Expected response status code [410] but received 201" — confirma que o teste pega a regressão; `git stash pop` restaurou o fix | — |
| — | — | — | — | `php artisan test` (suíte completa, depois de TASK-336+337) — 367 passed (1211 assertions), 58.84s | Nenhuma regressão em outras suítes |
| TASK-338 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`) | `./vendor/bin/pint app/Http/Controllers/AuthController.php` — sem alteração necessária; `./vendor/bin/pint --test app/Http/Controllers/AuthController.php` — PASS | `login()` chama `Auth::guard('api')->logout()` e responde `403` quando `email_verified_at` é nulo, antes de `respondWithToken()` |
| TASK-339 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`) | `php artisan test --filter=AuthControllerLoginRequiresVerifiedEmailTest` — 2 passed (4 assertions); `php artisan test --filter=AuthControllerLoginLogTest` (suíte existente) — 2 passed, sem regressão; prova de regressão: `git stash` só do `AuthController.php` + re-rodar o filtro → caso "unverified" `FAIL` ("Expected response status code [403] but received 200"), caso "verified" continuou `PASS`; `git stash pop` restaurou o fix | — |
| — | — | — | — | `php artisan test` (suíte completa, depois de TASK-338+339) — 369 passed (1215 assertions), 41.88s | Nenhuma regressão em outras suítes |
| TASK-340 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`, agent `security-reviewer`) | `git diff dev...HEAD` revisado pelo agent — sem achado bloqueante. Confirmado: (1) checagem de `email_verified_at` só é alcançada depois do `attempt()` validar a senha, não abre oráculo de enumeração novo; (2) `Auth::guard('api')->logout()` de fato invalida o token via blacklist do `tymon/jwt-auth` (`JWT_BLACKLIST_ENABLED=true` por default, confirmado em `config/jwt.php:220` e ausência de override em `.env`); (3) `410` de `register()` não faz query nem varia por payload | 2 achados informativos (não bloqueantes) viraram itens de backlog: [058](../../backlog/auth-login-logout-acoplado-jwt-blacklist-enabled.md) (acoplamento não testado a `JWT_BLACKLIST_ENABLED`) e [059](../../backlog/constitution-log-debug-credenciais-desatualizado.md) (`00-constitution.md` §5 item 3 desatualizado) |
