# Tasks — Limite de grupos com fonte única

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260922

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-349 | `GET /api/me` passa a incluir `max_groups_per_user` | backend | plan.md §1 | nenhum | Concluída |
| TASK-350 | Teste que prova que `/api/me` expõe `max_groups_per_user` sem quebrar os campos existentes | backend | plan.md §1 | nenhum | Concluída |
| TASK-351 | `Dashboard.tsx` consome `max_groups_per_user` da API em vez de constante hardcoded | frontend | plan.md §2 | nenhum | Concluída |
| TASK-352 | Teste que prova o botão de criar grupo reagindo ao limite vindo da API (e não travando antes de carregar) | frontend | plan.md §2 | nenhum | Concluída |
| TASK-353 | Revisão de segurança (`security-reviewer`) antes do PR | doc | plan.md §1 | antes do merge | Concluída |

## Critérios de aceite

- **TASK-349**: `AuthController::me()` (`backend/app/Http/Controllers/AuthController.php:53-55`) devolve `array_merge(auth('api')->user()->toArray(), ['max_groups_per_user' => GroupController::MAX_GROUPS_CREATED_PER_USER])` — todos os campos que a resposta já tinha (`id`, `name`, `email`, `avatar_url`, etc.) continuam presentes, mais o campo novo igual à constante de `GroupController` (hoje `3`). `./vendor/bin/pint --test app/Http/Controllers/AuthController.php` limpo.

- **TASK-350**: novo teste de feature (`backend/tests/Feature/AuthControllerMeExposesGroupLimitTest.php`) que autentica um usuário, chama `getJson('/api/me')` e afirma `assertJsonPath('max_groups_per_user', \App\Http\Controllers\GroupController::MAX_GROUPS_CREATED_PER_USER)` e `assertJsonPath('id', $user->id)` (prova que o campo novo não substituiu os antigos). `php artisan test --filter=AuthControllerMeExposesGroupLimitTest` verde. Rodar também `php artisan test --filter=UserPhotoTest` e `php artisan test --filter=PreRegisterControllerTest` (suítes existentes que já chamam `/api/me`) e confirmar que continuam verdes sem alteração.

- **TASK-351**: `frontend/src/pages/Dashboard.tsx` não declara mais `const MAX_GROUPS_CREATED_PER_USER = 3`. Novo estado `maxGroupsPerUser` (`number | null`, inicial `null`) é populado a partir de `res.data.max_groups_per_user` na mesma chamada a `/api/me` que já popula `currentUserId`. `reachedCreationLimit` e o texto do `Tooltip` do botão "Criar grupo" passam a usar esse estado em vez da constante removida. `npx tsc --noEmit` limpo.

- **TASK-352**: em `frontend/src/pages/Dashboard.test.tsx`: (1) um teste mocka `/api/me` com `max_groups_per_user: 2` e 2 grupos criados pelo usuário atual, e afirma que o botão "Criar grupo" está desabilitado (`getByRole('button', { name: /criar grupo/i })` com `disabled`); (2) um teste em que a resposta de `/api/me` ainda não chegou (promise pendente) afirma que o botão **não** está desabilitado nesse meio-tempo. `npx vitest run src/pages/Dashboard.test.tsx` verde. Reverter TASK-351 localmente faz o teste (1) falhar (a constante hardcoded volta a valer `3`, não `2`).

- **TASK-353**: agent `security-reviewer` executado sobre o diff final de `AuthController.php` antes de abrir o PR, sem achado bloqueante pendente — achado não-bloqueante vira item de backlog, não trava esta feature.
