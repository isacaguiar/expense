# Implementation — <Nome da Feature>

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: <AAAAMMDD>

---

## 1. Desvios do fluxo padrão (se houver)

TASK-124 não estava no `tasks.md` aprovado originalmente — foi descoberta ao rodar o teste automatizado da TASK-122 (`QueryException: Table 'ex-db.users' doesn't exist`) e adicionada como task nova nesta mesma feature, com aprovação explícita do usuário, antes de ser implementada (ver `plan.md` §6). Implementada em sub-branch própria (`backend/20260821-recuperacao-senha-login-TASK-124`), mergeada localmente na branch da feature — mesmo fluxo do `04-implementation.md` §1, sem desvio de processo.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-122 | Concluída | 20260821 | IA | `./vendor/bin/pint --test app/Http/Controllers/InvitationController.php tests/Feature/InvitationControllerForgotPasswordTest.php` — PASS, 2 files (após `./vendor/bin/pint` sem `--test` corrigir 2 issues de estilo pré-existentes/novas). `php artisan test --filter=InvitationControllerForgotPasswordTest` — 4 passed (19 assertions). `php artisan test` (suíte completa) — 83 passed (205 assertions, já incluindo TASK-124). | Só foi possível confirmar os 4 testes verdes depois de integrar TASK-124 (descoberta durante esta task) — antes disso, `POST /api/forgot-password` retornava 500 por `QueryException` antes mesmo de alcançar o código novo. `Mail::shouldReceive('send')` (Mockery) usado em vez de `Mail::fake()` porque o app chama `Mail::send($view,...)` bruto (não `Mailable`), e `Mail::fake()` não intercepta/assert esse formato de chamada de forma confiável. |
| TASK-124 | Concluída | 20260821 | IA | `./vendor/bin/pint --test app/Http/Controllers/InvitationController.php` — PASS, 1 file. `php artisan test --filter=InvitationController` — 7 passed (22 assertions), incluindo os 3 testes novos de regressão (`InvitationControllerValidationTest.php`: e-mail inexistente/já cadastrado retorna 422, não 500). `php artisan test` (suíte completa) — 83 passed (205 assertions). | Corrigiu `unique:users`→`unique:ex_users` (linha 19) e `exists:users`→`exists:ex_users` (linhas 49 e 91) nos 3 métodos do `InvitationController`. Implementada em sub-branch `backend/20260821-recuperacao-senha-login-TASK-124`, mergeada localmente (`git merge --no-ff`) na branch da feature `backend/20260821-recuperacao-senha-login`; sub-branch descartada após o merge. |
