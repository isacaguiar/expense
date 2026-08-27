# Specify — Navegação mobile do GroupShellLayout

> Feature: dá à navegação do grupo (Home/Resumo, Despesas, Participantes, Pagamentos, Relatórios, Configurações, Sair) um substituto funcional em telas abaixo do breakpoint `md`, hoje inexistente. Origem: promoção do item de backlog `022` (`docs/backlog/summary-sidebar-navegacao-mobile.md`), registrado durante `docs/feature/20260819-novo-layout-tela-entrada/`.

Versão: 1.0 · Criado em: 20260826

---

## 1. Problema

O item de backlog 022 foi registrado descrevendo a perda de navegação em mobile especificamente na tela de Resumo (Home do grupo). Ao investigar o código atual para esta promoção, confirmou-se que o problema é mais amplo: a `Sidebar` (`frontend/src/layouts/Sidebar.tsx:104-116`) é renderizada dentro do `GroupShellLayout` (`frontend/src/layouts/GroupShellLayout.tsx:56-57`), que por sua vez envolve **todas** as telas do grupo — Resumo, Despesas, Participantes e Pagamentos (rotas em `frontend/src/layouts/group/GroupSidebar.tsx:27-41`) — não só o Resumo.

A `Sidebar` usa `display: { xs: 'none', md: 'flex' }` (`Sidebar.tsx:112`), ou seja, abaixo do breakpoint `md` ela simplesmente desaparece. O `GroupHeader` (`frontend/src/layouts/group/GroupHeader.tsx`), renderizado ao lado do `Outlet` em todas essas telas, não tem nenhum botão de menu, hambúrguer ou substituto — só título, seletor de grupo, ícone de notificações e avatar do usuário.

Resultado: em qualquer tela do grupo, abaixo de `md`, o usuário perde todos os links de navegação (Despesas, Participantes, Pagamentos, Relatórios, Configurações, Sair) e só sai da tela atual via navegação do browser (voltar) ou indo para o `Dashboard` por outro caminho. Isso é uma regressão de usabilidade em mobile já que as demais páginas do app fora do `GroupShellLayout` continuam com uma navbar que ao menos lista os links.

## 2. Requisitos

### 2.1 Substituto de navegação abaixo do breakpoint `md`

Abaixo de `md`, o usuário deve ter acesso a todos os itens que a `Sidebar` oferece hoje em desktop: Home, Despesas, Participantes, Pagamentos, Relatórios (hoje sem link — item desabilitado, ver `GroupSidebar.tsx:33`), submenu de Configurações (`accountSettingsNavItems`) e Sair.

Abordagem escolhida (decisão do usuário ao promover este item, 2026-08-26): **drawer temporário do MUI, aberto por um botão hambúrguer no `GroupHeader`**, reaproveitando a lista de itens de navegação já usada pela `Sidebar` (`groupNavItems`) e sua renderização de item (`SidebarNavItem`), em vez de bottom navigation bar.

### 2.2 Escopo: todo o GroupShellLayout, não só a tela de Resumo

O item de backlog original citava só a tela de Resumo. Como a `Sidebar`/`GroupShellLayout` é compartilhada por todas as telas do grupo, e a causa raiz é a mesma, a correção é feita uma vez no layout compartilhado (`GroupShellLayout`/`Sidebar`/`GroupHeader`) e vale para todas as telas do grupo simultaneamente — não é uma correção específica da tela de Resumo.

### 2.3 Estado atual permanece em desktop

Acima do breakpoint `md`, o comportamento atual (sidebar fixa lateral, sempre visível) não deve mudar.

## 3. Fora de escopo desta feature

- Bottom navigation bar ou qualquer outra abordagem de navegação mobile além do drawer via hambúrguer (decisão já tomada ao promover o item).
- Redesenho visual do `GroupHeader` além do necessário para acomodar o botão de abrir o drawer.
- Implementar o link de "Relatórios" (hoje item desabilitado em `GroupSidebar.tsx:33`) — fora do escopo deste achado, é o item de backlog `018`.
- Navegação mobile de telas fora do `GroupShellLayout` (ex.: `Dashboard`, telas de autenticação) — usam a `Navbar` tradicional, não afetada por este achado.
- Item de backlog `020` (sistema de notificações) — o ícone de notificações do `GroupHeader` permanece como está, sem funcionalidade nova.
