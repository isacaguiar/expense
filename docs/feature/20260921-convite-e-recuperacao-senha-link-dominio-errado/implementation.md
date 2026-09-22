# Implementation — Link de e-mail (convite de grupo e recuperação de senha) aponta pro domínio errado

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260921

---

## 1. Desvios do fluxo padrão (se houver)

- **Scaffold de docs (`specify.md`/`plan.md`/`tasks.md`/`implementation.md` + item de backlog) commitado à parte, direto na branch da feature, sem `TASK-0xx` própria** — esses documentos nasceram do `/nova-feature` e da escrita colaborativa com o usuário, antes de qualquer task de `tasks.md` existir; não fazia sentido inventar uma task só pra isso.
- **Item de backlog renumerado de 057 para 056** durante a execução: o número 057 foi calculado olhando o `docs/backlog/README.md` de uma branch irmã (`fix/20260921-cadastro-codigo-email-nao-chega`, PR #190) que ainda não tinha sido mergeada em `dev` — nela o item 056 (`backend-env-example-gitignored.md`) já existia, mas em `dev` de verdade o próximo livre ainda era 056. Renumerado para não deixar lacuna/depender da ordem de merge dos dois PRs.
- **TASK-328 ficou temporariamente em `git stash`** enquanto o `composer install` local rodava (vendor/ não existia no ambiente) — as tasks de frontend (332-334) foram adiantadas nesse meio-tempo, já que não dependiam do backend. Sem impacto no resultado final; registrado só porque a ordem real de execução divergiu da ordem de `tasks.md`.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-328 | Concluída | 2026-09-21 | Claude | `./vendor/bin/pint --test app/Mail/UserInvitedMail.php` — PASS, 1 file. `php artisan test --filter=GroupMemberInvitationMailTest` — 2 passed. | Implementada direto na branch da feature (primeira task, `ADR-003`). |
| TASK-329 | Concluída | 2026-09-21 | Claude | `./vendor/bin/pint --test app/Http/Controllers/InvitationController.php` — PASS, 1 file. `php artisan test --filter=InvitationControllerForgotPasswordTest` — 4 passed (19 assertions). | Sub-branch `backend/20260921-…-TASK-329`. |
| TASK-330 | Concluída | 2026-09-21 | Claude | `php artisan test --filter=GroupMemberInvitationMailTest` — 3 passed (3 assertions). `./vendor/bin/pint --test tests/Feature/GroupMemberInvitationMailTest.php` — PASS. | Regressão comprovada manualmente: revertendo TASK-328 (`url()` de volta), o teste novo falha (`FAIL ... The expected [UserInvitedMail] mailable was not sent`); restaurada a correção, sem diff residual, teste volta a passar. |
| TASK-331 | Concluída | 2026-09-21 | Claude | `php artisan test --filter=InvitationControllerForgotPasswordTest` — 5 passed (21 assertions). `./vendor/bin/pint --test app/Http/Controllers/InvitationController.php tests/Feature/InvitationControllerForgotPasswordTest.php` — PASS, 2 files. | Regressão comprovada manualmente: revertendo TASK-329, o teste novo falha (500 em vez de 200 — Mockery rejeita a chamada por argumento não bater); restaurada a correção, sem diff residual, teste volta a passar. |
| TASK-332 | Concluída | 2026-09-21 | Claude | `npx tsc --noEmit` — sem erro. `npx vitest run src/pages/AcceptInvitePage.test.tsx` — 3 passed (suíte existente, antes da TASK-334). | Sub-branch `frontend/20260921-…-TASK-332`. |
| TASK-333 | Concluída | 2026-09-21 | Claude | `npx tsc --noEmit` — sem erro. `npx vitest run src/App.test.tsx` — 1 passed. | Comentário do `ConsentBanner` atualizado junto (enumerava as rotas de visitante). |
| TASK-334 | Concluída | 2026-09-21 | Claude | `npx vitest run src/pages/AcceptInvitePage.test.tsx` — 5 passed (3 existentes + 2 novos). `npx tsc --noEmit` — sem erro. | — |
| TASK-335 | Concluída | 2026-09-21 | Claude | Agent `security-reviewer` executado sobre `git diff origin/dev...feature/20260921-convite-e-recuperacao-senha-link-dominio-errado`. | **Nenhum achado bloqueante.** Conferiu especificamente: rota fora de `jwt.auth` (correto, já era assim), IDOR/ownership (não se aplica, nada novo lido/alterado), segredo em log (nenhum novo), token em query string (débito já conhecido em `00-constitution.md` §6 item 3, não introduzido por este diff), e que `FRONTEND_URL` não é input controlável (mesma env já usada em CORS, sem risco de open redirect). |

## 3. Checklist final da branch da feature (`04-implementation.md` §1.5)

Rodado na branch já integrada, com todas as tasks mergeadas (`git merge --no-ff`):

- `cd backend && ./vendor/bin/pint --test` — 8 arquivos com problema de estilo, **todos pré-existentes e fora do escopo** desta feature (`PixPayload.php`, `Expense.php`, `User.php`, 5 migrations antigas — nenhum tocado por nenhuma task daqui). Não corrigidos de passagem (`06-context-backend.md`, "Não corrigir de passagem"). Escopado só aos arquivos tocados (`pint --test app/Mail/UserInvitedMail.php app/Http/Controllers/InvitationController.php tests/Feature/GroupMemberInvitationMailTest.php tests/Feature/InvitationControllerForgotPasswordTest.php`) — PASS, 4 files.
- `cd backend && php artisan test` (suíte completa) — **366 passed (1208 assertions)**.
- `cd frontend && npx tsc --noEmit` — sem erro.
- `cd frontend && npx vitest run` (suíte completa) — **282 passed (43 test files)**.
- Agent `security-reviewer` sobre o diff completo (TASK-335) — nenhum achado bloqueante.

Nenhum segredo novo no diff (`git diff origin/dev...feature/20260921-convite-e-recuperacao-senha-link-dominio-errado` revisado). Sem migration. Sem mudança de contrato de API.
