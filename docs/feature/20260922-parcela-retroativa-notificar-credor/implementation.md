# Implementation — Notificar credor quando parcela retroativa nasce paga em seu nome

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260922

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum. Branch da feature `backend/20260922-parcela-retroativa-notificar-credor`, criada a partir de `dev` atualizada.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-341 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`) | `./vendor/bin/pint app/Support/Notifier.php` — sem alteração necessária; `./vendor/bin/pint --test app/Support/Notifier.php` — PASS | `Notifier::expenseBornPaid()` adicionado, mesmo padrão `guard()`/`fanOut()` |
| TASK-342 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`) | `./vendor/bin/pint app/Http/Controllers/ExpenseController.php` — sem alteração necessária; `./vendor/bin/pint --test ...` — PASS | `store()` conta `$bornPaidCount` no loop de quotas e chama `Notifier::expenseBornPaid()` quando > 0 |
| TASK-343 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`) | `php artisan test --filter=NotifierTriggersTest` — 16 passed (71 assertions); prova de regressão: `git stash` de `Notifier.php`+`ExpenseController.php` + re-rodar o filtro → `test_expense_born_paid_notifies_the_creditor_when_someone_else_registers_it` `FAIL` (1 failed, 15 passed); `git stash pop` restaurou o fix | 3 casos novos: notifica quando credor≠criador, não notifica quando credor=criador, não notifica quando nenhuma quota nasce `born_paid` |
| — | — | — | — | `php artisan test` (suíte completa) — 369 passed (1220 assertions), 60.49s | Nenhuma regressão em outras suítes |
