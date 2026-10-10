# Implementation — Filtros de Credor, Devedores e Status nas Despesas

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261010

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum desvio: a feature segue `04-implementation.md` sem exceção.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-406 | Concluída | 2026-10-10 | Claude (sessão com o mantenedor) | **Ciclo 1, `creditorOf`/`debtorsOf`.** RED: `cd frontend && npx vitest run src/utils/expenseFilters.test.ts` com o teste escrito e o módulo só com esqueleto que devolve vazio → `4 failed` (falha de asserção, não de import). GREEN: mesmo comando após implementar → `4 passed`. **Ciclo 2, `buildCreditorOptions`/`buildDebtorOptions`.** RED → `4 failed \| 5 passed (9)` (o caso de lista vazia já passa com o esqueleto, e protege contra um `reduce` sem valor inicial). GREEN → `9 passed`. **Ciclo 3, `filterExpenses`.** RED → `9 failed` (18 testes no arquivo). GREEN → `18 passed`. **Refactor:** tipos movidos para o topo do arquivo; `npx vitest run src/utils/expenseFilters.test.ts` → `18 passed`. **Checagem de mutação** com 10 mutações reais aplicadas uma a uma em `expenseFilters.ts`, cada uma seguida de `npx vitest run src/utils/expenseFilters.test.ts` (arquivo restaurado depois, conferido com `cmp` byte a byte contra a cópia): credor excluído dos devedores, credor OU→E, devedor OU→E, ordenação sem `localeCompare`, sem o fallback "Sem nome", busca sensível a maiúsculas, status `paid` invertido, sem deduplicação, prefixo `name:` removido, sem fallback de snapshot → **as 10 derrubaram ao menos um teste**. **Checklist:** `npx tsc --noEmit` → exit 0, sem saída; `grep -nE "from 'react'\|\bany\b"` nos dois arquivos → nenhuma ocorrência; `git status --short -- frontend` → só `expenseFilters.ts` e `expenseFilters.test.ts` (ambos novos), `ExpenseManager.tsx` intocado; `npm test` → `Test Files 46 passed (46)`, `Tests 356 passed (356)`. | Primeira task da feature, implementada direto na branch `frontend/20261010-filtros-credor-devedores-status-despesas`. Sem commit ainda: aguarda o seu OK, porque o `git merge --no-ff` da TASK-407 precisa de histórico. Expectativas dos testes escritas à mão por id de despesa (Aluguel 9, Mercado 10, Internet 11), sem reaproveitar o código testado. |
