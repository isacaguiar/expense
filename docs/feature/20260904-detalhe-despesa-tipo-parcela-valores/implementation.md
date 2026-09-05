# Implementation — Detalhe da despesa: tipo, parcela e valores por pagador

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260904

---

## 1. Desvios do fluxo padrão

**TASK-003 não produz código.** É um script SQL executado manualmente pelo usuário no banco de produção (gate da Constitution §5.2) — não entra no PR como mudança de comportamento, só como documento. Mesmo desvio já registrado na feature `20260904-parcela-retroativa-contabilizacao` (TASK-002).

Branch da feature: `feature/20260904-detalhe-despesa-tipo-parcela-valores`, criada a partir de `dev` em 2026-09-04 (após o merge do PR #151). TASK-001 vai direto nela; TASK-002 em sub-branch `frontend/20260904-detalhe-despesa-tipo-parcela-valores-TASK-002`, mergeada localmente (`ADR-003`).

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-001 | Concluída | 2026-09-04 | IA (Claude) | Ver detalhamento abaixo | Campos aditivos; `isFixed` mantido |

### TASK-001 — detalhamento

Arquivo alterado: `backend/app/Http/Controllers/ExpenseController.php`.

1. **`collectCycleEntries()`** — as três origens de entry passam a propagar `'quotaNumber'`:
   `$direct` e `$fixedCandidates` usam `$quota->number ?? null` (ocorrência FIXED ainda projetada
   não tem Quota); o laço de `$installmentQuotas` usa `$quota->number`, que é o dado que interessa.
2. **`computeCycleSummary()`** — o item de `expenses` ganha `expenseType`, `installmentNumber`,
   `installmentsTotal` e `totalValue`, logo abaixo de `isFixed`, que **permanece** (filtro/ícone de
   `ExpenseManager`, `Payments` e testes existentes dependem dele).

Testes novos em `backend/tests/Feature/ExpenseControllerSummaryTest.php`:
- `test_installments_expense_exposes_type_installment_number_and_total_value_per_cycle` — parcelada
  de 3 parcelas consultada em 3 ciclos consecutivos (`cycles_ago` 2/1/0) devolve `installmentNumber`
  1, 2 e 3, com `value` continuando a ser o valor da parcela (100) e `totalValue` o da despesa (300).
- `test_in_cash_and_fixed_expenses_expose_their_type_without_installment_semantics` — `IN_CASH`
  devolve `expenseType: 'IN_CASH'`/`isFixed: false`; ocorrência `FIXED` projetada devolve
  `expenseType: 'FIXED'` com `installmentNumber: null`.

| Comando | Resultado |
|---|---|
| `php artisan test --filter='test_installments_expense_exposes_type_installment_number_and_total_value_per_cycle\|test_in_cash_and_fixed_expenses_expose_their_type_without_installment_semantics'` (RED, antes do código) | 2 failed (2 assertions) — `Undefined array key "expenseType"` |
| `php artisan test --filter=ExpenseControllerSummaryTest` (GREEN) | 25 passed (177 assertions) |
| `./vendor/bin/pint app/Http/Controllers/ExpenseController.php tests/Feature/ExpenseControllerSummaryTest.php` | PASS, 2 files |
| `php artisan test` (suíte completa) | 330 passed (1065 assertions) — sem regressão |

Ajuste feito durante o RED→GREEN: os asserts de valor monetário passaram de `assertSame(300.0, ...)`
para `assertEqualsWithDelta(300, ..., 0.01)` — `json_encode` serializa float sem casa decimal como
inteiro (`300.0` → `300`), mesmo comportamento que `value`/`valuePerPerson` já tinham.
