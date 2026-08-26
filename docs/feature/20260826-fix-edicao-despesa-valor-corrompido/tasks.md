# Tasks — Corrigir Corrupção de `total_value` ao Editar Despesa

Versão: 1.0 · Criado em: 20260826

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-001 | Formatar o Valor pré-preenchido em pt-BR + teste de regressão | frontend | plan.md §1, §2 | nenhum | Concluída |

## Critérios de aceite

- **TASK-001**: `ExpenseView.tsx` pré-preenche Valor em formato pt-BR (`"1.234,56"`, não `"1234.56"`); salvar sem tocar no campo manda `total_value` correto no PUT; `ExpenseView.test.tsx` cobre o cenário; `npx tsc --noEmit` sem erro.
