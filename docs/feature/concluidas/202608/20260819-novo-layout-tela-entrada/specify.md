# Specify — Novo Layout da Tela de Resumo (Entrada pós-login)

> Feature: modernizar visualmente a tela "Resumo do grupo" (`GroupSummary.tsx`, rota `/groups/:id/summary` — primeira tela que o usuário vê após o login, via `/summary` → `SummaryEntry.tsx`), substituindo o layout atual — `AppBar` horizontal + `Container`/`Card`/`List` genéricos do MUI — por um layout com sidebar de navegação e cards de resumo, conforme mockup fornecido pelo usuário (`assets/images/entrada.png`). Pedido novo, sem épico correspondente em `docs/sdd/03-tasks.md`; reaproveita a paleta/identidade visual já criada na feature `docs/feature/concluidas/202608/20260819-novo-layout-tela-login/`. A feature anterior de dados desta tela (`docs/feature/concluidas/202608/20260818-resumo-grupo-dashboard/`) já implementou toda a lógica funcional e explicitamente deixou o redesenho visual "de fora do escopo" — é isso que esta feature resolve agora.

Versão: 1.0 · Criado em: 20260819

---

## 1. Problema

A tela "Resumo do grupo" (`GroupSummary.tsx`) já calcula e exibe tudo o que a feature `20260818-resumo-grupo-dashboard` definiu funcionalmente — ciclo de fechamento, cards de totais, lista de despesas do ciclo com status, saldos por pessoa — mas com um visual genérico: `AppBar` horizontal compartilhada (`Navbar.tsx`, usada em todas as páginas internas via `InternalLayout.tsx`) e conteúdo em `Container`/`Card`/`List` padrão do MUI, sem nenhuma identidade visual do produto. O `specify.md` daquela feature registrou explicitamente, em "Fora de escopo": *"Redesenho da navegação global para sidebar... mantém-se Navbar/InternalLayout atual"* e *"Rebrand visual (paleta verde, logo, ilustrações do mockup) — theme.ts/LoginPage.tsx inalterados"*.

O usuário forneceu um novo mockup (`entrada.png`) mostrando essa mesma tela com sidebar de navegação vertical, cabeçalho com seletor de grupo + notificação + avatar do usuário, e cards/listas com o visual mais refinado — na mesma linguagem visual (verde, cards arredondados) já estabelecida na tela de login redesenhada. Esta feature aplica esse layout à tela de Resumo, sem alterar a lógica/dados que já funcionam.

## 2. Achados confirmados

### 2.1 A funcionalidade da tela já existe e não precisa mudar

`GroupSummary.tsx:122-261` já renderiza, nesta ordem: seletor de grupo (`Select`, linhas 126-139), navegação de ciclo com setas (linhas 150-173), 3 cards de totais — Total/Pago/A pagar (linhas 175-210), lista "Despesas do ciclo" com status Paga/Pendente (linhas 212-240), e "Saldos por pessoa" (linhas 242-257). Toda a busca de dados (`GET /api/groups/:id/expenses/summary`, `GET /api/groups`) e os tipos (`Summary`, `SummaryExpense`, `SummaryBalance`) permanecem exatamente como estão — só a camada visual muda.

### 2.2 Navegação atual é uma `AppBar` horizontal global, compartilhada por todas as páginas internas

`InternalLayout.tsx:5-14` renderiza `Navbar.tsx` (uma `AppBar` fixa no topo com links Dashboard/Grupos/Despesas/Resumo/Sair) antes do `<Outlet />`, para **todas** as rotas privadas (`App.tsx:22-37`): `/dashboard`, `/groups`, `/groups/new`, `/groups/:id/edit`, `/groups/:id/members`, `/groups/:id/expenses`, `/expenses`, `/groups/:id/summary`, `/summary`. O mockup mostra uma sidebar vertical só no contexto de um grupo selecionado — não uma navegação global genérica.

### 2.3 Nem todos os itens do menu do mockup têm página correspondente hoje

A sidebar do mockup lista 6 itens: Resumo, Despesas, Participantes, Pagamentos, Relatórios, Configurações. Rotas reais existentes que mapeiam 1:1: Resumo → `/groups/:id/summary` (`GroupSummary.tsx`), Despesas → `/groups/:id/expenses` (`ExpenseManager.tsx`), Participantes → `/groups/:id/members` (`GroupMembersForm.tsx`). **Pagamentos**, **Relatórios** e **Configurações** não têm nenhuma página/rota no `frontend/` hoje (não existe `GroupExpenseReportController` exposto como tela, nem tela de configurações de grupo além do `GroupForm.tsx` de editar nome/descrição).

### 2.4 Não existe sistema de notificações

Não há nenhum componente, endpoint ou estado relacionado a notificações no `frontend/` (`grep -ri notif frontend/src` não retorna nada). O sino do mockup é puramente decorativo na referência.

### 2.5 Dados do usuário logado disponíveis via `GET /api/me`, mas sem foto/avatar

`AuthController::me` (`backend/app/Http/Controllers/AuthController.php:57-60`) devolve o `User` autenticado inteiro; `User::$fillable` (`backend/app/Models/User.php:24-29`) inclui `name` e `email` — suficiente para mostrar o nome do usuário no cabeçalho. Não existe campo de foto/avatar no model — o avatar do mockup (foto de perfil) não tem dado real equivalente.

### 2.6 Identidade visual (cores, logo, fonte) já existe, criada na feature de login

