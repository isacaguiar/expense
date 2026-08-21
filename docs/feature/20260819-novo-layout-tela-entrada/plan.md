# Plan — Novo Layout da Tela de Resumo (Entrada pós-login)

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260819

---

## 1. Layout de duas áreas, isolado da `Navbar`/`InternalLayout` global (specify R1)

- `GroupSummary.tsx` continua sendo o componente da rota `/groups/:id/summary` — nenhuma rota nova.
- **Decisão de roteamento**: hoje `/groups/:id/summary` está aninhada em `<Route element={<InternalLayout />}>` (`App.tsx:23-36`), que renderiza `Navbar` (AppBar horizontal) antes do `<Outlet />`. Se a sidebar nova for só adicionada dentro de `GroupSummary`, a tela ficaria com **dois** menus (a AppBar antiga em cima + a sidebar nova do mockup) — não é o que o mockup mostra. Solução: mover a rota `/groups/:id/summary` para **fora** de `<InternalLayout />`, mas mantendo-a dentro de `<Route element={<RequireAuth />}>` (autenticação continua obrigatória). Isso é uma mudança só em `App.tsx` (onde a rota está aninhada na árvore) — **zero linhas alteradas em `Navbar.tsx`/`InternalLayout.tsx`**, e nenhuma outra rota muda de comportamento. Cumpre o espírito do specify R1 (não redesenhar a navegação global) sem o efeito colateral visual do menu duplicado.
- Dentro de `GroupSummary.tsx`: `Box` raiz `display: flex`, `minHeight: '100vh'` — filho 1 é a sidebar (largura fixa, ex. `280px`), filho 2 é a área de conteúdo (`flex: 1`, com o cabeçalho + cards + listas de hoje, restilizados).
- Extrair subcomponentes em `frontend/src/pages/summary/` (pasta nova, mesmo padrão de `frontend/src/pages/login/`): `GroupSummarySidebar.tsx`, `GroupSummaryHeader.tsx`. `GroupSummary.tsx` mantém todo o estado e fetch já existentes (`summary`, `loading`, `error`, `cyclesAgo`, `groups`) e só passa dados via props para os subcomponentes — nenhuma mudança de estado/efeitos além do necessário para o cabeçalho (item 3).

## 2. Sidebar de navegação (specify R2, achados 2.3/2.6)

- `GroupSummarySidebar.tsx` recebe `groupId: string` via prop.
- Topo: wordmark "Shared Expense" — reaproveita `logoIcon` (`frontend/src/assets/images/logo-expense.png`) + `Typography` Poppins, mesma estrutura de `LoginBrandingPanel.tsx:45-61` (import direto da paleta compartilhada, ver item 6).
- Lista de 6 itens, array de objetos `{ label, icon, to?: string }` (sem `to` = item placeholder):
  - Resumo → `/groups/${groupId}/summary` (`GroupsOutlined` ou ícone equivalente já usado)
  - Despesas → `/groups/${groupId}/expenses`
  - Participantes → `/groups/${groupId}/members`
  - Pagamentos, Relatórios, Configurações → sem `to`, `href="#"`, estilo visualmente "desabilitado" (`opacity` reduzida + `cursor: default`), sem `onClick`.
- Item ativo (rota atual): `useLocation()` do `react-router-dom`, comparação de `pathname` com `item.to`, destaque com `loginColors`/`brandColors` (fundo `primaryLight`, texto/ícone `primary`) — mesmo padrão de estado ativo já comum em sidebars MUI (`Box`/`ListItemButton` com `selected`).
- Componente usa `List`/`ListItemButton`/`ListItemIcon`/`ListItemText` do MUI (import novo, já disponível na dependência `@mui/material` existente).

## 3. Cabeçalho da área de conteúdo (specify R3, achados 2.4/2.5)

- `GroupSummaryHeader.tsx` recebe `groups`, `groupId`, `onGroupChange` via props — o `Select` de troca de grupo é o mesmo já existente em `GroupSummary.tsx:126-139`, só movido para este subcomponente e restilizado (sem `<Typography variant="h4">` ao lado, que sai daqui — o título agora vive só como contexto visual da sidebar/página).
- Sino decorativo: `IconButton` com `NotificationsNoneOutlined` (`@mui/icons-material`), sem `onClick`, `aria-label="Notificações"` — puramente visual (achado 2.4, backlog `020`).
- Bloco de usuário: novo `useEffect` em `GroupSummary.tsx` (mesmo padrão de fetch dos demais, `axios.get<{ name: string; email: string }>('${API_BASE_URL}/api/me', { headers })`) guardando `{ name }` em um `useState` novo; passado como prop para o header. Iniciais calculadas com uma função pura local (`getInitials(name)`, primeira letra das duas primeiras palavras, maiúscula) — sem dependência nova. `Avatar` do MUI com as iniciais como children + `Typography` com o nome ao lado.

## 4. Cards de totais com percentual (specify R4, achado 2.7)

