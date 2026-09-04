# Navegação alternativa para a sidebar do SimpleShellLayout em mobile

ID: 032
Origem: docs/feature/concluidas/202608/20260826-navegacao-mobile-group-shell/ (achado durante TASK-206, tangencial à feature — não bloqueia nenhuma task dela)
Criado em: 2026-08-26
Prioridade: MEDIA
Status: Promovido para TASK-208

## Descrição

`SimpleShellLayout` (`frontend/src/layouts/SimpleShellLayout.tsx`), usado nas telas sem grupo selecionado (`App.tsx`: "Meus Grupos"/Dashboard, Minha Conta, Alterar Senha — rotas com `simpleNavItems`), tem exatamente o mesmo problema que motivou o item de backlog `022` e a feature `docs/feature/concluidas/202608/20260826-navegacao-mobile-group-shell/`: usa a mesma `Sidebar` (`display: { xs: 'none', md: 'flex' }`) e o mesmo `GroupHeader`, sem nenhum substituto de navegação abaixo do breakpoint `md`.

A feature `navegacao-mobile-group-shell` resolveu isso só para o `GroupShellLayout` (telas dentro de um grupo: Resumo, Despesas, Participantes, Pagamentos). O `SimpleShellLayout` recebeu apenas a prop `onMenuClick={() => {}}` (no-op) no `GroupHeader`, só para manter o `tsc` compilando após a prop se tornar obrigatória — não ganhou o `MobileNavDrawer`.

## Por que importa

Mesma regressão de usabilidade do item `022`: em mobile, o usuário perde a navegação (voltar para grupos, ir para Minha Conta/Alterar Senha) nessas telas específicas. Como o `MobileNavDrawer` e o `NavList` já foram criados e testados pela feature `navegacao-mobile-group-shell`, o custo de resolver este item é baixo — é replicar a integração já feita em `GroupShellLayout.tsx` para `SimpleShellLayout.tsx`, passando `simpleNavItems(navigate)` em vez de `groupNavItems(groupId, navigate)`.

Tipo sugerido: frontend

## Resolução

Concluído em: 2026-08-27
Feature: docs/feature/concluidas/202608/20260826-navegacao-mobile-simple-shell/
Tasks: TASK-208
PRs: https://github.com/isacaguiar/expense/pull/69
