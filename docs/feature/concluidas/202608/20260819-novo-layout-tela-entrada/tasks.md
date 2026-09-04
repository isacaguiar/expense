# Tasks — Novo Layout da Tela de Resumo (Entrada pós-login)

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs a partir de `TASK-078` — maior ID já usado no projeto antes desta feature: `TASK-077` (`docs/feature/concluidas/202608/20260819-novo-layout-tela-login/tasks.md`).

Versão: 1.0 · Criado em: 20260819

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-078 | Mover `loginColors` de `pages/login/colors.ts` para `theme/brandColors.ts` (renomeado `brandColors`), atualizando os imports existentes em `LoginBrandingPanel.tsx`/`LoginFormCard.tsx` | frontend | plan.md §6 | nenhum | Concluída |
| TASK-079 | Mover a rota `/groups/:id/summary` para fora de `InternalLayout` em `App.tsx` (mantendo dentro de `RequireAuth`), removendo a `Navbar` herdada dessa tela sem alterar `Navbar.tsx`/`InternalLayout.tsx` nem nenhuma outra rota | frontend | plan.md §1 | nenhum | Concluída |
| TASK-080 | Reestruturar `GroupSummary.tsx` em layout de duas áreas (sidebar + conteúdo), extraindo `GroupSummarySidebar.tsx`/`GroupSummaryHeader.tsx` em `pages/summary/` como componentes-casca (props definidas, sem conteúdo final ainda) | frontend | plan.md §1 | nenhum | Concluída |
| TASK-081 | Implementar `GroupSummarySidebar` — wordmark "Shared Expense", 6 itens de navegação (Resumo/Despesas/Participantes navegáveis; Pagamentos/Relatórios/Configurações como placeholder) e destaque do item ativo | frontend | plan.md §2 | nenhum | Concluída |
| TASK-082 | Implementar `GroupSummaryHeader` — seletor de grupo (migrado de `GroupSummary.tsx`), sino decorativo e bloco com nome/iniciais do usuário logado via `GET /api/me` | frontend | plan.md §3 | nenhum | Concluída |
| TASK-083 | Cards de totais: subtítulo do card "Total de despesas" passa a usar a faixa de datas do ciclo, e adicionar percentual calculado sob "Pago"/"A pagar" | frontend | plan.md §4 | nenhum | Concluída |
| TASK-084 | Restilizar a lista "Despesas do ciclo" com ícone por tipo de despesa (`isFixed`) em card por item | frontend | plan.md §5 | nenhum | Concluída |
| TASK-085 | Restilizar o bloco "Saldos por pessoa" com `Avatar` de iniciais por membro | frontend | plan.md §5 | nenhum | Concluída |
| TASK-086 | Responsividade: ocultar `GroupSummarySidebar` abaixo do breakpoint `md`, conteúdo ocupa largura total | frontend | plan.md §7 | nenhum | Concluída |
| TASK-087 | Atualizar/estender `GroupSummary.test.tsx`: manter cobertura funcional existente e cobrir sidebar (itens navegáveis vs. placeholder), header (nome/iniciais via `/api/me` mockado) e percentual dos cards (incluindo `total === 0`) | frontend | plan.md §8 | nenhum | Concluída |

## Critérios de aceite

- **TASK-078**: `frontend/src/theme/brandColors.ts` exporta `brandColors` com os mesmos 4 valores de hoje; `pages/login/colors.ts` não existe mais; `LoginBrandingPanel.tsx`/`LoginFormCard.tsx` importam do novo caminho; `npx vitest run` dos testes de login continua verde sem alteração de asserção (mesma cor renderizada).
- **TASK-079**: em `App.tsx`, a rota `/groups/:id/summary` não está mais aninhada em `<Route element={<InternalLayout />}>`; acessar a URL diretamente (`read_page`) não mostra a `AppBar`/`Navbar` antiga; as demais rotas (`/dashboard`, `/groups`, `/groups/:id/expenses` etc.) continuam mostrando a `Navbar` normalmente (sem diff em `Navbar.tsx`/`InternalLayout.tsx` — `git diff` vazio nesses 2 arquivos).
- **TASK-080**: `/groups/:id/summary` renderiza uma `Box` com duas áreas lado a lado (sidebar + conteúdo), mesmo que a sidebar/cabeçalho ainda estejam com conteúdo mínimo/placeholder nesta task; nenhuma regressão nos dados exibidos (cards, lista de despesas, saldos continuam com os mesmos valores de antes).
- **TASK-081**: sidebar mostra o wordmark e os 6 itens; clicar em Resumo/Despesas/Participantes navega para a URL correta (`read_page`/`computer` confirmam `href`); Pagamentos/Relatórios/Configurações não têm `href` de rota real (só `href="#"` ou nenhum) e não disparam navegação ao clicar; o item correspondente à rota atual está visualmente destacado.
- **TASK-082**: seletor de grupo continua funcionando (trocar grupo navega para `/groups/{novoId}/summary`, mesmo comportamento de hoje); sino é `IconButton` sem `onClick` (clicar não dispara nada, `read_network_requests` sem nova chamada); nome do usuário autenticado aparece no cabeçalho (mock de `GET /api/me` em teste) com `Avatar` mostrando as iniciais corretas.
- **TASK-083**: com `totals = { total: 100, paid: 60, pending: 40 }`, o card "Pago" mostra "60% do total" e "A pagar" mostra "40% do total"; com `total: 0`, nenhum card quebra (sem `NaN`/`Infinity` no DOM); subtítulo do card "Total de despesas" mostra a mesma faixa de datas já exibida na navegação de ciclo, não mais "Este mês".
- **TASK-084**: cada despesa da lista mostra um ícone diferente conforme `isFixed` (`true`/`false`), sem mudança nos dados exibidos (descrição, valor, pagador, participantes, status).
- **TASK-085**: cada linha de "Saldos por pessoa" mostra um `Avatar` com as iniciais do nome, sem mudança no valor/sinal exibido.
- **TASK-086**: `resize_window`/`javascript_tool` para largura abaixo do breakpoint `md` oculta a sidebar por completo e a área de conteúdo ocupa a largura total sem overflow horizontal; acima de `md`, as duas áreas voltam a aparecer lado a lado.
- **TASK-087**: `npx vitest run` verde cobrindo os pontos acima; nenhum teste existente de `GroupSummary.test.tsx` foi removido ou teve sua asserção de dados enfraquecida para "passar por passar".