- Mesmos 3 `Card`/`CardContent` de `GroupSummary.tsx:175-210`, restilizados (`borderRadius`, sem `elevation` padrão — sombra suave via `sx`).
- Subtítulo do card "Total de despesas": passa a reaproveitar o texto já formatado da faixa do ciclo (`${formatDate(summary.cycle.start)} – ${formatDate(summary.cycle.end)}`, já calculado em `GroupSummary.tsx:164`) em vez de "Este mês" — evita reintroduzir a confusão "ciclo vs. mês calendário" já resolvida na feature anterior.
- Percentual sob "Pago" e "A pagar": calculado inline no componente, `summary.totals.total > 0 ? Math.round((summary.totals.paid / summary.totals.total) * 100) : 0` (e equivalente para `pending`) — puramente derivado, sem novo campo de API, sem estado novo.

## 5. Lista de despesas e saldos por pessoa (specify R5)

- "Despesas do ciclo" (nome mantido, achado 2.7): mesma origem de dados (`summary.expenses`), troca `List`/`ListItem` simples por um cartão por item com ícone à esquerda — usa o campo `isFixed` já existente no tipo `SummaryExpense` para escolher o ícone (`AutorenewOutlined` para despesa Fixa, `ReceiptOutlined` para as demais), sem novo dado de backend.
- "Saldos por pessoa": mesma origem (`summary.balances`), cada linha ganha um `Avatar` com iniciais do `balance.name` (mesma função `getInitials` do item 3, extraída para um util compartilhado dentro de `pages/summary/` para não duplicar) antes do nome; cor do valor mantém a lógica atual (`success.main`/`error.main`/`text.secondary` conforme sinal do saldo).

## 6. Paleta de cores compartilhada (specify R6, achado 2.6)

- Mover `frontend/src/pages/login/colors.ts` → `frontend/src/theme/brandColors.ts`, renomeando o export `loginColors` → `brandColors` (mesmos 4 valores, sem mudar nenhuma cor). Atualizar os 2 imports existentes (`LoginBrandingPanel.tsx`, `LoginFormCard.tsx`) para o novo caminho/nome — troca mecânica, sem mudança de comportamento visual (mesma cor, mesmo hex).
- `pages/summary/*.tsx` importa de `frontend/src/theme/brandColors.ts` diretamente — sem acoplamento a `pages/login/`.
- `frontend/src/theme.ts` global permanece sem diff (confirmar com `git diff` vazio no arquivo ao final da feature).

## 7. Responsividade (specify — decisão adiada para o plan.md)

- Mesmo padrão de breakpoint da tela de login: abaixo de `md`, a sidebar fica oculta (`display: { xs: 'none', md: 'flex' }`) e a área de conteúdo ocupa 100% da largura.
- Sem substituto de navegação (drawer/hambúrguer) nesta feature — gap conhecido e aceito, registrado como ideia de backlog `022` (prioridade MEDIA, por ser regressão de usabilidade nesta tela especificamente).

## 8. Testes (convenção do projeto, `05-context-frontend.md`)

- `frontend/src/pages/GroupSummary.test.tsx` já existe e cobre o comportamento funcional atual (ciclo, totais, despesas, saldos, troca de grupo) — deve continuar passando sem alteração de asserções sobre dados/lógica; ajustar apenas seletores que dependerem de estrutura DOM que mudar (ex.: se algum teste usa `getByRole('heading', { name: /resumo do grupo/i })`, confirmar que o texto/role novo ainda bate).
- Adicionar casos novos: (a) sidebar renderiza os 6 itens, com Resumo/Despesas/Participantes como links reais (`href` correto) e Pagamentos/Relatórios/Configurações sem navegação; (b) cabeçalho mostra nome do usuário obtido de `GET /api/me` (mock) e iniciais corretas no `Avatar`; (c) cards mostram o percentual calculado corretamente a partir de `totals` mockados (incluindo caso `total === 0`, sem `NaN`/divisão por zero).

## 9. Ordem de execução

Há uma dependência técnica real entre os itens: a estrutura (item 1) e a paleta compartilhada (item 6) precisam existir antes dos demais, que só reorganizam/restilizam conteúdo dentro dela. Ordem em `tasks.md`:

1. Mover paleta para local compartilhado (item 6) — feito primeiro e isolado, é só um rename/move mecânico, fácil de verificar sozinho (tela de login continua idêntica).
2. Reestruturar rota + layout de duas áreas, extraindo `GroupSummarySidebar`/`GroupSummaryHeader` como cascas vazias (item 1) — pré-requisito de arquivo para o resto.
3. Preencher a sidebar (item 2).
4. Preencher o cabeçalho, incluindo o fetch de `GET /api/me` (item 3).
5. Cards com percentual (item 4).
6. Lista de despesas e saldos restilizados (item 5).
7. Responsividade (item 7) — ajuste final sobre o layout já montado.
8. Testes (item 8) — cobre o resultado final de 1-7.
