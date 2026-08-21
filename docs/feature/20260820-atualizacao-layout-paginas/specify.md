# Specify — Atualização de Layout das Demais Páginas

> Feature: modernizar visualmente as páginas do `expense/frontend` que ainda não passaram pelo novo sistema visual (paleta `brandColors`, sidebar de navegação, cards arredondados) criado nas features `novo-layout-tela-login` e `novo-layout-tela-entrada` — todas exceto Login e Resumo, que já estão prontas. Segue o mockup de Despesas (`assets/images/screen/desktop.png`, também disponível recortado tela a tela em `E:\Projetos\Controle de Despesas\assets\images\01.png`-`09.png`) como referência pixel-exata para a tela de Despesas, e como referência **de padrão** (listagem → visualizar/criar/editar → confirmar exclusão → concluído → vazio → filtros) para as demais páginas, que não têm mockup próprio. Pedido novo, sem épico correspondente em `docs/sdd/03-tasks.md`.

Versão: 1.0 · Criado em: 20260820

---

## 1. Problema

`novo-layout-tela-login` e `novo-layout-tela-entrada` modernizaram Login e Resumo do grupo, mas as demais 6 páginas do `frontend/` continuam com o visual antigo — `Container`/`Card`/`TextField` soltos do MUI, sem a paleta `brandColors`, sem a sidebar de navegação (`GroupSummarySidebar`) e ainda dependuradas na `Navbar`/`InternalLayout` global (`App.tsx:22-35`): `Dashboard.tsx` (`/dashboard`), `GroupList.tsx` (`/groups`), `GroupForm.tsx` (`/groups/new`, `/groups/:id/edit`), `GroupMembersForm.tsx` (`/groups/:id/members`), `ExpenseManager.tsx` (`/groups/:id/expenses`) e `ExpensesEntry.tsx` (`/expenses`).

O usuário forneceu um mockup completo só da tela de Despesas (8 estados). Decisão já tomada com o usuário: seguir esse mockup à risca para Despesas, e usar o mesmo *padrão* de listagem/formulário/exclusão para as demais páginas — sem inventar mockup pixel-exato para elas. Também já decidido: esta feature é **só frontend** — onde o mockup de Despesas pede dado/ação que a API não tem (ver achado 2.3), a tela usa o que já existe hoje, e o gap vira item de backlog (`023`-`026`), não é resolvido aqui.

## 2. Achados confirmados

### 2.1 Sidebar/header/paleta já existem, mas acoplados à pasta `pages/summary/`

`GroupSummarySidebar.tsx`/`GroupSummaryHeader.tsx` (`frontend/src/pages/summary/`, criados em `novo-layout-tela-entrada`) já implementam exatamente a navegação lateral do mockup (Resumo/Despesas/Participantes navegáveis; Pagamentos/Relatórios/Configurações placeholder) e o cabeçalho (seletor de grupo, sino decorativo, avatar de iniciais via `GET /api/me`). Ambos só recebem `groupId`/props simples, sem depender de nada específico de `GroupSummary` — reaproveitáveis para qualquer página que já tenha um grupo no contexto (tem `:id` na rota).

### 2.2 Nem toda página tem um grupo no contexto

`ExpenseManager.tsx` (`/groups/:id/expenses`), `GroupMembersForm.tsx` (`/groups/:id/members`) e `GroupForm.tsx` em modo edição (`/groups/:id/edit`) têm `:id` na rota — cabem no shell "dentro de um grupo" (sidebar + header, achado 2.1). `Dashboard.tsx` (`/dashboard`), `GroupList.tsx` (`/groups`), `GroupForm.tsx` em modo criação (`/groups/new`) e `ExpensesEntry.tsx` (`/expenses`) **não têm grupo selecionado** — não há como montar os links `Despesas`/`Participantes` da sidebar sem um `:id`. Essas páginas precisam de um shell mais simples (sem sidebar de grupo).

### 2.3 O que o mockup de Despesas pede além do que a API tem hoje

`ExpenseController` (achados já registrados em `docs/backlog/023`-`026`) não implementa `show`/`update`/`destroy` (apesar de as rotas existirem via `apiResource`), não tem campo `category`, só tem 2 estados de pagamento (`Quota.paid`, não 3 como o mockup) e `indexByGroup` não pagina/filtra/busca no servidor. Decisão já tomada: esta feature usa só o que já funciona (ver R3 abaixo).

### 2.4 `Dashboard.tsx` e `GroupList.tsx` são páginas quase duplicadas

Ambas listam grupos do usuário (`GET /api/groups`) em cards; `GroupList.tsx` tem busca e botão "Novo Grupo" (e um título com resíduo de debug, `"Meus Grupos XXX"`, `GroupList.tsx:65`), `Dashboard.tsx` tem um botão de ação a mais (ir para Resumo). `LoginPage.tsx` navega para `/dashboard` após login (rota de entrada real). Registrado como achado tangencial em `docs/backlog/027`.

### 2.5 `GroupForm.tsx` (edição) já cobre parte do que seria "Configurações do grupo"

