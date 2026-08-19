# Specify — Resumo do Grupo (Dashboard)

> Feature: nova tela "Resumo do grupo" no frontend web (`expense/frontend`), com cards de totais do mês (Total de despesas / Pago / A pagar), lista de despesas do mês com pagador e status, e saldos por pessoa — no estilo do mockup anexado pelo usuário. Origem: pedido novo do usuário nesta conversa (sem task/épico prévio em `03-tasks.md`).

Versão: 1.0 · Criado em: 20260818

---

## 1. Problema

Hoje não existe nenhuma tela que dê uma visão consolidada de um grupo específico. `Dashboard.tsx` lista todos os grupos do usuário (cards "Meus Grupos", sem nenhum número financeiro) e `ExpenseManager.tsx` lista despesas de um grupo mês a mês, mas sem totais agregados, sem status de pagamento por despesa e sem saldo por pessoa — quem deve quanto para quem só existe hoje nos relatórios anuais (`report/{year}`, `report-monthly/{year}`), que não são pensados para uma visão rápida de "situação do grupo agora".

## 2. Achados confirmados

### 2.1 Não existe hoje uma tela de resumo por grupo — só lista de grupos e lista de despesas

`Dashboard.tsx:44-72` renderiza cards de grupo (nome, descrição, botões editar/membros/despesas) vindos de `GET /api/groups`, sem nenhum dado financeiro. `ExpenseManager.tsx:302-400` mostra uma tabela de despesas do mês (data, descrição, valor, pagador) vinda de `GET /api/groups/{groupId}/expenses`, sem cards de total, sem status de pagamento e sem saldo por pessoa. Nenhuma das duas telas se parece com o mockup (cards de Total/Pago/A pagar + lista com status + bloco de saldos).

### 2.2 Layout atual é uma barra superior (`Navbar`), não a sidebar do mockup

`InternalLayout.tsx:5-14` renderiza só `<Navbar />` (AppBar horizontal, `Navbar.tsx:13-27`) + `<Outlet />`, sem sidebar. O mockup mostra uma sidebar fixa à esquerda com itens "Resumo / Despesas / Participantes / Pagamentos / Relatórios / Configurações" — nenhum desses itens (Pagamentos, Relatórios, Configurações) tem tela ou rota hoje em `App.tsx`. Reproduzir a sidebar inteira do mockup é uma mudança de navegação global da aplicação, não desta tela isolada.

### 2.3 Tema atual não é o do mockup (paleta azul/rosa padrão MUI, não verde)

`theme.ts:4-17` define `primary.main: '#1976d2'` (azul MUI) e `secondary.main: '#dc004e'` (rosa MUI), sem nenhuma customização de verde/marca. O mockup usa uma identidade visual própria (logo "$" verde, paleta verde, ilustrações na tela de login). Repaginar o tema global da aplicação (`theme.ts`) e a tela de login não foi pedido além do mockup de referência visual — tratado como decisão de design a confirmar, não como achado que já determina um requisito.

### 2.4 `GET /groups/{groupId}/expenses` (`indexByGroup`) já devolve a lista de despesas do mês, mas sem status de pagamento

`ExpenseController::indexByGroup` (`backend/app/Http/Controllers/ExpenseController.php:14-67`) devolve, para um `year`/`month`, cada despesa do mês (direta ou projeção de despesa Fixa) com `id`, `description`, `date`, `value`, `payerName`, `isFixed` (`mapRow`, linhas 26-35) — não inclui nenhum campo de status ("Paga"/"Pendente" no mockup). O tipo `Expense` do frontend (`ExpenseManager.tsx:37-44`) espelha exatamente esses campos, sem `paid`/`status`.

### 2.5 Status de pagamento (`Quota.paid`) existe na base, mas com lacunas que impedem reaproveitar direto

- Tabela `ex_quotas` (`backend/database/migrations/2025_06_12_022708_create_ex_quotas_table.php`) tem coluna `paid` (boolean). Model `Quota` (`backend/app/Models/Quota.php`) expõe `paid` e pertence a uma `Expense` via `expense_id`.
- No cadastro de despesa (`ExpenseManager.tsx:219-234`), a quota criada já nasce com `paid` diferente por tipo: **À Vista** nasce `paid: true` (linha 233); **Parcelada** nasce cada parcela com `paid: false` (`buildInstallmentQuotas`, `ExpenseManager.tsx:67-81`, linha 77); **Fixa** nasce com uma única quota `paid: false` (linha 231).
- Não existe, em nenhum lugar do backend, um jeito de marcar uma quota como paga depois de criada: `QuotaController` (`backend/app/Http/Controllers/QuotaController.php`) e `ParticipationController` (`backend/app/Http/Controllers/ParticipationController.php`) são stubs vazios (todos os métodos só têm `//`) e **não estão registrados em `routes/api.php`** (confirmado por `grep "Route::" backend/routes/api.php` — só aparecem rotas de `groups`, `groups/{groupId}/members`, `expenses`, `expenses/{expenseId}/stop-recurrence`, relatórios; nada de `quotas` nem `participations`). Ou seja, hoje uma parcela ou despesa fixa nunca muda de "Pendente" para "Paga" depois de criada.
- Para despesas **Fixa**, a recorrência de meses futuros é só uma projeção virtual em `indexByGroup` (`ExpenseController.php:46-64`, `$projectedFixed`) — não existe uma `Quota` de verdade para cada mês projetado, só para o mês de criação. Não há hoje nenhuma coluna/registro para dizer se a "cópia" de fevereiro de uma despesa Fixa criada em janeiro está paga ou não.