`frontend/src/pages/login/colors.ts` já define `loginColors` (`primary: '#17A37F'`, `primaryDark: '#128468'`, `primaryLight: '#E8F5E9'`, `textDark: '#122B4F'`); `frontend/src/assets/images/logo-expense.png` (ícone) e a fonte Poppins (via `<link>` em `index.html`) já estão disponíveis para reaproveitar no wordmark "Shared Expense" da sidebar. `frontend/src/theme.ts` permanece com `primary.main: '#1976d2'`, sem alteração (mesmo padrão da feature de login: cores locais, tema global intocado).

### 2.7 O termo "ciclo" não é sinônimo de "mês calendário"

O mockup usa rótulos como "Este mês" (sob "Total de despesas") e "Despesas do mês". A tela real trabalha com "ciclo de fechamento" (que pode não coincidir com o mês calendário — ver `docs/feature/concluidas/202608/20260818-resumo-grupo-dashboard/specify.md` §R2). Copiar esses rótulos literalmente do mockup induziria a erro.

## 3. Requisitos

- **R1**: Redesenhar apenas a tela de Resumo (`GroupSummary.tsx`) com um layout de duas áreas: sidebar de navegação vertical fixa à esquerda + área de conteúdo à direita — renderizado localmente dentro de `GroupSummary`, **sem alterar** `InternalLayout.tsx`/`Navbar.tsx` (que continuam servindo as demais páginas exatamente como hoje). Decisão explícita para não repetir a expansão de escopo já evitada em `20260818-resumo-grupo-dashboard`; se o usuário quiser esse layout em todas as páginas internas, é uma feature futura separada.
- **R2**: Sidebar mostra, no topo, o wordmark "Shared Expense" (ícone `logo-expense.png` + texto Poppins, reaproveitando o padrão de `LoginBrandingPanel.tsx`) e, abaixo, os 6 itens do mockup com ícone cada: **Resumo**, **Despesas**, **Participantes** navegam para as rotas reais do grupo atual (`/groups/:id/summary`, `/groups/:id/expenses`, `/groups/:id/members`); **Pagamentos**, **Relatórios**, **Configurações** ficam como itens visuais desabilitados/`href="#"` (achado 2.3), sem navegação real — registrados como ideias de backlog `017` (Pagamentos), `018` (Relatórios) e `019` (Configurações) em `docs/backlog/`.
- **R3**: Cabeçalho da área de conteúdo com: título da página ("Resumo do grupo"), seletor de grupo (reaproveita o `Select` já existente, linhas 126-139), ícone de sino decorativo sem badge/funcionalidade (achado 2.4, registrado como ideia de backlog `020`) e bloco com nome do usuário logado (via `GET /api/me`, achado 2.5) + `Avatar` com iniciais do nome (sem foto, já que não há esse dado — ideia de backlog `021`).
- **R4**: Os 3 cards de totais (Total de despesas / Pago / A pagar) mantêm os valores já calculados, com sublabel adaptado ao conceito real de ciclo (ex.: usar a mesma faixa de datas já exibida na navegação de ciclo, em vez de "Este mês" — achado 2.7) e adicionar o percentual do total sob "Pago" e "A pagar" (`paid / total * 100`, `pending / total * 100` — cálculo derivado no frontend, sem novo campo de backend).
- **R5**: Lista "Despesas do ciclo" (nome mantido — achado 2.7) e bloco "Saldos por pessoa" seguem exibindo os mesmos dados de hoje, com o visual de card/lista arredondado do mockup (ícone por despesa, chip de status, avatar por pessoa com iniciais).
- **R6**: A tonalidade de cores é exatamente a mesma da tela de login — reaproveita `loginColors` (`primary: '#17A37F'`, `primaryDark: '#128468'`, `primaryLight: '#E8F5E9'`, `textDark: '#122B4F'`, achado 2.6) sem criar nova paleta para esta tela; se precisar mover/reexportar de um local compartilhado, é decisão de `plan.md`, mas os valores de cor permanecem idênticos. `theme.ts` global permanece sem diff.

## 4. Fora de escopo desta feature

- **Redesenho da navegação global (`Navbar`/`InternalLayout`) para sidebar em todas as páginas internas** — só a tela de Resumo ganha o layout novo nesta feature (R1). Repetir esse padrão nas demais telas é decisão/feature futura.
- **Telas de Pagamentos, Relatórios e Configurações** — os itens correspondentes na sidebar são visuais/`href="#"` (R2); criar essas telas de fato é trabalho futuro, registrado em backlog (uma ideia por item).
- **Sistema de notificações** — o sino do cabeçalho é decorativo (R3), sem endpoint, contagem ou funcionalidade; virar funcional é ideia de backlog.
- **Foto/avatar real do usuário** — mostra iniciais (R3); upload/exibição de foto de perfil não existe no projeto hoje e não é criado aqui.
- **Qualquer mudança na lógica de cálculo do ciclo, dos totais, das despesas ou dos saldos** — `GroupSummary.tsx` continua consumindo exatamente os mesmos endpoints e dados de hoje (achado 2.1); só a camada visual e o cálculo derivado de percentual (R4) mudam.
- **Layout mobile/responsivo detalhado da sidebar** — o mockup é desktop; comportamento em telas estreitas (ocultar sidebar, menu hambúrguer etc.) fica como decisão técnica a detalhar em `plan.md`, seguindo os breakpoints já usados no restante do `frontend/`.
