# Implementation — Login social via Google

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260922

---

## 1. Desvios do fluxo padrão (se houver)

Pasta da feature reaproveitada de 2026-08-21 (só tinha `specify.md`, nunca chegou a `plan.md`/`tasks.md`/código — ver nota em `specify.md` §1). Branch da feature `backend/20260821-login-social-google` (nome derivado da pasta, não da data de início da execução), criada a partir de `dev` atualizada em 2026-09-22.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-357 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`) | `./vendor/bin/pint --test app/Http/Controllers/GoogleAuthController.php routes/api.php` — PASS, 2 files; `php artisan route:list --path=auth/google` — mostra `GET api/auth/google/login -> GoogleAuthController@loginRedirect` ao lado do `callback` já existente | Rota pública nova + `loginRedirect()`: gera state opaco (`Str::random(40)`) com `intent=login` no mesmo cache/prefixo/TTL que `redirectUrl()` já usa, e devolve `redirect()->away(...)` em vez de JSON (usuário não autenticado, sem XHR prévia) |
| TASK-358 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`) | `php artisan test --filter=GoogleAuthControllerTest` — 9 passed (29 assertions), incluindo o teste novo e os 8 já existentes sem regressão; `./vendor/bin/pint --test tests/Feature/GoogleAuthControllerTest.php` — PASS, 1 file | Novo teste `test_login_redirect_sends_the_user_to_google_with_opaque_login_state`: bate em `GET /api/auth/google/login`, confere `302` para `accounts.google.com`, e que o `state` gravado no cache tem `intent=login` sem `user_id` (diferente do state de `link`) |
| TASK-359 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`) | `./vendor/bin/pint --test app/Http/Controllers/GoogleAuthController.php` — PASS, 1 file; `php artisan test --filter=GoogleAuthControllerTest` — 8 passed, **1 failed esperado** (`callback returns 501 when intent is not link`, que usa `intent: 'login'` para testar o 501 — vira `302` porque `login` agora é um intent válido; corrigido na TASK-360, não aqui) | `callback()` ganha branch `intent === 'login'` → `handleLoginCallback()` (método privado novo): acha por `google_id`, senão por `email` (auto-vínculo), senão cria via `new User([...])` + atribuição direta de `google_id`/`avatar_url`/`email_verified_at` antes de `save()` — **não** `User::create([...'google_id'...])`, porque `google_id`/`avatar_url` não estão em `User::$fillable` e seriam descartados silenciosamente na mass assignment (conferido: sem `preventSilentlyDiscardingAttributes` no projeto, o `create()` não erraria, só perderia os campos). Emite JWT via `auth('api')->login($user)` e gera código de troca de uso único (`google_login_code:`, TTL 1 min) em vez de devolver o token na URL. Desvio do `plan.md` §1: o fetch do Socialite **não** foi extraído para fora do `if (intent === 'link')` como o plano descrevia — ficou duplicado (uma chamada própria em `handleLoginCallback`), pra não mexer no branch `link` já testado; comportamento final é o mesmo, é só estrutura interna |
