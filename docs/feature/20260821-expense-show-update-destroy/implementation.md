# Implementation — Expense show/update/destroy

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260821

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-126 | Implementada na branch da feature | 2026-08-21 | IA (Claude Code) | `cd backend && ./vendor/bin/pint --test` — 9 issues pré-existentes fora de `ExpenseController.php`/testes novos, nenhum introduzido; `php artisan test --filter=ExpenseController` — 33 passed (90 assertions), incluindo as 3 novas em `ExpenseControllerShowUpdateDestroyTest` (`member can view expense`, `non member cannot view expense`, `deleted expense returns 404`) | Adiciona `ExpenseController::show` e o helper privado `findExpenseForMember` (busca despesa não deletada + `authorizeGroupMembership`), reaproveitado por `update`/`destroy` nas próximas tasks |
| TASK-127 | Implementada na branch da feature | 2026-08-21 | IA (Claude Code) | `cd backend && ./vendor/bin/pint --test app/Http/Controllers/ExpenseController.php tests/Feature/ExpenseControllerShowUpdateDestroyTest.php` — PASS; `php artisan test --filter=ExpenseController` — 39 passed (101 assertions), incluindo as 6 novas de `update` (não-membro 404, membro sem ser criador/pagador 403 sem alterar dado, criador 200, pagador 200, `expense_type`/`installments`/`quotas` ignorados, `payers` substituído via `sync`) | Adiciona `ExpenseController::update` e o helper `authorizeExpenseOwner` (criador OU pagador, senão 403); reaproveita `findExpenseForMember` da TASK-126 |
