# Tasks — Atualização de Layout das Demais Páginas

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs a partir de `TASK-088` — maior ID já usado no projeto antes desta feature: `TASK-087` (`docs/feature/20260819-novo-layout-tela-entrada/tasks.md`).

Versão: 1.0 · Criado em: 20260820

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-088 | Extrair `GroupSummarySidebar`/`GroupSummaryHeader`/`getInitials` de `pages/summary/` para `layouts/group/`, generalizando o header para receber `title` como prop | frontend | plan.md §1 | nenhum | Concluída |
| TASK-089 | Criar `GroupShellLayout` (layout route com sidebar+header compartilhados, busca `groups`/`userName` uma vez, deriva `title` do item ativo da sidebar) e migrar `GroupSummary.tsx` para usá-lo | frontend | plan.md §1 | nenhum | Concluída |
| TASK-090 | Criar `SimpleShellLayout` (cabeçalho simples — logo + avatar do usuário, sem sidebar/seletor de grupo) | frontend | plan.md §1 | nenhum | Concluída — revisada após feedback do usuário: `SimpleShellLayout` ganhou o mesmo menu lateral do `GroupShellLayout` (ver `implementation.md`, linha "Correção pós-TASK-091") |
| TASK-091 | Reestruturar rotas em `App.tsx`: `/groups/:id/summary`, `/groups/:id/expenses`, `/groups/:id/members`, `/groups/:id/edit` sob `GroupShellLayout`; `/dashboard`, `/groups/new`, `/expenses` sob `SimpleShellLayout` | frontend | plan.md §2 | nenhum | Concluída |
| TASK-092 | Consolidar `Dashboard.tsx` (+ conteúdo de `GroupList.tsx`) numa única página "Meus Grupos" em `/dashboard`; remover `GroupList.tsx` e a rota `/groups`, atualizando referências | frontend | plan.md §3 | nenhum | Concluída |
| TASK-093 | Reestilizar `GroupForm.tsx` (criar e editar) como formulário em card, mesmos campos e validação de hoje | frontend | plan.md §6 | nenhum | Concluída |
| TASK-094 | Reestilizar `GroupMembersForm.tsx` — lista de membros com `Avatar` de iniciais + formulário de adicionar por e-mail | frontend | plan.md §5 | nenhum | Concluída |
| TASK-095 | Reestilizar `ExpensesEntry.tsx` (cards de grupo, mesmo comportamento de redirecionamento/escolha) | frontend | plan.md §7 | nenhum | Pendente |
| TASK-096 | `ExpenseManager.tsx`: extrair criação de despesa do `Dialog` atual para rota de página cheia `/groups/:id/expenses/new`, reaproveitando `handleSaveExpense`/`POST /api/expenses` existente | frontend | plan.md §4 | nenhum | Pendente |
| TASK-097 | `ExpenseManager.tsx`: criar rota somente-leitura `/groups/:id/expenses/:expenseId` (busca no array já carregado do mês; trata caso "despesa não encontrada" com link de volta) | frontend | plan.md §4 | nenhum | Pendente |
| TASK-098 | `ExpenseManager.tsx`: restilizar listagem em cards (badge de tipo Fixa/Variável) com busca e filtro por tipo client-side sobre os dados já carregados | frontend | plan.md §4 | nenhum | Pendente |
| TASK-099 | `ExpenseManager.tsx`: restilizar o fluxo de exclusão de despesa Fixa (`stopRecurrence`) com diálogo de confirmação e toast de sucesso no novo visual | frontend | plan.md §4 | nenhum | Pendente |
| TASK-100 | Responsividade: confirmar que `GroupShellLayout` oculta a sidebar abaixo do breakpoint `md` (herdado de `GroupSidebar`) e que o cabeçalho de `SimpleShellLayout` não quebra em telas estreitas | frontend | plan.md §8 | nenhum | Pendente |
| TASK-101 | Testes frontend: ajustar `GroupSummary.test.tsx` pós-migração para o shell compartilhado; criar/atualizar testes de `Dashboard`, `GroupForm`, `GroupMembersForm`, `ExpensesEntry` e `ExpenseManager` (criar via página, visualizar incluindo "não encontrada", filtro/busca, exclusão de Fixa) | frontend | plan.md §9 | nenhum | Pendente |

