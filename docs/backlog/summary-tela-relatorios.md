# Criar tela de Relatórios do grupo

ID: 018
Origem: docs/feature/20260819-novo-layout-tela-entrada/specify.md §2.3/R2 (item "Relatórios" da sidebar como placeholder `href="#"`)
Criado em: 2026-08-19
Reenquadrado em: 2026-08-22 — de "expor relatório anual/mensal calendário" para "expor histórico de ciclos de fechamento", após `docs/feature/20260822-acerto-de-contas-ciclo` confirmar que o mecanismo de ciclo já é a fonte de verdade do produto (ver Descrição)
Prioridade: BAIXA
Status: Promovido para TASK-209

## Descrição

O novo layout da tela de Resumo (`novo-layout-tela-entrada`) inclui uma sidebar com o item "Relatórios", sem tela correspondente hoje. A ideia original apontava para `GroupExpenseReportController::reportByGroupAndYearMonthlySettlement` (`backend/app/Http/Controllers/GroupExpenseReportController.php`), mas esse endpoint é **legado e diverge do cálculo real do grupo**: opera por ano/mês calendário (não pelo `BillingCycle`/`closing_day` do grupo), reconstrói `total_value/installments` do zero em vez de usar `Quota.value_quota` já persistida, e agrupa por nome do pagador em vez de `user_id` (`docs/feature/20260822-acerto-de-contas-ciclo/specify.md` §2.2).

Desde essa feature, o grupo já tem um mecanismo de fechamento por ciclo completo e correto: `ExpenseController::summary`/`computeCycleSummary`/`cycleSnapshotFor` (`GET /groups/{groupId}/expenses/summary`) calcula totais, despesas, saldos líquidos e liquidação par-a-par ("quem paga a quem") por ciclo, persistindo o resultado do ciclo fechado em `GroupCycleSnapshot` (`ex_group_cycle_snapshots`, campos `totals`/`expenses`/`balances`/`settlements`/`cycle_start`/`cycle_end` — `backend/app/Models/GroupCycleSnapshot.php`). A tela de Resumo (`GroupSummary.tsx`) já consome isso para o ciclo atual/navegável.

A tela "Relatórios" passa a fazer mais sentido como um **histórico de ciclos fechados** do grupo (lista de `GroupCycleSnapshot` passados com seus totais/saldos/liquidações), reaproveitando esse dado já existente, em vez de expor o relatório anual/mensal calendário antigo.

## Por que importa

Existe um mecanismo de ciclo completo (totais, saldos, par-a-par) já calculado e persistido por ciclo fechado, mas hoje só acessível ciclo a ciclo pela tela de Resumo — não há visão de histórico. Construir "Relatórios" como esse histórico aproveita dado e cálculo já prontos, sem depender do endpoint antigo de `GroupExpenseReportController` (que segue como está, sem uso — ver `docs/feature/20260822-acerto-de-contas-ciclo/specify.md` §4 "Fora de escopo"). Ainda assim, fora do escopo de qualquer feature já fechada — segue como ideia de backlog.

Tipo sugerido: frontend (com possível endpoint novo de listagem de `GroupCycleSnapshot` por grupo no backend)
