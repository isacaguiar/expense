# Tasks — Campo Data vazio ao editar despesa

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").
>
> Numeração: continua a partir de TASK-346, o maior ID em uso no projeto — as features `email-verificado-obrigatorio` (TASK-336–340) e `parcela-retroativa-notificar-credor` (TASK-341–346), promovidas na mesma sessão, ainda não estão em `dev` para o grep de `docs/feature/**` enxergar, mas os IDs já estão reservados por elas.

Versão: 1.0 · Criado em: 20260922

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-347 | Normalizar a data em `startEditing()` (`.slice(0, 10)`) | frontend | plan.md §1 | nenhum | Concluída |
| TASK-348 | Teste que prova o campo Data não fica vazio ao editar com `date_payment` em ISO-8601 completo | frontend | plan.md §1 | nenhum | Concluída |

## Critérios de aceite

- **TASK-347**: `frontend/src/pages/ExpenseView.tsx:210` passa a ser `setDate(expense.date_payment.slice(0, 10));`. Nenhuma outra linha de `startEditing()` muda. `npx tsc --noEmit` sem erro.

- **TASK-348**: novo teste em `frontend/src/pages/ExpenseView.test.tsx`, mesmo padrão dos testes de edição já existentes (`user.click(screen.getByRole('button', { name: 'Editar' }))`), usando `mockGetResponses({ expense: { ...expenseDetail, date_payment: '2026-08-01T00:00:00.000000Z' } })` (fixture em ISO completo, não no formato curto que hoje mascara o bug) — depois de entrar em modo de edição, `screen.getByLabelText(dateFieldLabel)` (ou o rótulo real do campo Data) tem `value` `'2026-08-01'`, não vazio. `npx vitest run src/pages/ExpenseView.test.tsx` verde. Reverter TASK-347 localmente faz este teste falhar.
