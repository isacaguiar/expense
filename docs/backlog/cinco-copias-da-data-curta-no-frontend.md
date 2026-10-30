# Cinco cópias da data curta (`formatDate`) no frontend podem usar `formatShortDate`

ID: 109
Origem: docs/feature/concluidas/202610/20261026-organizacao-despesas-pagamentos/plan.md §2 e implementation.md (TASK-500)
Criado em: 2026-10-27
Prioridade: BAIXA
Status: Aberto

## Descrição
A feature `20261026-organizacao-despesas-pagamentos` criou `frontend/src/utils/dates.ts` (`formatShortDate`) e trocou as quatro cópias que estavam nas telas que ela tocava (`GroupSummary`, `CycleExpenseList`, `Payments`, `ExpenseManager`). Sobraram **cinco cópias idênticas**, todas chamadas `formatDate`, em áreas fora daquele escopo:

- `frontend/src/components/CycleTotalsCards.tsx:8-11`
- `frontend/src/components/GroupGrossDebtsPanel.tsx:13-16`
- `frontend/src/components/MyItemsList.tsx:7-10`
- `frontend/src/components/PendingCyclesList.tsx:9-12`
- `frontend/src/pages/GroupReports.tsx:11-14`

Conferi em 2026-10-27 que as cinco são o mesmo código de `formatShortDate` (data montada pelos componentes locais, sem o recuo de um dia do `new Date('YYYY-MM-DD')`). **Não é defeito**, só duplicação: cada uma já tem teste (`*.test.tsx`).

Correção provável: apagar a cópia local e importar `formatShortDate` de `../utils/dates`, sem mudar nenhum teste.

## Por que importa
Se um dia o formato da data curta mudar, ou se aparecer um erro de fuso numa das cópias, hoje há seis lugares para lembrar. Refatoração pura, sem efeito visível, que cabe junto de qualquer trabalho futuro nessas telas (Painel, Dashboard, Relatórios).

Tipo sugerido: frontend
