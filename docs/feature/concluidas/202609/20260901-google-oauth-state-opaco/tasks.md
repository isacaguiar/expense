# Tasks — OAuth Google com state opaco

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260901

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-235 | Reescrever `GoogleAuthController` (`redirectUrl`/`callback`) para `state` opaco — `Str::random(40)` + contexto em `Cache` com uso único — e atualizar `GoogleAuthControllerTest` ao novo mecanismo | backend | plan.md §1, §2, §3 | antes do merge (toca auth — `security-reviewer`) | Pendente |

## Critérios de aceite

- **TASK-235**:
  - `backend/app/Http/Controllers/GoogleAuthController.php` não importa nem usa mais `Illuminate\Support\Facades\Crypt`; o método privado `decodeState()` foi removido.
  - `redirectUrl()` gera `state` com `Str::random(40)` e grava `['intent' => 'link', 'user_id' => $user->id]` em `Cache` na chave `google_oauth_state:<token>` com TTL de 5 min (constantes `STATE_CACHE_PREFIX` / `STATE_TTL_MINUTES`). Resposta continua `{ "url": "..." }`.
  - `callback()` lê `state` da query, faz `Cache::pull` (get + forget). `state` vazio / desconhecido / já consumido → `redirect(...profile?linked=error)`; `intent !== 'link'` → `501`; daí pra frente (Socialite `stateless()->user()`, set `google_id`/`avatar_url`, `save()` com `catch(QueryException) → linked=error`, sucesso → `linked=success`) inalterado.
  - `cd backend && ./vendor/bin/pint --test` limpo nos arquivos tocados.
  - `cd backend && php artisan test --filter=GoogleAuthControllerTest` verde, com:
    - o teste de `redirect-url` assertando `preg_match('/^[A-Za-z0-9]{40}$/', $state)` e o conteúdo via `Cache::get`;
    - **novo** `test_callback_state_is_single_use` — 1ª chamada `linked=success`, 2ª com o mesmo token → `linked=error`;
    - o antigo teste de "expired state" reescrito como "unknown state" (token de 40 chars nunca gravado).
  - `cd backend && php artisan test` verde (suíte inteira, sem regressão).
  - **Verificação pós-deploy** (registrar em `implementation.md` §2): em produção, `GET /api/user/google/redirect-url` autenticado devolve `url` com `state` de 40 chars alfanuméricos; o fluxo de vincular Google completa **sem 406 do Mod_Security** e retorna a `.../profile?linked=success`.
