# Tasks — Redesign Visual das Telas de Despesas

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260825

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-001 | Criar tema com escopo restrito às telas de Despesas | frontend | plan.md §1 | nenhum | Concluída |
| TASK-002 | Aplicar o tema restrito e o polish visual em `ExpenseManager.tsx` | frontend | plan.md §2 | nenhum | Concluída |
| TASK-003 | Aplicar o polish visual em `ExpenseView.tsx` | frontend | plan.md §3 | nenhum | Concluída |
| TASK-004 | Aplicar o polish visual em `ExpenseForm.tsx` e `ExpensesEntry.tsx` | frontend | plan.md §4 | nenhum | Concluída |

## Critérios de aceite

- **TASK-001**: `frontend/src/theme/despesasTheme.ts` e `frontend/src/theme/DespesasThemeScope.tsx` existem; `npx tsc --noEmit` sem erro novo; nenhuma tela ainda consome o componente (esta task só cria a peça, não aplica).
- **TASK-002**: `ExpenseManager.tsx` envolvido por `DespesasThemeScope`; busca/filtro/tabela/estado vazio com o tratamento visual de `plan.md §2`; suíte `ExpenseManager.test.tsx` (34 testes) continua verde sem alteração de asserção sobre comportamento (só ajustar seletor se um texto/role mudar por causa de um elemento novo, ex. ícone do estado vazio); navegação manual confirma que todas as ações (ver detalhe, editar, excluir, marcar/desfazer pago, remover fixa, fechar/reabrir mês) continuam funcionando idênticas.
- **TASK-003**: `ExpenseView.tsx` envolvido por `DespesasThemeScope`; `ExpenseView.test.tsx` continua verde; visualização e edição navegadas manualmente sem alteração de campo/fluxo.
- **TASK-004**: `ExpenseForm.tsx` e `ExpensesEntry.tsx` envolvidos por `DespesasThemeScope`; `ExpenseForm.test.tsx`/`ExpensesEntry.test.tsx` continuam verdes; criação de despesa navegada manualmente sem alteração de campo/fluxo.
