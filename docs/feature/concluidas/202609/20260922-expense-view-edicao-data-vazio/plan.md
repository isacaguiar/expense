# Plan — Campo Data vazio ao editar despesa

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260922

---

## 1. Normalizar a data em `startEditing()` (specify §2.1/§2.3)

- **Decisão**: em `frontend/src/pages/ExpenseView.tsx:210`, trocar `setDate(expense.date_payment)` por `setDate(expense.date_payment.slice(0, 10))`.
- **Por que essa abordagem e não outra**:
  - `.slice(0, 10)` é exatamente o corte que `parseLocalDate()` (`:96-100`) já usa pra resolver o mesmo formato dúbio no modo de visualização — reaproveita o raciocínio já validado ali (`"2026-08-01T00:00:00.000000Z".slice(0, 10)` e `"2026-08-01".slice(0, 10)` dão o mesmo `"2026-08-01"`), sem precisar entender fuso-horário: o valor final é uma string `YYYY-MM-DD` pronta pro `<input type="date">`, não um objeto `Date` (diferente de `parseLocalDate()`, que converte pra `Date` pra formatar exibição — aqui o input já quer a string).
  - Alternativa descartada: chamar `parseLocalDate(expense.date_payment)` e depois reformatar de volta pra `YYYY-MM-DD` — daria o mesmo resultado com mais um passo de ida-e-volta (`Date` → string) sem necessidade, já que o `<input type="date">` consome string diretamente.
  - Não extrai um helper compartilhado (ex.: exportar um `toDateInputValue()` usado tanto aqui quanto em `parseLocalDate()`) — é uma mudança de uma linha, num único call site; criar abstração pra um único uso violaria a regra de não introduzir abstração além do necessário.
- **Arquivo afetado**: `frontend/src/pages/ExpenseView.tsx`.

## 2. Ordem de execução

Item único, sem dependência.
