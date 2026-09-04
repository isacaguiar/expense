# Dashboard sem dado financeiro (resumo Credor→devedores + Pix por grupo)

ID: 031
Origem: análise de branch local descartada (backend/20260821-regras-mensalidade-entrada-despesas, nunca mergeada — docs/feature/20260821-regras-mensalidade-entrada-despesas/specify.md §2.3/R3, TASK-113/TASK-114) — decisão de produto já confirmada pelo usuário em 2026-08-21 nessa branch, mas nunca implementada
Criado em: 2026-08-22
Prioridade: BAIXA
Status: Promovido para TASK-212

## Descrição

`Dashboard.tsx` (primeira tela após login) hoje só lista cards de grupo (nome, descrição, e-mail do responsável) — nenhum saldo/valor devido aparece ali, mesmo o dado já existindo via `GroupSummary.tsx`. A ideia (já confirmada com o usuário antes, ver Origem) é cada card de grupo ganhar uma árvore indentada Credor→devedores do mês corrente, com navegação de mês própria por card, ícone de copiar Pix e "informar pagamento" (só ação de UI, sem persistir nada — `Quota.paid` continua sem endpoint de escrita). Precisa de um endpoint novo (`GET /groups/{groupId}/expenses/monthly-summary` ou equivalente) que devolva o status do mês e o valor bruto (não líquido) devido por devedor a cada credor — hoje `GroupExpenseReportController`/`computeCycleSummary` calculam isso de formas diferentes (líquido, por ciclo de fechamento), nenhum dos dois é exatamente o que essa tela pediria; a decisão de reaproveitar/adaptar um dos dois ou criar um endpoint novo fica pro `plan.md` quando isso for promovido.

## Por que importa

`Dashboard` e `GroupSummary` continuam telas separadas por decisão de produto (confirmada antes) — sem isso, a tela inicial do app não responde à pergunta "quem me deve o quê" sem o usuário entrar em cada grupo.

Tipo sugerido: frontend

## Resolução

Concluído em: 2026-08-27
Feature: docs/feature/concluidas/202608/20260827-dashboard-resumo-credor-devedores/
Tasks: TASK-212, TASK-213, TASK-214
PRs: https://github.com/isacaguiar/expense/pull/71
