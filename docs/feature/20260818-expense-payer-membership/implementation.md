# Implementation — Checagem de membership em despesas (criação e leitura)

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260818

---

## 1. Desvios do fluxo padrão (se houver)

Feature segue `04-implementation.md`/`ADR-003` sem exceção (branch única da feature, tasks seguintes mergeadas nela localmente sem PR, PR único no final contra `dev`).

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-039 | Implementada, integrada na branch da feature | 2026-08-18 | IA (Claude Code) | `./vendor/bin/pint app/Http/Controllers/ExpenseController.php tests/Feature/ExpenseControllerGetMonthlyExpensesTest.php` — FIXED, 2 files, limpo depois. `php artisan test --filter=ExpenseControllerGetMonthlyExpensesTest` — 2/2 verde. `php artisan test` (suíte completa) — 23/23 verde. | Branch `backend/20260818-expense-payer-membership` (primeira task, direto na branch da feature, sem sub-branch). |
| TASK-040 | Implementada, integrada na branch da feature | 2026-08-18 | IA (Claude Code) | `./vendor/bin/pint app/Http/Controllers/GroupExpenseReportController.php tests/Feature/GroupExpenseReportControllerTest.php` — FIXED, 2 files, limpo depois. `php artisan test --filter=GroupExpenseReportControllerTest` — 4/4 verde. `php artisan test` (suíte completa) — 27/27 verde. | Sub-branch `backend/20260818-expense-payer-membership-TASK-040`, criada a partir da branch da feature; merge local `--no-ff` de volta nela, sem PR (ADR-003). |
| TASK-041 | Implementada, integrada na branch da feature | 2026-08-18 | IA (Claude Code) | `./vendor/bin/pint app/Http/Controllers/ExpenseController.php tests/Feature/ExpenseControllerStoreTest.php` — PASS, limpo. `php artisan test --filter=ExpenseControllerStoreTest` — 5/5 verde. `php artisan test` (suíte completa) — 29/29 verde. | Sub-branch `backend/20260818-expense-payer-membership-TASK-041`. Desvio pequeno do `plan.md` §1 descoberto ao rodar o teste pré-existente `test_non_member_cannot_create_expense_in_group`: a nova regra `Rule::exists('ex_groups_members', ...)` para `user_payer_id`/`payers` rodava dentro do mesmo `$request->validate()` que já incluía `group_id`, e o `validate()` inteiro acontecia **antes** de `authorizeGroupMembership()` — não-membro recebia `422` (falha na regra do payer) em vez do `404` esperado (falha de membership do próprio chamador), quebrando o teste existente. Corrigido dividindo `store()` em duas chamadas de `validate()`: primeiro só `group_id` (`required\|exists:ex_groups,id`), depois `Group::findOrFail` + `authorizeGroupMembership`, e só então o restante das regras (incluindo `user_payer_id`/`payers`) — preserva a precondição assumida no `plan.md` §1 ("quem chama já é membro confirmado antes desse ponto"). Sem mudança de escopo, só correção de ordem dentro da própria task. |
