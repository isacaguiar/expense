# Plan — Atualização de Layout das Demais Páginas

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260820

---

## 1. Shells compartilhados: `GroupShellLayout` e `SimpleShellLayout` (specify R1, achados 2.1/2.2)

- **`GroupShellLayout`** (`frontend/src/layouts/GroupShellLayout.tsx`, layout route com `<Outlet />`): generaliza o que `GroupSummary.tsx` já monta inline hoje (sidebar + header). Lê `:id` via `useParams`, busca `groups` (`GET /api/groups`) e `userName` (`GET /api/me`) uma única vez — hoje esse fetch está duplicado dentro de `GroupSummary.tsx`; centralizar aqui remove a duplicação quando `GroupSummary` passar a usar este layout também (ver abaixo). Renderiza `GroupSummarySidebar` (movida para `frontend/src/layouts/group/GroupSidebar.tsx`, mesmo conteúdo) + `GroupSummaryHeader` (movida para `frontend/src/layouts/group/GroupHeader.tsx`), com o `title` do header derivado do item da sidebar cujo `to` bate com `location.pathname` (reaproveita o array `navItems` que a sidebar já tem — sem duplicar a lista de rotas/labels).
- **`GroupSummary.tsx` migra para usar `GroupShellLayout`** em vez de montar sidebar/header manualmente (`GroupSummary.tsx` atual, todo o JSX de `Box` com duas áreas) — remove código duplicado, mantendo os testes de `GroupSummary.test.tsx` que já validam sidebar/header (só muda onde o componente é montado, não o resultado renderizado).
- **`SimpleShellLayout`** (`frontend/src/layouts/SimpleShellLayout.tsx`, layout route com `<Outlet />`): cabeçalho simples — logo/wordmark à esquerda (mesmo `logoIcon` + Poppins da sidebar), avatar de iniciais + nome à direita (mesmo `GET /api/me` + `getInitials`, componente `getInitials.ts` promovido de `pages/summary/` para `frontend/src/layouts/group/getInitials.ts`, reaproveitado pelos dois shells). Sem seletor de grupo, sino ou sidebar — não há grupo no contexto (achado 2.2).
- `Navbar.tsx`/`InternalLayout.tsx` continuam existindo sem alteração — depois desta feature, nenhuma rota privada os usa mais (todas migram para um dos dois shells novos ou já migraram no `novo-layout-tela-entrada`), mas os arquivos não são apagados nesta feature (fora do escopo — ver `specify.md` §4, é decisão de limpeza separada, não bloqueia nada).

## 2. Rotas em `App.tsx` (specify R1, R2, R6)

- Novo grupo de rotas sob `<Route element={<GroupShellLayout />}>` (fora de `InternalLayout`, dentro de `RequireAuth`, mesmo padrão já usado para `/groups/:id/summary`): `/groups/:id/summary` (migra pra cá, hoje é rota solta), `/groups/:id/expenses` + sub-rotas novas (item 4), `/groups/:id/members`, `/groups/:id/edit`.
- Novo grupo de rotas sob `<Route element={<SimpleShellLayout />}>`: `/dashboard`, `/groups/new`, `/expenses`.
- `InternalLayout`/`Navbar` deixam de ser referenciados em `App.tsx` (rota `<Route element={<InternalLayout />}>` é removida — todas as rotas que ela envolvia migraram).
- Rota `/groups` (`GroupList.tsx`) é removida (item 3).

## 3. Consolidar `Dashboard.tsx` + `GroupList.tsx` (specify R2, achado 2.4)

- `Dashboard.tsx` vira a página "Meus Grupos": busca (`TextField`, mesmo comportamento de filtro client-side que `GroupList.tsx` já tem), botão "Novo grupo" (`Button` para `/groups/new`, hoje só em `GroupList.tsx`), cards de grupo restilizados (nome, descrição, ações: editar/participantes/despesas — mantém as 3 ações que `GroupList.tsx` tem hoje; a 4ª ação de `Dashboard.tsx` atual, ir para Resumo, é redundante com clicar no próprio card/nome do grupo, que passa a navegar para `/groups/:id/summary`), estado vazio com o texto que `ExpensesEntry.tsx:73` já usa ("Você ainda não participa de nenhum grupo").
- `GroupList.tsx` é apagado; toda referência a `/groups` (rota, `Link`/`navigate`) é atualizada para `/dashboard`.

## 4. `ExpenseManager.tsx` (specify R3, achados 2.3/2.6) — o que fica real vs. o que fica de fora

Refinamento importante sobre o que `specify.md` R3 descreveu como "visualizar/editar client-side": **"Editar despesa" não vira uma rota funcional nesta feature.** Não existe `update` no backend (achado 2.3/backlog `023`) — uma página "Editar despesa" com botão "Salvar alterações" que não persiste nada seria enganosa (o usuário acharia que salvou). Em vez disso:

