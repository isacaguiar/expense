# Tasks — Links gerados pelo backend sem o prefixo /app do frontend

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs a partir de `TASK-371` — maior ID já usado no projeto antes desta feature: `TASK-370` (`docs/feature/20260821-login-social-google/tasks.md`).

Versão: 1.0 · Criado em: 20260930

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-371 | Adicionar `services.frontend_app_url` em `config/services.php` | backend | plan.md §1 | nenhum | Concluída |
| TASK-372 | `InvitationController::forgotPassword` usa `frontend_app_url` no `$resetLink` | backend | plan.md §2 | nenhum | Concluída |
| TASK-373 | Atualizar `InvitationControllerForgotPasswordTest` para esperar `/app` no `resetLink` | backend | plan.md §4 | nenhum | Concluída |
| TASK-374 | `UserInvitedMail` usa `frontend_app_url` no `$activationLink` | backend | plan.md §2 | nenhum | Concluída |
| TASK-375 | Atualizar `GroupMemberInvitationMailTest` para esperar `/app` no `activationLink` | backend | plan.md §4 | nenhum | Concluída |
| TASK-376 | `GoogleAuthController` usa `frontend_app_url` nos redirects de `/profile?linked=...` | backend | plan.md §3 (itens 1-2) | nenhum | Concluída |
| TASK-377 | `GoogleAuthController` corrige os redirects de retorno de login: `frontend_app_url` e remove o segmento `/login` inexistente | backend | plan.md §3 (item 3) | nenhum | Concluída |
| TASK-378 | Atualizar `GoogleAuthControllerTest` para fixar `frontend_app_url` e esperar as novas URLs (`/profile` com `/app`, retorno de login sem `/login`) | backend | plan.md §4 | nenhum | Concluída |
| TASK-379 | Revisão de segurança (`security-reviewer`) antes do PR | doc | plan.md §2, §3 | antes do merge | Concluída |

## Critérios de aceite

- **TASK-371**: `backend/config/services.php` ganha a chave `'frontend_app_url' => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/').'/app'`, ao lado de `frontend_url` (não a substitui). Com `.env` padrão (`FRONTEND_URL` não setada), `config('services.frontend_app_url')` resolve `http://localhost:3000/app`. `./vendor/bin/pint --test config/services.php` limpo.

- **TASK-372**: `backend/app/Http/Controllers/InvitationController.php:84` passa a montar `$resetLink` com `config('services.frontend_app_url')` em vez de `config('services.frontend_url')`. Nenhuma outra linha do método muda (token, cache, rate limit, envio). `./vendor/bin/pint --test app/Http/Controllers/InvitationController.php` limpo.

- **TASK-373**: em `backend/tests/Feature/InvitationControllerForgotPasswordTest.php`, o teste `test_reset_link_points_to_the_frontend_domain` passa a afirmar `str_starts_with($data['resetLink'], config('services.frontend_app_url'))` (em vez de `frontend_url`) e continua afirmando que não começa com `config('app.url')`. `php artisan test --filter=InvitationControllerForgotPasswordTest` verde. Reverter TASK-372 localmente faz este teste falhar (o link volta a apontar para a URL sem `/app`, que não começa mais com `frontend_app_url`).

- **TASK-374**: `backend/app/Mail/UserInvitedMail.php:33` passa a montar `$activationLink` com `config('services.frontend_app_url')` em vez de `config('services.frontend_url')`. Nenhuma outra linha do mailable muda. `./vendor/bin/pint --test app/Mail/UserInvitedMail.php` limpo.

- **TASK-375**: em `backend/tests/Feature/GroupMemberInvitationMailTest.php`, o teste `test_activation_link_points_to_the_frontend_domain` passa a afirmar `str_starts_with($activationLink, config('services.frontend_app_url'))` (em vez de `frontend_url`). `php artisan test --filter=GroupMemberInvitationMailTest` verde. Reverter TASK-374 localmente faz este teste falhar.

- **TASK-376**: em `backend/app/Http/Controllers/GoogleAuthController.php`, `$frontendUrl` (linha 74) passa a vir de `config('services.frontend_app_url')`; os redirects `"{$frontendUrl}/profile?linked=error"` (linhas 81, 101, 112, 128) e `"{$frontendUrl}/profile?linked=success"` (linha 133) continuam com o path `/profile` inalterado — só a base muda. `./vendor/bin/pint --test app/Http/Controllers/GoogleAuthController.php` limpo.

- **TASK-377**: no mesmo arquivo, os redirects `"{$frontendUrl}/login?google_error=1"` (linhas 171, 187, 216) e `"{$frontendUrl}/login?google_code={$code}"` (linha 226) perdem o segmento `/login` — viram `"{$frontendUrl}?google_error=1"` e `"{$frontendUrl}?google_code={$code}"`. `handleLoginCallback` continua recebendo `$frontendUrl` por parâmetro, sem mudança de assinatura.

- **TASK-378**: em `backend/tests/Feature/GoogleAuthControllerTest.php`, as 9 ocorrências de `config(['services.frontend_url' => 'http://localhost:3000'])` nos testes de `link`/`callback`/`handleLoginCallback` passam a ser `config(['services.frontend_app_url' => 'http://localhost:3000/app'])`; as asserções de redirect passam a esperar `http://localhost:3000/app/profile?linked=...` e `http://localhost:3000/app?google_error=1` / `http://localhost:3000/app?google_code=...` (regex da linha 224 ajustada para `#^http://localhost:3000/app\?google_code=[A-Za-z0-9]{40}$#`, sem `/login`). `php artisan test --filter=GoogleAuthControllerTest` verde. Reverter TASK-376 ou TASK-377 localmente faz os testes correspondentes falhar.

- **TASK-379**: agent `security-reviewer` executado sobre o diff final de `GoogleAuthController.php` e `InvitationController.php` antes de abrir o PR, sem achado bloqueante pendente — achado não-bloqueante vira item de backlog, não trava esta feature.
