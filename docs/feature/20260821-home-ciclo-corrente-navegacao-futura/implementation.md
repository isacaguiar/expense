# Implementation — Home: ciclo corrente, navegação futura, status do ciclo e fechamento congelado

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260821

---

## 1. Desvios do fluxo padrão

TASK-134 a TASK-145 (todo o backend) foram implementadas juntas, direto na branch da feature (`backend/20260821-home-ciclo-corrente-navegacao-futura`), em vez de uma sub-branch por task com merge individual. Motivo: as tasks são fortemente acopladas (mesmo arquivo `ExpenseController.php`/`BillingCycle.php`, cada mudança só é testável em conjunto com as seguintes — ex. TASK-140 depende de TASK-134/138/139 para fazer sentido) e a implementação já foi escrita como uma unidade coesa antes da formalização task-a-task; abrir sub-branches retroativas para um código já escrito e testado junto não agregaria rastreabilidade real. As tasks de frontend (TASK-146/147/148) seguem o fluxo padrão normalmente.

## 2. Log de implementação

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-134 | Implementada | 2026-08-21 | IA (Claude Code) | `php artisan test --filter=BillingCycleTest` — 10 passed (33 assertions) | `BillingCycle::closedCycle` renomeada para `cycleFor`, eixo trocado para "ciclo que contém a referência", retorno ganhou `status` |
| TASK-135 | Implementada | 2026-08-21 | IA (Claude Code) | `php artisan test --filter=BillingCycleTest` — 10 passed (33 assertions) | Suíte reescrita cobrindo ciclo aberto/fechado/futuro e bordas do dia de fechamento |
| TASK-136 | Implementada | 2026-08-21 | IA (Claude Code) | Coberto por `test_status_for_computes_status_of_the_cycle_containing_an_arbitrary_date` em `BillingCycleTest` (passou) | `BillingCycle::statusFor($closingDay, $date, $reference)` adicionado |
| TASK-137 | Implementada | 2026-08-21 | IA (Claude Code) | `php artisan test --filter=ExpenseControllerSummaryTest` — 8 passed (42 assertions), incluindo `test_installment_with_date_expected_in_a_later_cycle_still_appears` | `collectCycleEntries` passa a buscar parcelas `IN_INSTALLMENTS` por `date_expected` via `Quota`, independente do ciclo de criação |
| TASK-138 | Implementada | 2026-08-21 | IA (Claude Code) | `php artisan migrate` — `2026_08_22_015116_create_ex_group_cycle_snapshots_table ... DONE` | Migration local aditiva (gate autônomo — `06-context-backend.md`); model `GroupCycleSnapshot` criado |
| TASK-139 | Implementada | 2026-08-21 | IA (Claude Code) | `php artisan test --filter=ExpenseControllerSummaryTest` — 8 passed, sem mudança de comportamento | Cálculo de `totals`/`expenses`/`balances` extraído para `computeCycleSummary()` |
| TASK-140 | Implementada | 2026-08-21 | IA (Claude Code) | `php artisan test --filter=ExpenseControllerCycleFreezeTest` — 7 passed (27 assertions) | `summary()` busca/cria a foto (`cycleSnapshotFor()`) para ciclo `closed`; `open`/`future` continuam ao vivo |
| TASK-141 | Implementada | 2026-08-21 | IA (Claude Code) | `php artisan test --filter=ExpenseControllerSummaryTest` — 8 passed (42 assertions) | Testes atualizados para a nova semântica + 2 testes novos (ciclo futuro sem despesas, parcela em ciclo posterior) |
| TASK-142 | Implementada | 2026-08-21 | IA (Claude Code) | `php artisan test --filter=ExpenseControllerCycleFreezeTest` — 7 passed (27 assertions) | Testes de estabilidade da foto (edição/exclusão depois de fechado não muda o resultado; ciclo aberto continua ao vivo) |
| TASK-143 | Implementada | 2026-08-21 | IA (Claude Code) | `php artisan test --filter=ExpenseControllerCycleFreezeTest` — 7 passed (27 assertions) | `update`/`destroy` de `IN_CASH`/`IN_INSTALLMENTS` de ciclo fechado retornam 422 (`rejectIfCycleClosed`) |
| TASK-144 | Implementada | 2026-08-21 | IA (Claude Code) | `php artisan test --filter=ExpenseControllerStopRecurrenceTest` — 5 passed | `stopRecurrence` rejeita cutoff em ciclo fechado |
| TASK-145 | Implementada | 2026-08-21 | IA (Claude Code) | `php artisan test` (suíte completa) — 114 passed (311 assertions); `./vendor/bin/pint --test` limpo nos arquivos tocados | Testes de bloqueio cobertos em `ExpenseControllerCycleFreezeTest` e `ExpenseControllerStopRecurrenceTest` |
| TASK-146 | Pendente | — | — | — | — |
| TASK-147 | Pendente | — | — | — | — |
| TASK-148 | Pendente | — | — | — | — |
