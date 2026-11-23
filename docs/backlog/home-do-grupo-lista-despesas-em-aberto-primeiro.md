# Home do grupo: mostrar primeiro as despesas em aberto, mantendo a ordem atual dentro de cada grupo

ID: 160
Origem: pedido dele em 2026-11-20 (conversa da feature `20261120-ver-comprovante-em-modal`); `frontend/src/components/CycleExpenseList.tsx`, `frontend/src/components/CycleDetailPanel.tsx:39`, `frontend/src/utils/cycleOrdering.ts:24`
Criado em: 2026-11-20
Prioridade: MEDIA
Status: Aberto

## Descrição
Pedido dele, nas palavras: na página inicial do grupo, manter a ordenação atual, **mas priorizar as despesas não pagas**, para facilitar a visão do que está em aberto.

Como é hoje (conferido no código em 2026-11-20): a Home do grupo (`pages/GroupSummary.tsx`) renderiza `CycleDetailPanel`, que passa `sortExpensesByOccurrence(summary.expenses)` para `CycleExpenseList` ("Despesas do ciclo"). `sortExpensesByOccurrence` ordena só pela data da ocorrência (mais recente primeiro), com `id` e número da parcela decrescentes no empate, e **de propósito ignora o status**: ela substituiu o "pendentes primeiro" do backend (regra R1 de `docs/feature/concluidas/202610/20261026-organizacao-despesas-pagamentos/specify.md`, citada em `docs/sdd/05-context-frontend.md`). O pedido, portanto, **reverte em parte** essa regra para a Home.

Resultado esperado: as despesas com `paid === false` vêm antes das pagas; dentro de cada um dos dois grupos vale a ordem atual (data decrescente, depois `id`, depois parcela). A lista não ganha filtro nem some com as pagas.

O mesmo `sortExpensesByOccurrence` também alimenta o relatório de fechamento (`ClosingReportPanel.tsx:212`), a tela de Despesas (`ExpenseManager.tsx:109`) e a de Pagamentos (`Payments.tsx:255`); mudar a função afetaria os quatro, o que o pedido não diz (D1).

## Decisões em aberto
A fechar no specify, se o item for promovido.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Onde vale | A) só a Home (`CycleDetailPanel`)<br>B) Home e relatório de fechamento<br>C) Home, Despesas, Pagamentos e relatório | **A** | O pedido cita a página inicial. Despesas e Pagamentos têm a ordem por ocorrência como regra deliberada (R1 da feature de organização); mudá-las sem pedido seria surpresa. |
| D2 | Como ordenar | A) função nova `sortExpensesOpenFirst`, que reaproveita `sortExpensesByOccurrence` dentro de cada grupo<br>B) parâmetro novo em `sortExpensesByOccurrence` | **A** | Não mexe em quem já usa a função atual (ExpenseManager, Payments, relatório) e deixa a regra nova num lugar só, testável. |
| D3 | Separador visual entre "em aberto" e "pagas" | A) nenhum, só a ordem<br>B) subtítulo ou divisor | **A** | O pedido é de ordem; o chip de pago já existe na linha. B pode virar item depois. |
| D4 | Ciclo selado ou fechado | A) vale igual<br>B) só em ciclo aberto | **A** | A pergunta "o que falta pagar" faz sentido também no ciclo fechado; o snapshot selado traz `paid` por despesa. Conferir na specify. |

## Por que importa
Quem abre o grupo quer saber o que ainda falta pagar; com a ordem só por data, uma despesa em aberto de dias atrás fica soterrada sob as pagas mais recentes.

Tipo sugerido: frontend (sem mudança de backend)
