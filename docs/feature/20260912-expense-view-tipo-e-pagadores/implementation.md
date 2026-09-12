# Implementation — Página da despesa: tipo, cronograma de parcelas e pagadores

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260912

Branch da feature: `frontend/20260912-expense-view-tipo-e-pagadores`, criada a partir de `dev` atualizada (`git pull origin dev` trouxe até `47bac5ca8a`, o merge do PR #159). Documentos do SDD commitados em `d5f906a237`.

---

## 1. Desvios do fluxo padrão

Nenhum. Segue `docs/sdd/04-implementation.md` §1: primeira task direto na branch da feature, tasks seguintes em sub-branch com merge local `--no-ff`, um PR único contra `dev` no fim.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-276 | Integrada na branch da feature | 2026-09-12 | IA (Claude Opus 5) | `cd frontend && npx vitest run src/pages/ExpenseView.test.tsx` — **antes** da correção: `Tests 2 failed \| 13 passed (15)`; **depois**: `Tests 15 passed (15)`. `npx tsc --noEmit` — exit 0, sem saída. `npx vitest run` (suíte completa) — `Test Files 37 passed (37)`, `Tests 241 passed (241)` | Commit `a05520279f`. 1ª task da feature → direto na branch da feature, sem sub-branch (`04-implementation.md` §1.1) |
| TASK-277 | Pendente | — | — | — | — |
| TASK-278 | Pendente | — | — | — | — |
| TASK-279 | Pendente | — | — | — | — |
| TASK-280 | Pendente | — | — | — | — |
| TASK-281 | Pendente | — | — | — | — |

### TASK-276 — detalhe

Ordem seguida: os dois casos de teste foram escritos **antes** da correção e rodados para confirmar que falhavam (o `findByText('À Vista')` estourou timeout com o DOM mostrando o chip antigo). Só então `typeLabel` deixou de ser `Record<ExpenseType, string>` e virou função `typeLabel(expense: ExpenseDetail)` — a mudança de forma é necessária porque `Parcelada (Nx)` depende de `expense.installments`, que um `Record` indexado por `expense_type` não alcança.

Arquivos tocados: `frontend/src/pages/ExpenseView.tsx` (`typeLabel` + a chamada no `Chip`) e `frontend/src/pages/ExpenseView.test.tsx` (2 casos novos). O caso existente da fixture `FIXED` (espera `Fixa`) **não** foi alterado, como exigia o critério de aceite.

Checklist de `04-implementation.md` §1.3: critério de aceite verificado por teste; frontend com `tsc --noEmit` limpo e sem `any`; nenhuma migration envolvida; `git diff` revisado antes do commit — 62 inserções, 5 remoções, nenhum segredo.
