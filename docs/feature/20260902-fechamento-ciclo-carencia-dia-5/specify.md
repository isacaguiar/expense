# Ponteiro — Fechamento de Ciclo com Carência até o Dia 5

Versão: 1.0 · Criado em: 20260902

Este pedido — janela de carência de 5 dias após o fim do ciclo (o corte definitivo passa
do dia 1 para o dia 5 do mês seguinte, no caso padrão) + avisos de fechamento na tela
(banner pré-fechamento e banner de devedores pós-fechamento) — foi **absorvido pela feature
[`20260902-pagamento-ciclo-fechado`](../20260902-pagamento-ciclo-fechado/)**, por mexer no
mesmo `App\Support\BillingCycle`, no mesmo `ExpenseController` (`summary`/`focusCycle`/
guards de competência) e nos mesmos testes.

| Aqui | Lá |
|---|---|
| Requisitos | `../20260902-pagamento-ciclo-fechado/specify.md` §2.8–§2.10 |
| Decisões técnicas | `../20260902-pagamento-ciclo-fechado/plan.md` §9–§11 |
| Tasks | `../20260902-pagamento-ciclo-fechado/tasks.md` TASK-256..259 |

## Histórico

- **Origem:** conversa de 2026-09-02 — "o fechamento do ciclo, em vez de ser no dia 1 do
  próximo mês, deve ser no dia 5, avisando os usuários a partir do dia 1".
- **Triagem BFF:** rodada em 2026-09-02; as 4 caixas de `docs/bugfix/README.md` marcaram
  (toca despesas/grupos; muda contrato de API; correção ampla; decisão de produto), então o
  trabalho saiu do fluxo leve. Nenhum arquivo em `docs/bugfix/` foi criado.
- **`/nova-feature fechamento-ciclo-carencia-dia-5`** criou esta pasta; na sequência o
  escopo foi dobrado na feature `20260902-pagamento-ciclo-fechado` (mesma branch,
  `backend/20260902-pagamento-ciclo-fechado`) e esta pasta virou este ponteiro.
