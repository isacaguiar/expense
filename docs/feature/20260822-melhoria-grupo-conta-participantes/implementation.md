# Implementation — Melhoria em Grupo, Conta e Participantes

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260822

---

## 1. Desvios do fluxo padrão (se houver)

<Deixe vazio/apague esta seção se a feature segue `04-implementation.md` sem exceção.>

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-181 | Implementada na branch da feature | 20260822 | isacaguiar (IA) | `abort_if($group->deleted, 404)` adicionado em `GroupMemberController@store` (`backend/app/Http/Controllers/GroupMemberController.php`) logo após `authorizeMembership`; teste novo `test_cannot_add_member_to_deleted_group` em `backend/tests/Feature/GroupMemberControllerTest.php`. `cd backend && ./vendor/bin/pint --test` — 9 issues pré-existentes em arquivos não tocados por esta task (`PixPayload.php`, `UserInvitedMail.php`, `Expense.php`, `User.php`, migrations antigas), nenhum nos arquivos alterados. `php artisan test --filter=GroupMemberControllerTest` — 3 passed (10 assertions). `php artisan test` (suíte completa) — 170 passed (488 assertions). | Branch `backend/20260822-melhoria-grupo-conta-participantes` criada a partir de `dev` (primeira task da feature, implementada direto nela). Commit `1358c4f9e`. |
| TASK-182 | Implementada na branch da feature | 20260822 | isacaguiar (IA) | `abort_if($group->deleted, 404)` adicionado em `ExpenseController@store` (após `authorizeGroupMembership`, antes da validação de payload), `@close` e `@reopen` (`backend/app/Http/Controllers/ExpenseController.php`); testes novos `test_cannot_create_expense_in_deleted_group` (`ExpenseControllerStoreTest.php`), `test_cannot_close_deleted_group` (`ExpenseControllerCloseTest.php`), `test_cannot_reopen_deleted_group` (`ExpenseControllerReopenTest.php`). `./vendor/bin/pint --test` nos 4 arquivos tocados — PASS, sem issues. `php artisan test --filter="ExpenseControllerStoreTest\|ExpenseControllerCloseTest\|ExpenseControllerReopenTest"` — 33 passed (107 assertions). `php artisan test` (suíte completa) — 173 passed (495 assertions). | Branch de task `backend/20260822-melhoria-grupo-conta-participantes-TASK-182` criada a partir da branch da feature, mergeada de volta com `--no-ff` (sem PR) e removida. Commit da task `614e158bb`, merge sem PR conforme `ADR-003`. |
