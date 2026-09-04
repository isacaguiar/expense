# Plan — Relatórios: histórico de ciclos fechados do grupo

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260827

---

## 1. Endpoint de listagem de ciclos passados (specify §2.1, §2.3)

- Nova rota `GET /groups/{groupId}/expenses/cycles` em `backend/routes/api.php`, dentro do mesmo grupo `jwt.auth` das demais rotas de `ExpenseController` (ao lado de `summary`/`close`/`reopen`, linha ~46).
- Novo método `ExpenseController::cycleHistory($groupId, Request $request)`:
  - `$group = Group::findOrFail($groupId); $this->authorizeGroupMembership($group);` — mesmo padrão de `summary()`.
  - Calcula o início da competência vigente: `$currentStart = BillingCycle::cycleFor($group->closing_day, Carbon::now())['start'];`.
  - `GroupCycleSnapshot::where('group_id', $groupId)->where('cycle_start', '<', $currentStart->toDateString())->orderByDesc('cycle_start')->paginate(10)` — paginação nativa do Laravel, serializada direto via `response()->json($paginator)` (envelope plano do `LengthAwarePaginator`: `data`, `current_page`, `per_page`, `total`, `last_page`, etc. — não o formato aninhado `data`/`links`/`meta` de um `JsonResource`, que não está em uso aqui), página via `?page=N` (padrão do framework, sem parâmetro custom).
  - Transforma a coleção paginada (`$paginator->getCollection()->transform(...)`) para o mesmo formato de `summary()`: cada item vira `{ cycle: { start, end, status: 'closed' }, totals, expenses, balances, settlements }` — sempre `status: 'closed'` porque a query já filtra só `cycle_start` anterior à competência vigente (nunca inclui a atual, fechada manualmente ou não).
  - Retorna `response()->json($paginator)`.
- Por quê essa abordagem e não outra: reaproveita o mesmo formato de objeto que o frontend já sabe renderizar (`Summary` de `useGroupCycle.ts`) — evita criar um segundo formato de resposta só para o histórico. Paginação nativa do Laravel evita reinventar contrato de paginação (consistente com backlog `026`, que já cogita paginação server-side no projeto).

## 2. Extrair `CycleDetailPanel` reutilizável de `GroupSummary.tsx` (specify §2.2)

- Extrai de `frontend/src/pages/GroupSummary.tsx:77-179` (chip de status + 3 cards de totais + lista de despesas + `SummarySidePanel`) para um novo componente `frontend/src/components/CycleDetailPanel.tsx`, recebendo `summary: Summary` (mesmo tipo de `useGroupCycle.ts`) como única prop.
- `GroupSummary.tsx` passa a importar `CycleDetailPanel` e usá-lo, mantendo só o cabeçalho de navegação por ciclo (setas + label de período, linhas 53-75) fora do componente extraído — esse cabeçalho não faz sentido no histórico (que navega por lista, não por seta).
- Nenhuma mudança de comportamento visual em `GroupSummary` — só reorganização de código. `GroupSummary.test.tsx` (se existir) não deve quebrar sem alteração de asserções.
- Por quê essa abordagem e não outra: evita duplicar a renderização de totais/despesas/saldos entre a tela de Resumo (competência vigente) e a nova tela de Relatórios (ciclo passado selecionado) — a lógica de apresentação é idêntica, só muda a origem do dado (`useGroupCycle` vs. a lista de histórico) e a ausência do cabeçalho de navegação por seta.

## 3. Tela de Relatórios: lista + detalhe (specify §2.2)

- Novo hook `frontend/src/hooks/useGroupCycleHistory.ts`: busca `GET /groups/{groupId}/expenses/cycles` (paginado), expõe `{ cycles: Summary[], loading, error, page, totalPages, setPage }` — mesmo padrão de tratamento de erro 401 (`navigate('/', { replace: true })`) já usado em `useGroupCycle.ts`.
- Nova página `frontend/src/pages/GroupReports.tsx`:
  - Lista os ciclos (`cycle.start`–`cycle.end`, `totals.total`) em uma `List`/`Paper` (mesmo padrão visual de listas já usado em `GroupSummary`), mais recente primeiro, com paginação via `Pagination` do MUI (`@mui/material`, já dependência do projeto) — não há paginação de UI implementada em nenhuma outra tela hoje (confirmado por busca no código; o item de backlog `026` descreve paginação como algo cogitado, não uma implementação existente a reaproveitar), então este é o primeiro uso do componente no frontend.
  - Estado local `selectedCycle: Summary | null` — clicar num item da lista seleciona aquele ciclo; `CycleDetailPanel` (item 2 acima) renderiza o ciclo selecionado abaixo/ao lado da lista.
  - Vazio (nenhum ciclo no histórico ainda): mensagem "Nenhum ciclo fechado ainda." — não é erro, é estado esperado para grupo novo.
- `frontend/src/layouts/group/GroupSidebar.tsx:33`: item "Relatórios" ganha `to: \`/groups/${groupId}/reports\`` (mesmo padrão dos outros itens de `groupNavItems`).
- `frontend/src/App.tsx`: nova rota `<Route path="/groups/:id/reports" element={<GroupReports />} />` dentro do `GroupShellLayout` (ao lado de `summary`/`payments`/`expenses`/`members`).
- Por quê essa abordagem e não outra: lista + detalhe numa página só (sem rota própria por ciclo, ex. `/groups/:id/reports/:cycleStart`) porque o volume esperado de ciclos por grupo é pequeno (um por mês) — não há necessidade de URL profunda/compartilhável para um ciclo específico ainda; se isso mudar, é ajuste incremental futuro, não motivo para adiar esta feature.

## N. Ordem de execução

1. **§1 (endpoint backend)** — independente do frontend, pode ser feito primeiro e testado isoladamente (PHPUnit).
2. **§2 (extrair CycleDetailPanel)** — independente de §1 (só mexe em código já existente do frontend), mas pré-requisito de §3, que consome o componente extraído.
3. **§3 (tela de Relatórios)** — depende de §1 (endpoint) e §2 (componente) completos; é o item que entrega a feature de fato.

Ordem de tasks em `tasks.md`: §1 → §2 → §3 (sequencial — §1 e §2 poderiam ser paralelas, mas a feature é pequena o suficiente para uma única cadeia de branches, mesmo padrão já adotado nas features anteriores de navegação mobile).