- **Criar despesa**: sai do `Dialog` atual (`ExpenseManager.tsx:412-502`) e vira rota de página cheia `/groups/:id/expenses/new` (mockup tile 3) — os mesmos campos, o mesmo `handleSaveExpense`/`POST /api/expenses` (que já funciona), só a casca visual muda de modal para página.
- **Visualizar despesa**: rota nova `/groups/:id/expenses/:expenseId` (mockup tile 2), **somente leitura** — busca a despesa dentro do array `expenses` já carregado do mês corrente (mesmo hook de carregamento de hoje). Se o usuário chegar direto nessa URL (refresh, link direto) e a despesa não estiver no mês atualmente carregado, mostra estado "Despesa não encontrada" com link de volta para a listagem — trade-off explícito de fazer isso 100% client-side sem endpoint `show` (registrado também no backlog `023` como motivação).
- **"Editar"**: o botão que aparece na tela de Visualizar (mockup tile 2) **não é incluído nesta feature** — sem endpoint de update, não há o que editar de verdade. Fica pendente do backlog `023`.
- **Excluir**: continua sendo só o fluxo que já existe — `stopRecurrence`, disponível apenas para despesas `isFixed` (mesma condição de hoje, `ExpenseManager.tsx:394`). O diálogo atual ("Remover despesa fixa" / "a partir deste mês" / "a partir do mês que vem") ganha o visual novo (mockup tiles 5/6: confirmação + toast de sucesso), mas mantém as mesmas duas opções — não vira um "Excluir" genérico de qualquer despesa.
- **Listagem**: cards em vez de `Table`, com badge de tipo (`isFixed` → "Fixa"/"Variável", reaproveitando o mesmo campo que a projeção de despesas Fixa já usa), busca por descrição e filtro Todas/Fixas/Variáveis — os dois **client-side**, sobre `expenses` do mês já carregado (`Array.filter`, sem chamada nova à API). Paginação real fica de fora (backlog `026`); se a lista for longa, mostra tudo (sem "Mostrando 1 a 5 de N").
- **Estado vazio**: reaproveita a condição já existente (`expenses.length === 0`), só com o visual do mockup tile 7.

## 5. `GroupMembersForm.tsx` (specify R4, achado 2.7)

- Lista de membros vira cards/lista com `Avatar` de iniciais (mesmo `getInitials` do item 1) + e-mail, em vez da `<table>` HTML crua atual (`GroupMembersForm.tsx:104-123`).
- Formulário de adicionar por e-mail mantém os mesmos campos/`handleAddMember`, só reestilizado (card, mesma paleta).
- Layout de duas colunas (lista | formulário) mantido, só com os componentes novos.

## 6. `GroupForm.tsx` (specify R5)

- Mesmo componente para criar (`/groups/new`, dentro de `SimpleShellLayout`) e editar (`/groups/:id/edit`, dentro de `GroupShellLayout`) — só a casca ao redor muda conforme a rota (item 2), o componente em si não precisa saber qual shell o envolve.
- Formulário em `Card` centralizado, mesmos 3 campos (nome, descrição, dia de fechamento) e mesmo `handleSubmit`/validação de hoje — sem campo novo.

## 7. `ExpensesEntry.tsx` (specify R6)

- Mesma lógica (redireciona se só 1 grupo, senão lista para escolher) dentro de `SimpleShellLayout`, cards restilizados no mesmo padrão do item 3 (sem busca/botão "novo grupo", que não fazem sentido aqui).

## 8. Responsividade

- Mesmo padrão das duas features anteriores: `GroupShellLayout` oculta a sidebar abaixo do breakpoint `md` (mesma implementação de `GroupSummarySidebar`, que já faz isso); `SimpleShellLayout` não tem sidebar, então não há o que ocultar — só o cabeçalho precisa não quebrar em telas estreitas (`flexWrap`).

## 9. Testes (convenção do projeto, `05-context-frontend.md`)

- `GroupSummary.test.tsx` continua verde após a migração para `GroupShellLayout` (item 1) — se algum teste depender de estrutura DOM que mudar de lugar (ex.: sidebar renderizada por outro componente), ajustar seletor, não a asserção de dado.
- Testes novos/atualizados por página: `Dashboard.test.tsx` (consolidado, cobre o que `GroupList.test.tsx` cobria — se existir, é removido junto com `GroupList.tsx`), `ExpenseManager.test.tsx` (criar via página nova, visualizar client-side incluindo caso "não encontrada", filtro/busca client-side, excluir Fixa com o diálogo novo), `GroupMembersForm.test.tsx`, `GroupForm.test.tsx` (criar e editar).
- Checar se `GroupList.test.tsx` existe hoje antes de apagar — se existir, extrair os casos ainda válidos para o teste consolidado de `Dashboard.test.tsx` em vez de simplesmente descartá-los.

## 10. Ordem de execução

Dependência técnica real: os shells (item 1) e as rotas (item 2) precisam existir antes de qualquer página migrar para eles.

1. `GroupShellLayout`/`SimpleShellLayout` + migração de `GroupSummary.tsx` para o shell compartilhado (item 1) — pré-requisito de arquivo, e valida que a extração não quebrou o que já existe (testes de `GroupSummary` continuam verdes).
2. Reestruturar rotas em `App.tsx` (item 2) — ainda com as páginas antigas por dentro, só trocando o wrapper; cada página migra de fato nos itens seguintes.
3. Consolidar `Dashboard`/`GroupList` (item 3) — independente das demais páginas, pode ir em paralelo conceitualmente, mas entra aqui por ser a mais simples.
4. `GroupForm.tsx` (item 6) — pequeno, usado por dois shells diferentes, bom para validar que a mesma página funciona nos dois.
5. `GroupMembersForm.tsx` (item 5).
6. `ExpensesEntry.tsx` (item 7).
7. `ExpenseManager.tsx` (item 4) — o mais trabalhoso (rotas novas de criar/visualizar, remoção do modal), por último.
8. Responsividade (item 8) — ajuste final sobre os shells já em uso por todas as páginas.
9. Testes (item 9) — cobre o resultado final de 1-8.
