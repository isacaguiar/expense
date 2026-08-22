# Implementation — Melhoria da Gestão de Despesas do Grupo

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260821

---

## 1. Desvios do fluxo padrão (se houver)

<Deixe vazio/apague esta seção se a feature segue `04-implementation.md` sem exceção.>

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-149 | Implementada | 2026-08-22 | IA (Claude Code) | `php artisan migrate --path=database/migrations/2026_08_22_120000_add_paid_at_and_paid_by_to_ex_quotas_table.php` — rodou limpo; `php artisan tinker` confirmou colunas `paid_at`/`paid_by` em `ex_quotas`; `./vendor/bin/pint --test app/Models/Quota.php database/migrations/2026_08_22_120000_...php` — PASS (2 files); `php artisan test` (suíte completa) — 114 passed (311 assertions) | Migration aditiva local (coluna `paid_at` timestamp nullable, `paid_by` FK nullable para `ex_users`); model `Quota` ganhou `paid_at`/`paid_by` em `$fillable`/`$casts` e relação `paidBy()` |
| TASK-150 | Implementada | 2026-08-22 | IA (Claude Code) | `./vendor/bin/pint --test app/Http/Controllers/ExpenseController.php` — PASS (1 file); `php artisan test` (suíte completa) — 114 passed (311 assertions), sem regressão | `ExpenseController::store` — removida a regra de validação `quotas.*.paid` (cliente não decide mais o status inicial) e o `paid` de cada `Quota` criada passa a ser hardcoded `false`, ignorando qualquer valor enviado no payload |
| TASK-151 | Implementada | 2026-08-22 | IA (Claude Code) | `./vendor/bin/pint --test tests/Feature/ExpenseControllerStoreTest.php` — PASS (1 file); `php artisan test --filter=ExpenseControllerStoreTest` — 14 passed (32 assertions); `php artisan test` (suíte completa) — 117 passed (319 assertions) | 3 testes novos em `ExpenseControllerStoreTest.php` cobrindo `IN_CASH`, `IN_INSTALLMENTS` (2 quotas) e `FIXED`, todos enviando `paid: true` no payload e confirmando `paid = false` no banco |
