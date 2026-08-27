# Tasks — Navegação mobile do GroupShellLayout

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260826

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-204 | Extrair `NavList` reutilizável de `Sidebar.tsx` | frontend | plan.md §1 | nenhum | Concluída |
| TASK-205 | Criar `MobileNavDrawer` usando `NavList` | frontend | plan.md §2 | nenhum | Concluída |
| TASK-206 | Adicionar botão hambúrguer ao `GroupHeader`, visível abaixo de `md` | frontend | plan.md §3 | nenhum | Concluída |
| TASK-207 | Integrar `MobileNavDrawer` ao `GroupShellLayout` | frontend | plan.md §4 | nenhum | Concluída (validada manualmente pelo usuário) |

## Critérios de aceite

- **TASK-204**: `Sidebar.test.tsx` continua passando sem alteração de asserções (mesmo comportamento observável). Novo `NavList.tsx` exporta o componente e é importado por `Sidebar.tsx`, que não contém mais a lógica de item (`SidebarNavItem`, `containsActiveChild`) diretamente. `npx vitest run` no frontend passa.
- **TASK-205**: Renderizar `<MobileNavDrawer items={...} open={true} onClose={fn} />` em teste exibe os itens de navegação (mesmas asserções de link/botão de `Sidebar.test.tsx`, adaptadas). Clicar em um item com `to` ou `onAction` chama `onClose`. `open={false}` não deixa os itens visíveis/acessíveis (drawer fechado).
- **TASK-206**: Renderizar `GroupHeader` com `onMenuClick` passado exibe um `IconButton` com `aria-label="Abrir menu de navegação"`; clicar nele chama `onMenuClick` uma vez. Inspeção visual/snapshot confirma `sx` com `display: { xs: 'inline-flex', md: 'none' }` (oposto ao breakpoint de `Sidebar.tsx`).
- **TASK-207**: Em `GroupShellLayout`, clicar no botão hambúrguer do `GroupHeader` abre o `MobileNavDrawer` (drawer visível com os itens de `groupNavItems`); clicar em um item de navegação do drawer fecha o drawer. Navegar manualmente para `/groups/:id/summary`, `/groups/:id/expenses`, `/groups/:id/members` e `/groups/:id/payments` no browser em viewport mobile (`< md`, ex. 375px) confirma o hambúrguer presente e funcional nas quatro telas — validação manual registrada em `implementation.md` com print ou passo a passo, já que é comportamento de layout compartilhado difícil de cobrir 100% em teste automatizado de uma tela isolada.
