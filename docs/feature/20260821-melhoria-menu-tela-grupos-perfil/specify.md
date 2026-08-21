# Specify — Melhoria do Menu, Tela de Grupos e Perfil

> Feature: unifica a estrutura do menu lateral entre os dois shells hoje divergentes (incluindo um botão de logout, hoje inexistente na navegação atual), transforma a listagem de grupos em uma tabela com avatares dos integrantes e ícones de ação completos (CRUD), e adiciona uma página de perfil (Minha Conta) com troca de senha. Pedido novo, sem épico correspondente em `docs/sdd/03-tasks.md`.

Versão: 1.0 · Criado em: 20260821

---

## 1. Problema

Hoje o menu lateral tem duas implementações divergentes. `simpleNavItems.ts:9-21` (sem grupo selecionado) lista Resumo (`/summary`), Despesas (`/expenses`), Participantes (sem link), Pagamentos (sem link) e Configurações com um submenu de 1 item (Grupos → `/dashboard`). `groupNavItems` em `GroupSidebar.tsx:21-30` (com grupo selecionado) lista Resumo, Despesas, Participantes, Pagamentos, Relatórios (sem link) e Configurações — mas aqui Configurações é um **link direto** para `/groups/:id/edit`, não um submenu. Não há "Relatórios" no primeiro menu, e "Configurações" significa duas coisas diferentes em cada shell.

A listagem de grupos (`Dashboard.tsx`) já existe como grid de `Card`s (nome, descrição, responsável, busca, botão "Novo grupo", ícones de editar/participantes/despesas — entregue na feature `melhoria-tela-grupos`, já mergeada em `dev`), mas não mostra os integrantes de cada grupo, e não tem ícone de exclusão na UI apesar do backend já suportar exclusão (soft delete, `GroupController::destroy`, `backend/app/Http/Controllers/GroupController.php:82-90`).

Não existe hoje nenhuma tela de perfil do usuário: `GET /api/me` (`routes/api.php:19` → `AuthController::me`, `AuthController.php:57-60`) é somente leitura, e o único endpoint de escrita em `UserController.php` é `atualizarPix` (linhas 13-32, `POST /user/pix`) — não há endpoint genérico para editar nome/e-mail, nem endpoint de troca de senha autenticada (só existe o fluxo de "esqueci minha senha", `POST /forgot-password`, `routes/api.php:16`, que pressupõe usuário deslogado).

## 2. Requisitos

### 2.1 Menu lateral unificado

Os dois shells (`SimpleShellLayout`/`simpleNavItems.ts` e `GroupShellLayout`/`groupNavItems`) passam a ter exatamente a mesma estrutura de itens de primeiro nível: **Home**, **Despesas**, **Participantes**, **Pagamentos**, **Relatórios**, **Configurações**. "Home" substitui o rótulo "Resumo" nos dois menus (abre a mesma página de resumo/summary de hoje — só o rótulo muda). "Configurações" deixa de ser, no menu com grupo selecionado, um link direto de edição do grupo atual (`GroupSidebar.tsx:28`) e passa a ser, nos dois shells, um submenu com 3 itens: **Meus Grupos**, **Minha Conta**, **Alterar Senha**.

Consequência que fica registrada aqui para decisão explícita (não resolvida neste specify): hoje "Configurações" no menu com grupo selecionado é o único ponto de acesso, pelo menu, para editar o grupo atual (nome/descrição/dia de fechamento). Com esta mudança, esse acesso deixa de existir no menu — passa a depender inteiramente do ícone de editar na listagem de grupos (requisito 2.3).

### 2.2 "Home" e "Despesas" abrem o grupo com atividade mais recente

Hoje, com mais de 1 grupo, `/summary` (`SummaryEntry.tsx`) e `/expenses` (`ExpensesEntry.tsx`) mostram uma tela de escolha (cards de grupo) em vez de entrar direto num grupo — o redirecionamento automático só acontece quando o usuário tem exatamente 1 grupo (`res.data.length === 1`, mesmo padrão nos dois arquivos). O pedido é que, mesmo com múltiplos grupos, "Home" e "Despesas" abram automaticamente o grupo com a movimentação mais recente, sem tela de escolha.

Não existe hoje nenhum conceito de "atividade mais recente" por grupo no sistema: o pivot de membros (`ex_groups_members`, `backend/database/migrations/2025_06_12_024544_create_ex_group_members_table.php:14-22`) não tem coluna de timestamp, e o `Group` model não guarda nenhum indicador de última atividade. É lógica de negócio nova, não um ajuste de UI.

