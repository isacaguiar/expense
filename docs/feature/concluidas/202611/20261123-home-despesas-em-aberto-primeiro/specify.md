# Specify — Despesas em aberto primeiro

> Feature: as listas de despesas do ciclo mostram primeiro as despesas ainda não pagas, mantendo a ordem atual dentro de cada grupo. Origem: pedido dele em 2026-11-20 para a página inicial do grupo, registrado como item 160 do backlog (`docs/backlog/home-do-grupo-lista-despesas-em-aberto-primeiro.md`); escopo "só o 160" e, em 2026-11-23, ele ampliou a regra para as outras telas que já reaproveitam a mesma ordenação.

Versão: 1.1 · Criado em: 20261123

---

## 1. Problema

Quem abre o grupo quer saber o que ainda falta pagar. As listas de despesas do ciclo (a "Despesas do ciclo" da Home e as demais) ordenam só pela data da ocorrência (mais recente primeiro) e, de propósito, ignora o status. Uma despesa em aberto de dias atrás fica soterrada sob as pagas mais recentes, e a pessoa precisa rolar e ler os chips "Paga"/"Pendente" para achar o que falta.

É mudança só de frontend: a ordem de apresentação é feita no cliente (`frontend/src/utils/cycleOrdering.ts`), não no backend, e nenhum endpoint muda.

## 2. Requisitos

### 2.1 Despesas em aberto antes das pagas

Hoje (conferido no código em 2026-11-23): `pages/GroupSummary.tsx:53` renderiza `CycleDetailPanel`, que em `components/CycleDetailPanel.tsx:39` passa `sortExpensesByOccurrence(summary.expenses)` para `CycleExpenseList`. `sortExpensesByOccurrence` (`utils/cycleOrdering.ts:24`) ordena por data decrescente, depois `id` decrescente e número da parcela decrescente, sem olhar `paid`. Essa neutralidade ao status é a regra R1 de `docs/feature/concluidas/202610/20261026-organizacao-despesas-pagamentos/specify.md` (citada em `docs/sdd/05-context-frontend.md:36`); o pedido **reverte R1**, agora nas quatro telas que usam a função (ver 2.2).

Resultado esperado:

- As despesas com `paid === false` vêm antes das pagas.
- Dentro de cada um dos dois grupos vale a ordem atual (data decrescente, depois `id`, depois parcela).
- A lista não ganha filtro, divisor nem some com as pagas; os filtros de Despesas e de Pagamentos (`useExpenseFilters`) preservam a ordem recebida, então a regra vale também com filtro; o chip "Paga"/"Pendente" de cada linha continua como está.
- Só a ordem muda: totais, valores e o painel lateral (saldos e acertos) não são tocados.

### 2.2 Uma regra só, nas quatro telas

`sortExpensesByOccurrence` alimenta a Home (`CycleDetailPanel.tsx:39`), o relatório de fechamento (`ClosingReportPanel.tsx:212`), a tela de Despesas (`ExpenseManager.tsx:109`) e a de Pagamentos (`Payments.tsx:255`), todas sobre `summary.expenses`, que já traz `paid` por despesa: nenhuma consulta nova. A função passa a ordenar em aberto primeiro (D2-B) e as quatro telas herdam sem trocar a chamada. Mudam junto: os comentários dessas telas que descrevem a ordem, a nota de `docs/sdd/05-context-frontend.md:36` e os testes de ordem existentes, que passam a esperar "em aberto primeiro".

### 2.3 Decisões a fechar neste specify

As recomendações vêm do item 160, ajustadas por ele em 2026-11-23 (D1 ampliada, D2-B, D3-A; D4-A lido de uma resposta em branco).

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Onde vale | A) só a Home<br>B) Home e relatório de fechamento<br>C) Home, Despesas, Pagamentos e relatório | **C** (decidida por ele) | Todas reaproveitam a mesma função e o `paid` já vem no `summary`. Substitui a recomendação A do item 160. |
| D2 | Como ordenar | A) função nova só para a Home<br>B) `sortExpensesByOccurrence` passa a ordenar em aberto primeiro | **B** (decidida por ele) | Com D1-C, uma função só evita regra duplicada. O comparador por data (o atual) é extraído e reaproveitado dentro de cada grupo; o nome da função fica e o comentário é atualizado. |
| D3 | Separador visual entre "em aberto" e "pagas" | A) nenhum, só a ordem<br>B) subtítulo ou divisor | **A** (decidida por ele) | O pedido é de ordem; o chip "Paga"/"Pendente" já existe na linha. B pode virar item depois. |
| D4 | Ciclo selado ou fechado | A) vale igual<br>B) só em ciclo aberto | **A** | "O que falta pagar" faz sentido também no ciclo fechado; o snapshot selado traz `paid` por despesa (`SummaryExpense.paid`, `hooks/useGroupCycle.ts:38`). A função devolve cópia e não altera o `summary`. |

## 3. Fora de escopo desta feature

- Divisor ou subtítulo entre "em aberto" e "pagas" (D3-A), filtro ou ocultar as pagas.
- Ordem dos saldos e dos acertos (`sortBalancesForViewer`, `orderSettlementsForViewer`): não mudam.
- Item 161 (aviso de ciclo fechado com quem falta acertar): é outra feature; a dúvida sobre "credor" continua aberta lá.
- Backend, API, migration e contrato: nada muda.
- `key={expense.id}` em `CycleExpenseList.tsx:34`, que repete quando a mesma despesa tem duas parcelas no ciclo (existe `expenseRowKey` para isso): achado tangencial, não faz parte do pedido; se for confirmado, vira item de backlog.