## Critérios de aceite

- **TASK-088**: `layouts/group/GroupSidebar.tsx`/`GroupHeader.tsx`/`getInitials.ts` existem com o mesmo comportamento visual de hoje; `GroupHeader` aceita `title` como prop (sem valor fixo "Resumo do grupo" hardcoded); `pages/summary/` não tem mais esses 3 arquivos; `npx tsc --noEmit` limpo.
- **TASK-089**: `/groups/:id/summary` renderizado via `GroupShellLayout` mostra exatamente a mesma sidebar/header de antes (`read_page` sem diff perceptível); `GroupSummary.tsx` não busca mais `groups`/`userName` por conta própria (removido o `useEffect` correspondente); `GroupSummary.test.tsx` continua verde.
- **TASK-090**: `SimpleShellLayout` renderiza logo + avatar/nome do usuário (mock de `GET /api/me`), sem sidebar nem seletor de grupo; `resize_window` abaixo de `md` não quebra o cabeçalho (sem overflow horizontal).
- **TASK-091**: nenhuma rota privada referencia mais `InternalLayout` em `App.tsx`; acessar cada rota migrada (`read_page`) mostra o shell correto (sidebar para as 4 rotas de grupo, cabeçalho simples para as 3 sem grupo); nenhuma rota quebra (todas continuam respondendo 200/renderizando).
- **TASK-092**: `/dashboard` mostra busca, botão "Novo grupo", cards de grupo com as 3 ações (editar/participantes/despesas) e clique no card navega para `/groups/:id/summary`; estado vazio mostra o texto correto; acessar `/groups` não resolve mais (rota removida); nenhum `Link`/`navigate('/groups')` sobrevive no código (`grep` confirma).
- **TASK-093**: formulário em card nos dois modos (criar e editar), mesmos 3 campos, `POST`/`PUT` continuam funcionando (`read_network_requests` confirma a chamada correta em cada modo).
- **TASK-094**: lista de membros mostra avatar de iniciais + e-mail; adicionar membro por e-mail continua funcionando (`read_network_requests` confirma `POST /api/groups/:id/members`).
- **TASK-095**: cards de grupo restilizados; comportamento de redirecionamento automático (1 grupo) e escolha (múltiplos grupos) inalterado.
- **TASK-096**: `/groups/:id/expenses/new` renderiza o formulário de criação em página cheia (não mais `Dialog`); salvar dispara o mesmo `POST /api/expenses` de hoje e volta para a listagem; `Dialog` antigo removido de `ExpenseManager.tsx`.
- **TASK-097**: `/groups/:id/expenses/:expenseId` mostra os dados da despesa (somente leitura, sem botão "Editar" funcional); acessar um `:expenseId` que não está no mês carregado mostra "Despesa não encontrada" com link de volta, sem lançar erro no console.
- **TASK-098**: listagem em cards com badge Fixa/Variável; digitar no campo de busca filtra por descrição sem nova chamada de rede (`read_network_requests` sem requisição nova); clicar nas abas Todas/Fixas/Variáveis filtra corretamente.
- **TASK-099**: clicar em excluir (só visível para despesa Fixa) abre diálogo de confirmação no novo visual com as mesmas 2 opções de hoje; confirmar dispara `POST /expenses/:id/stop-recurrence` e mostra toast de sucesso.
- **TASK-100**: `resize_window`/`javascript_tool` abaixo de `md` oculta a sidebar em todas as páginas que usam `GroupShellLayout`; cabeçalho de `SimpleShellLayout` sem overflow horizontal em mobile.
- **TASK-101**: `npx vitest run` verde cobrindo os pontos acima; nenhum teste existente foi removido sem seus casos válidos serem preservados em outro arquivo (achado do plan.md §9 sobre `GroupList.test.tsx`, se existir).