`GroupForm.tsx` em modo edição (`/groups/:id/edit`) já permite editar nome, descrição e dia de fechamento do grupo — é, na prática, uma versão inicial da tela de "Configurações" que a sidebar hoje trata como placeholder (`docs/backlog/019`). Não cobre tudo que o mockup mobile de configurações sugere (membros, regras, notificações — fora de escopo, `019` continua aberto para isso), mas cobre o que existe hoje.

### 2.6 `ExpenseManager.tsx` hoje não bate com a estrutura do mockup

Cria despesa via modal (`Dialog`, `ExpenseManager.tsx:412-502`), sem tela dedicada de visualizar/editar, sem exclusão genérica (só `stopRecurrence` para despesa Fixa, um conceito diferente de "excluir"), sem coluna de status/participantes/categoria na listagem (o `Expense` retornado por `indexByGroup` só tem `id, description, date, value, payerName, isFixed`).

### 2.7 `GroupMembersForm.tsx` já segue o padrão "listagem + formulário lado a lado"

Lista membros (tabela simples) e formulário de adicionar por e-mail, em duas colunas — estrutura próxima do padrão do mockup (listagem + ação), só falta o visual novo (cards, paleta, ícones, avatar por membro).

## 3. Requisitos

- **R1**: Duas variantes de shell de página, ambas reaproveitando `brandColors`:
  - **Dentro de um grupo** (`ExpenseManager`, `GroupMembersForm`, `GroupForm` em edição): `GroupSummarySidebar` + `GroupSummaryHeader` (achado 2.1), tirando essas 3 rotas de dentro de `InternalLayout`/`Navbar` — mesmo padrão já aplicado à rota de Resumo (`App.tsx:37-38`). Sidebar aponta "Configurações" para `/groups/:id/edit` (achado 2.5) em vez de `href="#"` — fecha parcialmente o backlog `019`.
  - **Sem grupo selecionado** (`Dashboard`/lista de grupos consolidada, `GroupForm` em criação, `ExpensesEntry`): cabeçalho simples (logo + avatar do usuário, sem seletor de grupo/sino/sidebar), permanece fora de `InternalLayout`/`Navbar` também, para não ter 3 sistemas de navegação diferentes no app (sidebar / Navbar antiga / cabeçalho simples).
- **R2**: Consolidar `Dashboard.tsx`+`GroupList.tsx` (achado 2.4) numa única página, na rota `/dashboard` (mantida por já ser o destino do login) — `GroupList.tsx`/rota `/groups` são removidos. Página segue o padrão "listagem" do mockup: título, busca, botão "Novo grupo", cards de grupo (nome, descrição, ação de editar/participantes/despesas), estado vazio ("Você ainda não participa de nenhum grupo", reaproveitando o texto que já existe em `ExpensesEntry.tsx`).
- **R3**: `ExpenseManager.tsx` reestilizado seguindo o mockup de Despesas, mas só com dado/ação que a API já oferece hoje (achado 2.3): listagem em cards (não `Table`), badge de tipo (Fixa/Variável a partir de `isFixed`), busca e filtro por tipo **no cliente** (sobre os dados do mês já carregados, sem paginação server-side), "Visualizar despesa" e "Editar despesa" como views client-side (roteadas, mas usando o item já presente no array carregado — sem chamar `show`/`update` da API), exclusão continua restrita ao fluxo que já existe (`stopRecurrence`, só para despesa Fixa) com um diálogo de confirmação no novo visual — **sem** coluna de status/categoria (não existem no dado hoje) e **sem** paginação real (lista do mês inteiro, sem "Mostrando 1 a 5 de N").
- **R4**: `GroupMembersForm.tsx` reestilizado no padrão "listagem + ação" (achado 2.7): cards/lista de membros com avatar de iniciais, formulário de adicionar por e-mail ao lado — mesmos dados/endpoints de hoje.
- **R5**: `GroupForm.tsx` (criar e editar) reestilizado como formulário em card, com os mesmos campos de hoje (nome, descrição, dia de fechamento) — sem novo campo.
- **R6**: `ExpensesEntry.tsx` reestilizado no shell "sem grupo selecionado" (R1), mesmo comportamento de hoje (redireciona direto se só há 1 grupo, senão lista para escolher).
- **R7**: Nenhuma mudança de lógica de negócio, contrato de API ou dado exibido além do que R2-R6 descrevem — só camada visual e a consolidação de rota do R2.

## 4. Fora de escopo desta feature

- **Tudo que o mockup de Despesas pede e a API não tem** (achado 2.3): endpoints `show`/`update`/`destroy` reais, campo categoria, status "Aguardando", busca/filtro/paginação server-side — registrado em `docs/backlog/023`-`026`.
- **Telas de Pagamentos, Relatórios e Configurações completas** — continuam como já decidido em `novo-layout-tela-entrada` (`docs/backlog/017`-`019`); "Configurações" ganha só o link real para `GroupForm` em edição (R1), não uma tela nova.
- **Exclusão genérica de despesa** (excluir qualquer despesa, não só parar recorrência de Fixa) — depende do backlog `023`.
- **Regra de negócio de "Configurações do grupo" além do que `GroupForm` já cobre** (membros, regras de divisão, notificações do mockup mobile) — seguem no backlog `019`.
- **Responsividade mobile detalhada** — mesmo tratamento das features anteriores (sidebar oculta abaixo de `md`), sem menu alternativo (mesmo gap já registrado no backlog `022`).
