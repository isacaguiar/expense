# Implementar sistema de notificações

ID: 020
Origem: docs/feature/20260819-novo-layout-tela-entrada/specify.md §2.4/R3 (sino no cabeçalho da tela de Resumo, hoje decorativo)
Criado em: 2026-08-19
Prioridade: BAIXA
Status: Promovido para TASK-260 (docs/feature/20260903-notificacoes-in-app/)

## Descrição

O novo layout da tela de Resumo inclui um ícone de sino no cabeçalho, reaproveitando um elemento comum do mockup — mas não existe hoje, em nenhum lugar do projeto (frontend ou backend), qualquer conceito de notificação (endpoint, tabela, contagem de não lidas, etc.). O ícone fica sem badge e sem `onClick`.

## Por que importa

Um sistema de notificações real (ex.: "fulano marcou uma despesa como paga", "ciclo do grupo fechou") agregaria valor de produto, mas é uma feature grande (modelo de dados, endpoint, entrega em tempo real ou polling, UI de lista) que não deve ser decidida só como efeito colateral do redesenho visual.

Tipo sugerido: backend

## Resolução

Concluído em: 2026-09-03
Feature: docs/feature/20260903-notificacoes-in-app/
Tasks: TASK-260, TASK-261, TASK-262, TASK-263, TASK-264, TASK-265, TASK-266, TASK-267
PRs: https://github.com/isacaguiar/expense/pull/141

Tabela `ex_notifications` + serviço `App\Support\Notifier` com 6 gatilhos inline
(`expense_paid`, `settlement_confirmed`, `cycle_settled`, `cycle_closed`,
`group_member_added`, `expense_created`), 3 endpoints (`GET /notifications`,
`GET /notifications/unread-count`, `POST /notifications/read`) e, no frontend, o
sino do cabeçalho com badge por polling (60s) + `NotificationsMenu` (lista,
marcar como lida, navegar). Entrega por polling; tempo real / e-mail / WhatsApp
ficaram fora de escopo.
