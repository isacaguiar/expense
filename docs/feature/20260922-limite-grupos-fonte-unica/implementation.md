# Implementation — Limite de grupos com fonte única

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260922

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum. Branch da feature `backend/20260922-limite-grupos-fonte-unica`, criada a partir de `dev` atualizada (`git checkout dev && git merge --ff-only origin/dev`, que trouxe 16 commits incluindo as features `email-verificado-obrigatorio`, `parcela-retroativa-notificar-credor` e `expense-view-edicao-data-vazio` já mergeadas).

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-349 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`) | `./vendor/bin/pint --test app/Http/Controllers/AuthController.php tests/Feature/AuthControllerMeExposesGroupLimitTest.php` — PASS, 2 files | `AuthController::me()` passa a devolver `array_merge($user->toArray(), ['max_groups_per_user' => GroupController::MAX_GROUPS_CREATED_PER_USER])`; `GroupController` no mesmo namespace, sem `use` novo |
| TASK-350 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`) | `php artisan test --filter=AuthControllerMeExposesGroupLimitTest` — 1 passed (4 assertions); prova de regressão: `git stash` só do `AuthController.php` + re-rodar o filtro → `FAIL`, "Failed asserting that null is identical to 3" — confirma que o teste pega a regressão; `git stash pop` restaurou o fix e o teste voltou a `PASS` | Rodadas as suítes existentes que já chamam `/api/me`: `php artisan test --filter=UserPhotoTest` — 8 passed (25 assertions); `php artisan test --filter=PreRegisterControllerTest` — 12 passed (57 assertions). Sem regressão |
| TASK-351 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`) | `npx tsc --noEmit` — limpo | `Dashboard.tsx`: removida `const MAX_GROUPS_CREATED_PER_USER = 3`; novo estado `maxGroupsPerUser` (inicial `null`) populado por `res.data.max_groups_per_user` na mesma chamada a `/api/me`; `reachedCreationLimit` e o `Tooltip` passam a usar o estado |
| TASK-352 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`) | `npx vitest run src/pages/Dashboard.test.tsx` — 21 passed (19 existentes + 2 novos); prova de regressão: `git stash` só do `Dashboard.tsx` + re-rodar → teste novo "disables ... using the limit returned by GET /api/me, not a hardcoded 3" `FAIL` (esperava `aria-disabled=true`, recebeu `null`), demais 20 continuaram verdes; `git stash pop` restaurou o fix e voltou a 21/21 | Helper `mockGroupsAndMe` do teste ganhou parâmetro `maxGroupsPerUser` (default `3`, mesmo valor que os testes já assumiam implicitamente); 2 testes novos: limite vindo da API com valor diferente de 3 (prova que não é mais hardcoded) e botão não trava enquanto `/api/me` ainda não respondeu |
