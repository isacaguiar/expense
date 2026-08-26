# Plan — Corrigir Corrupção de `total_value` ao Editar Despesa

> Traduz `specify.md` em decisão técnica.

Versão: 1.0 · Criado em: 20260826

---

## 1. `ExpenseView.tsx::startEditing()` (specify §R1)

- Troca `setValue(String(expense.total_value))` por `setValue(Number(expense.total_value).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))` — formata pro mesmo padrão pt-BR que `handleSave()`'s parser (`replace('.', '').replace(',', '.')`) já espera, igual ao placeholder "Ex: 150,00" do próprio campo.
- Nenhuma mudança na função de parsing, no backend, ou em `ExpenseForm.tsx` (não tem o bug — specify §2.2).

## 2. Teste de regressão (specify §R2)

- Novo teste em `ExpenseView.test.tsx`: abre edição, confirma que o campo já mostra o valor formatado (`"1.234,56"` pra um fixture de `total_value: "1234.56"`), salva sem tocar em Valor, confirma que o payload do PUT manda `total_value: 1234.56` (não `123456`).
- Teste existente (`'enters edit mode... saves via PUT'`) ganha a mesma asserção de `total_value` no `toMatchObject`, pra também travar essa regressão no fluxo que já existia.

## N. Ordem de execução

Item único, sem dependência.
