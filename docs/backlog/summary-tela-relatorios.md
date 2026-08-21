# Criar tela de Relatórios do grupo

ID: 018
Origem: docs/feature/20260819-novo-layout-tela-entrada/specify.md §2.3/R2 (item "Relatórios" da sidebar como placeholder `href="#"`)
Criado em: 2026-08-19
Prioridade: BAIXA
Status: Aberto

## Descrição

O novo layout da tela de Resumo (`novo-layout-tela-entrada`) inclui uma sidebar com o item "Relatórios", sem tela correspondente hoje. O backend já tem `GroupExpenseReportController::reportByGroupAndYearMonthlySettlement` (`backend/app/Http/Controllers/GroupExpenseReportController.php`), mas nenhuma página do `frontend/` o consome — criar essa tela é o trabalho que falta.

## Por que importa

Existe endpoint de backend pronto e não usado; expor isso como tela daria valor real ao usuário sem trabalho de backend novo. Ainda assim, fora do escopo desta feature (que é só o redesenho visual da tela de Resumo).

Tipo sugerido: frontend