### 2.3 CRUD de grupos: listagem em tabela com avatares dos integrantes

A listagem de grupos (hoje grid de `Card`s em `Dashboard.tsx`) passa a ser uma tabela: cada linha mostra o nome do grupo, os avatares dos integrantes do grupo (mesmo padrão visual já usado em `GroupMembersForm.tsx:118-129` e `GroupHeader.tsx:70` — `Avatar` do MUI com iniciais via `getInitials()`, `frontend/src/layouts/group/getInitials.ts`) e os ícones de ação. Os ícones já existentes hoje (editar → `/groups/:id/edit`, participantes → `/groups/:id/members`, despesas → `/groups/:id/expenses`) são mantidos; como o pedido enquadra isso como "CRUD de grupos" e o backend já suporta exclusão (soft delete) sem nenhum ponto de acesso na UI hoje, esta feature adiciona também um ícone de excluir grupo na tabela.

Funcionalidades já entregues na feature anterior (`melhoria-tela-grupos`: busca, botão "Novo grupo", limite de 3 grupos criados, exibição do responsável) continuam existindo — só a estrutura visual muda de cards para tabela.

### 2.4 Página de Perfil (Minha Conta)

Nova tela, acessível pelo submenu "Minha Conta" (2.1), com formulário para editar os campos do usuário autenticado que fazem sentido serem autoeditáveis: `name`, `email` e `pix` (colunas reais de `ex_users` — `backend/database/migrations/2014_10_12_000000_create_users_table.php:14-23` e `.../2025_06_13_012846_add_pix_to_users_table.php:12`). Campos de sistema (`role`, `email_verified_at`, `remember_token`, `invited_by`, timestamps) não entram no formulário. Exige endpoint novo de atualização de perfil — hoje só existe `atualizarPix` (`UserController.php:13-32`, só o campo `pix`) e `GET /api/me`, que é somente leitura.

### 2.5 Alterar Senha

Novo item de submenu (2.1) e nova tela/formulário para o usuário autenticado trocar a própria senha (senha atual + nova senha). Não existe hoje nenhum endpoint de troca de senha autenticada — o único fluxo de senha existente é "esqueci minha senha" (`POST /forgot-password`, `routes/api.php:16`), que é um caso de uso diferente (usuário deslogado).

### 2.6 Botão "Sair" no menu

O menu lateral ganha um item "Sair" (logout), presente nos dois shells. Hoje não existe nenhum ponto de logout acessível na navegação atual (`GroupShellLayout.tsx`/`SimpleShellLayout.tsx`/`GroupHeader.tsx` não têm botão de sair); o único código de logout existente é `Navbar.tsx:8-11` (`handleLogout`), que pertence ao `InternalLayout.tsx` legado — não referenciado em `App.tsx` hoje, ou seja, código morto. Esse `handleLogout` só limpa o `localStorage` e navega para `/`, sem chamar o backend. Já existe um endpoint de logout no backend (`POST /logout` → `AuthController::logout`, `backend/app/Http/Controllers/AuthController.php:71-80`) que invalida o token JWT no servidor (`auth()->logout()`) — não usado por nenhuma tela hoje.

## 3. Fora de escopo desta feature

- Definir a fórmula exata de "atividade mais recente" (2.2) além de registrar que é lógica nova — a decisão técnica (qual coluna/tabela usar) é do `plan.md`, não deste specify.
- Qualquer alteração em `groupNavItems`/`GroupSidebar.tsx` que não seja a unificação da estrutura descrita em 2.1 — os itens Home/Despesas/Participantes/Pagamentos do menu com grupo selecionado continuam levando para dentro do grupo atual (`/groups/:id/...`); só "Configurações" muda de comportamento.
- Conteúdo funcional da página "Relatórios" — o item de menu passa a existir em ambos os shells, mas pode continuar como placeholder sem página (mesmo estado de hoje para "Pagamentos"/"Relatórios" no menu com grupo selecionado, `GroupSidebar.tsx:26-27`) até uma feature própria implementá-la.
- Regras de força de senha, 2FA, ou qualquer política de segurança de conta além do formulário básico de troca de senha (2.5).
- Exclusão física (hard delete) de grupo — o ícone novo de excluir (2.3) usa o soft delete já existente (`GroupController::destroy`), nunca `DELETE` físico.
- Remover o código legado `Navbar.tsx`/`InternalLayout.tsx` (2.6) — hoje já não é referenciado em `App.tsx`, mas essa limpeza é tangencial a esta feature; fica para um item de backlog separado, não para esta feature.
