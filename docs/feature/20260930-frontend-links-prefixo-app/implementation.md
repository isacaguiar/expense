# Implementation — Links gerados pelo backend sem o prefixo /app do frontend

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260930

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum. Branch da feature `backend/20260930-frontend-links-prefixo-app`, criada a partir de `dev` atualizada (`origin/dev` já estava em dia no momento da criação, incluindo o merge do PR #201).

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-371 | Concluída | 2026-09-30 | Claude (via `/nova-feature`) | `./vendor/bin/pint --test config/services.php` — PASS; `php artisan tinker --execute="echo config('services.frontend_app_url');"` → `https://expense-app.novemax.com.br/app` (local `.env`, confirma o `/app` sendo anexado corretamente) | `frontend_url` mantido intocado, só a chave nova foi adicionada |
| TASK-372 | Concluída | 2026-09-30 | Claude (via `/nova-feature`) | `./vendor/bin/pint --test app/Http/Controllers/InvitationController.php` — PASS | — |
| TASK-373 | Concluída | 2026-09-30 | Claude (via `/nova-feature`) | `php artisan test --filter=InvitationControllerForgotPasswordTest` — 5 passed (21 assertions); prova de regressão: `git stash push -- app/Http/Controllers/InvitationController.php` + rerun do teste único → `FAIL` ("Expected response status code [200] but received 500", mock do `Mail::send` deixa de bater porque o link não começa mais com `frontend_app_url`); `git stash pop` restaurou o fix | — |
| TASK-374 | Concluída | 2026-09-30 | Claude (via `/nova-feature`) | `./vendor/bin/pint --test app/Mail/UserInvitedMail.php` — PASS | — |
| TASK-375 | Concluída | 2026-09-30 | Claude (via `/nova-feature`) | `php artisan test --filter=GroupMemberInvitationMailTest` — 3 passed (3 assertions); prova de regressão: `git stash push -- app/Mail/UserInvitedMail.php` + rerun → `FAIL` ("The expected [App\Mail\UserInvitedMail] mailable was not sent" — `Mail::fake()`/assertSent com o callback de verificação do link falhando); `git stash pop` restaurou o fix | — |
| TASK-376 | Concluída | 2026-09-30 | Claude (via `/nova-feature`) | `./vendor/bin/pint --test app/Http/Controllers/GoogleAuthController.php` — PASS | `$frontendUrl` (linha 74) agora vem de `frontend_app_url`; os 5 redirects de `/profile?linked=...` herdam a base nova sem mudança de path |
| TASK-377 | Concluída | 2026-09-30 | Claude (via `/nova-feature`) | (mesmo arquivo da TASK-376) | os 4 redirects de retorno de login perderam o segmento `/login` (`"{$frontendUrl}?google_error=1"` / `"{$frontendUrl}?google_code={$code}"`) |
| TASK-378 | Concluída | 2026-09-30 | Claude (via `/nova-feature`) | `php artisan test --filter=GoogleAuthControllerTest` — 17 passed (52 assertions); prova de regressão: `git stash push -- app/Http/Controllers/GoogleAuthController.php` + rerun da suíte completa do arquivo → 8 de 17 testes falham (todos os que dependem da base/paths corrigidos); `git stash pop` restaurou o fix | 10 ocorrências de `config(['services.frontend_url' => ...])` trocadas (a estimativa de "9" em `tasks.md` era de uma contagem manual; a real é 10) |
| — | — | 2026-09-30 | Claude | `php artisan test` (suíte completa) — 383 passed (1263 assertions), 48.03s | Nenhuma regressão em outras suítes |
| — | — | 2026-09-30 | Claude | `./vendor/bin/pint --test` (projeto completo) — PASS, 163 files | — |
| TASK-379 | Concluída | 2026-09-30 | Claude (agent `security-reviewer`) | Diff completo (working tree) revisado — nenhum achado bloqueante | Confirmado: (a) `frontend_app_url` deriva só de `env('FRONTEND_URL')`, nenhum input de request, sem open-redirect novo; (b) remover `/login` é cosmético, nenhuma branch de decisão depende do path; (c) checagens de `email_verified`/`google_id`/auto-vínculo em `GoogleAuthController` e o fluxo de `forgotPassword` ficaram byte-a-byte iguais ao `dev`. `frontend_url` (sem `/app`) continua em uso só em `cors.php`, de propósito. 2 observações informativas, não-bloqueantes, sem ação necessária (token de reset/convite em query string — já é padrão aceito em `00-constitution.md` §6 item 3, não é fluxo novo) |