### 2.6 Nenhum endpoint agregado devolve "Total do mês / Pago / A pagar" prontos

Nem `indexByGroup` nem `getMonthlyExpenses` (`ExpenseController.php:176-195`, agregados por mês mas sem quebra pago/pendente) somam valores pagos vs. pendentes. Dado o achado 2.5, mesmo somando `value_quota` por `paid`, o resultado hoje seria sempre "tudo pago" para despesas À Vista e "tudo pendente" para Parceladas/Fixas — não reflete uso real (ninguém nunca marca uma parcela como paga).

### 2.7 Saldo por pessoa já é calculado para relatório anual, mas como par a par, não como saldo líquido único por pessoa

`GroupExpenseReportController::reportByGroupAndYearMonthlySettlement` (`backend/app/Http/Controllers/GroupExpenseReportController.php:108-194`) já calcula, mês a mês, `finalSettlement[receiver][payer] = valor` (líquido entre cada par de pessoas, linhas 169-189) a partir de `payer`/`payers` de cada despesa. O mockup pede um número único por pessoa ("Isac: R$ 230,00 a receber"), que é a soma de tudo que essa pessoa recebe de todo mundo menos tudo que ela deve a todo mundo — essa agregação (par a par → líquido por pessoa) não existe hoje em nenhum endpoint; teria que ser calculada a partir de `finalSettlement` ou de uma consulta nova equivalente, filtrada para o mês corrente (o endpoint atual exige um `{year}` inteiro e retorna 404 se o grupo não tiver nenhuma despesa naquele ano).

### 2.8 Endpoints reaproveitáveis sem mudança

- `GET /api/groups` (`GroupController@index`) — já usado por `Dashboard.tsx` e `ExpensesEntry.tsx`; serve para popular o seletor de grupo do cabeçalho do mockup ("Casa dos Amigos ▾").
- `GET /api/groups/{groupId}/members` (`GroupMemberController@index`) — já usado por `ExpenseManager.tsx:170`; dá nome de cada participante, necessário para o bloco "Saldos por pessoa".
- `GET /api/me` (`AuthController@me`, `backend/app/Http/Controllers/AuthController.php:57-60`) — retorna o usuário autenticado. Não há campo de avatar/foto em `User` (`backend/app/Models/User.php`, sem coluna `avatar`/`photo` em `$fillable`) — os avatares do mockup não têm dado de origem hoje.
- `GET /groups/{groupId}/expenses?year&month` (`indexByGroup`) — base para a lista "Despesas do mês" (data, descrição, valor, pagador, `isFixed`), faltando só o status de pagamento (achado 2.4/2.5).

## 3. Fora de escopo desta feature

- Redesenho da navegação global para sidebar (itens "Despesas", "Participantes", "Pagamentos", "Relatórios", "Configurações" do mockup) — mantém-se o `Navbar`/`InternalLayout` atual; a tela nova é só mais uma rota dentro do layout existente. Uma sidebar de verdade, com todas aquelas seções, é mudança de navegação de toda a aplicação, não desta feature.
- Rebrand visual da aplicação (paleta verde, logo "$", ilustrações da tela de login) — `theme.ts` e `LoginPage.tsx` continuam como estão; o mockup serve de referência de layout/conteúdo da tela de Resumo, não de redesign de marca.
- Qualquer fluxo para **marcar uma quota/parcela como paga** (endpoint novo em `QuotaController`/`ParticipationController`, ou toggle na UI) — os achados 2.5/2.6 mostram que isso não existe hoje; esta feature consome o status que existir no banco no momento da consulta (mesmo que hoje isso signifique "sempre pago" para À Vista e "sempre pendente" para Parcelada/Fixa), sem adicionar o mecanismo de alterá-lo. Vira ideia de backlog se confirmado como necessário.
- Registrar `paid`/status por mês para despesas Fixa projetadas (achado 2.5, terceiro bullet) — a projeção de meses futuros continua só virtual; esta feature no máximo decide como *exibir* essa lacuna (ex.: status fixo "Pendente" para meses projetados), não cria tabela/coluna nova para rastrear isso mês a mês.
- Tela/menu "Pagamentos", "Relatórios" e "Configurações" do mockup — não existem hoje e não fazem parte desta feature (só "Resumo" e o que já existe em "Despesas"/"Participantes" via `ExpenseManager`/`GroupMembersForm`).
- Saldos por pessoa no acumulado histórico do grupo — o mockup mostra números que parecem ser do mês corrente (mesmo período dos cards e da lista); saldo acumulado desde o início do grupo, se for isso que o mockup quer dizer, precisa ser decidido no `plan.md` a partir de confirmação do usuário, não assumido aqui.
