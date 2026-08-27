# Tasks — Relatórios: histórico de ciclos fechados do grupo

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260827

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-209 | Criar endpoint `GET /groups/{groupId}/expenses/cycles` (histórico paginado) | backend | plan.md §1 | nenhum | Pendente |
| TASK-210 | Extrair `CycleDetailPanel` reutilizável de `GroupSummary.tsx` | frontend | plan.md §2 | nenhum | Pendente |
| TASK-211 | Criar tela de Relatórios (lista + detalhe) e ligar rota/sidebar | frontend | plan.md §3 | nenhum | Pendente |

## Critérios de aceite

- **TASK-209**: `GET /groups/{groupId}/expenses/cycles` exige `jwt.auth` e membership do grupo (403/404 conforme já testado em `summary()`). Para um grupo com N `GroupCycleSnapshot` cuja `cycle_start` é anterior à competência vigente, retorna paginado (envelope Laravel `data`/`links`/`meta`), ordenado do mais recente para o mais antigo, cada item no formato `{ cycle: { start, end, status: 'closed' }, totals, expenses, balances, settlements }`. A competência vigente (mesmo com `closed_manually_at` setado) nunca aparece na lista. Teste PHPUnit novo cobrindo: grupo sem nenhum ciclo passado (lista vazia), grupo com ciclos passados (ordem e paginação), grupo com fechamento manual da competência vigente (não aparece na lista). `php artisan test` verde.
- **TASK-210**: `CycleDetailPanel` recebe `summary: Summary` e renderiza chip de status + 3 cards de totais + lista de despesas + `SummarySidePanel`, idêntico ao que `GroupSummary.tsx` renderizava antes da extração. `GroupSummary.tsx` usa o novo componente e mantém o cabeçalho de navegação por seta fora dele. Nenhuma asserção de teste existente de `GroupSummary` muda de comportamento esperado (mesmo texto/estrutura visível). `npx vitest run` no frontend passa.
- **TASK-211**: Nova página `GroupReports` acessível em `/groups/:id/reports` (rota registrada em `App.tsx`, dentro do `GroupShellLayout`); item "Relatórios" da sidebar (`GroupSidebar.tsx`) deixa de ser desabilitado e navega para essa rota. A página lista os ciclos do hook `useGroupCycleHistory` (mais recente primeiro, com paginação via `Pagination` do MUI), mostra "Nenhum ciclo fechado ainda." quando a lista vem vazia, e selecionar um item da lista renderiza `CycleDetailPanel` com o detalhe daquele ciclo. Teste novo (`GroupReports.test.tsx`) cobre: lista renderizada a partir do mock do endpoint, seleção de um ciclo exibindo o detalhe, e o estado vazio. `npx tsc --noEmit` sem erro; `npx vitest run` (suíte completa do frontend) passa.
