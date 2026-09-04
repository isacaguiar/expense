# Specify — Navegação mobile do SimpleShellLayout

> Feature: dá à navegação das telas sem grupo selecionado (Meus Grupos/Dashboard, Minha Conta, Alterar Senha) um substituto funcional em telas abaixo do breakpoint `md`, hoje inexistente. Origem: promoção do item de backlog `032` (`docs/backlog/simpleshelllayout-sidebar-navegacao-mobile.md`), registrado durante `TASK-206` da feature `docs/feature/concluidas/202608/20260826-navegacao-mobile-group-shell/`.

Versão: 1.0 · Criado em: 20260826

---

## 1. Problema

A feature `navegacao-mobile-group-shell` resolveu a perda de navegação em mobile para o `GroupShellLayout` (telas dentro de um grupo: Resumo, Despesas, Participantes, Pagamentos), adicionando um drawer de navegação (`MobileNavDrawer`) acionado por um botão hambúrguer no `GroupHeader`.

O `SimpleShellLayout` (`frontend/src/layouts/SimpleShellLayout.tsx`), usado nas telas sem grupo selecionado (`App.tsx`: rotas com `simpleNavItems` — Dashboard/"Meus Grupos", Minha Conta, Alterar Senha), tem exatamente o mesmo problema: usa a mesma `Sidebar` (`display: { xs: 'none', md: 'flex' }`, `Sidebar.tsx:112`) e o mesmo `GroupHeader`, sem nenhum substituto de navegação abaixo do breakpoint `md`. Ao integrar o `onMenuClick` obrigatório em `GroupHeader` durante a feature anterior, `SimpleShellLayout.tsx:40` recebeu apenas `onMenuClick={() => {}}` (no-op), só para manter o `tsc` compilando — sem nenhum drawer real por trás.

Resultado: em mobile, o usuário que está em "Meus Grupos", "Minha Conta" ou "Alterar Senha" não tem nenhum jeito de navegar entre esses itens nem sair da aplicação, exceto pela navegação do browser.

## 2. Requisitos

### 2.1 Substituto de navegação abaixo do breakpoint `md`

Abaixo de `md`, o usuário deve ter acesso a todos os itens que a `Sidebar` oferece hoje em desktop no `SimpleShellLayout`: Home (Meus Grupos), Despesas (link desabilitado — sem `to`, ver nota abaixo), Participantes (sem `to`), Pagamentos (sem `to`), Relatórios (sem `to`), submenu de Configurações (`accountSettingsNavItems` — Minha Conta / Alterar Senha) e Sair.

> Nota: `simpleNavItems` (`frontend/src/layouts/simpleNavItems.ts`) hoje só define `to` para "Home" (`/summary`) e "Despesas" (`/expenses`) — mas nenhuma rota do `App.tsx` usa `SimpleShellLayout` para essas duas (elas vivem sob `GroupShellLayout`, com `groupId` na URL). Isso é comportamento pré-existente do componente (mesmo em desktop, clicar nesses itens na sidebar do `SimpleShellLayout` hoje não leva a lugar nenhum útil sem um grupo selecionado) — fora do escopo desta feature corrigir; o drawer mobile deve só espelhar exatamente o que a sidebar desktop já mostra, sem mudar itens ou links.

Abordagem: reaproveitar o `MobileNavDrawer` e o botão hambúrguer no `GroupHeader` já criados pela feature `navegacao-mobile-group-shell` (mesmos componentes, sem duplicação) — mesma decisão de produto já tomada naquela feature (drawer temporário do MUI, não bottom navigation bar).

### 2.2 Escopo: todo o SimpleShellLayout

O `SimpleShellLayout` é compartilhado por todas as telas sem grupo selecionado (`simpleNavItems`) — a correção é feita uma vez no layout compartilhado e vale para todas essas telas simultaneamente.

### 2.3 Estado atual permanece em desktop

Acima do breakpoint `md`, o comportamento atual (sidebar fixa lateral, sempre visível) não deve mudar.

## 3. Fora de escopo desta feature

- Qualquer mudança em `MobileNavDrawer`, `NavList` ou `GroupHeader` além de passar a usá-los de fato no `SimpleShellLayout` (o `onMenuClick={() => {}}` no-op vira `onMenuClick={() => setMobileNavOpen(true)}`) — os componentes já existem e já têm cobertura de teste própria da feature `navegacao-mobile-group-shell`.
- Corrigir os itens de `simpleNavItems` sem `to` (Participantes, Pagamentos, Relatórios) ou os itens com `to` que não correspondem a nenhuma rota sob `SimpleShellLayout` (Home, Despesas) — comportamento pré-existente, não uma regressão introduzida por esta feature nem pela feature anterior.
- Bottom navigation bar ou qualquer outra abordagem de navegação mobile além do drawer via hambúrguer (decisão já tomada ao promover o item de backlog `022`/feature `navegacao-mobile-group-shell`).
- Item de backlog `020` (sistema de notificações) — o ícone de notificações do `GroupHeader` permanece como está, sem funcionalidade nova.
