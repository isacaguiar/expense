# Implementation — Despesa parcelada retroativa

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260903

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum — segue `docs/sdd/04-implementation.md` sem exceção. TASK-001 é a primeira
(e única) task, implementada direto na branch da feature
`feature/20260903-despesa-parcelada-retroativa`.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-001 | Concluída | 2026-09-03 | Claude (IA) | Ver detalhamento abaixo | `store()` + 3 testes novos; TDD (RED→GREEN) |

### TASK-001 — `store()` aceita Parcelada retroativa

Mudança: `backend/app/Http/Controllers/ExpenseController.php` — `store()`:
1. Ramo por `expense_type`: `IN_INSTALLMENTS` não passa mais por
   `rejectIfCompetenceClosed($group, date_payment)`; recusa 422 só se **toda**
   parcela cair em ciclo `closed` por data (`BillingCycle::statusFor`), com
   mensagem própria. `IN_CASH`/`FIXED` inalterados.
2. Laço de criação das quotas: parcela de `IN_INSTALLMENTS` cujo `date_expected`
   cai em ciclo `closed` nasce `paid = true`, `paid_at = now()`,
   `paid_by = user_payer_id`. Demais parcelas e demais tipos: `paid = false`.

Sem migration, sem endpoint novo, sem tocar `collectCycleEntries` /
`computeCycleSummary`. `Notifier::expensePaid` **não** é disparado para as parcelas
retroativas; `Notifier::expenseCreated` segue igual.

Testes (`backend/tests/Feature/ExpenseControllerStoreTest.php`):
- `test_installments_expense_starting_in_a_closed_cycle_is_created_with_past_quotas_paid` (novo)
- `test_installments_expense_entirely_in_closed_cycles_is_rejected` (novo)
- `test_retroactive_installments_leave_a_sealed_past_cycle_untouched_and_pending_starts_at_the_open_cycle` (novo)
- `test_installments_expense_quotas_in_open_cycles_start_as_pending_even_if_client_sends_paid_true` (renomeado de `..._quotas_start_as_pending_...`, docblock novo)

| Comando | Resultado |
|---|---|
| `php artisan test --filter='...starting_in_a_closed_cycle...\|...entirely_in_closed_cycles...'` (antes da mudança, RED) | 2 failed — 422 onde esperava 201; mensagem genérica onde esperava a nova |
| `php artisan test --filter='...' ` (depois da mudança, GREEN) | 2 passed (20 assertions) |
| `./vendor/bin/pint --test app/Http/Controllers/ExpenseController.php tests/Feature/ExpenseControllerStoreTest.php` | PASS, 2 files |
| `php artisan test --filter=ExpenseControllerStoreTest` | 21 passed (68 assertions) — inclui os 3 testes de rejeição (`IN_CASH`/`FIXED`/manual) intactos |
| `php artisan test` (suíte completa) | 320 passed (991 assertions) — sem regressão |
