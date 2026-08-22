# Tasks — Acerto de Contas por Ciclo

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260822

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-001 | `computeCycleSummary()` calcula `settlements` par-a-par líquido por `user_id` | backend | plan.md §1 | nenhum | Concluída |
| TASK-002 | Migration aditiva `settlements` em `ex_group_cycle_snapshots` + fillable/cast em `GroupCycleSnapshot` | backend | plan.md §2 | nenhum (migration aditiva local) | Concluída |
| TASK-003 | `summary()`/`cycleSnapshotFor()`/`close()`/`reopen()` expõem e persistem `settlements` | backend | plan.md §2 | nenhum | Concluída |
| TASK-004 | Testes backend de `settlements` (reconciliação, netting, imutabilidade) | backend | plan.md §3 | nenhum | Concluída |
| TASK-005 | Bloco "Quem paga a quem" em `GroupSummary.tsx` (`SettlementList.tsx` + tipos em `useGroupCycle.ts`) | frontend | plan.md §4 | nenhum | Concluída |
| TASK-006 | Teste frontend do bloco "Quem paga a quem" | frontend | plan.md §5 | nenhum | Concluída |

## Critérios de aceite

- **TASK-001**: para o cenário desta conversa (Isac paga Água×2/Luz/Placa Solar com João e Maria como participantes variados, João paga Mercado dividido entre os 4; saldos líquidos 487,50/87,50/-462,50/-112,50), `settlements` (direção sempre `from` = devedor → `to` = credor, já líquida por par) contém exatamente 4 entradas: `{from: Maria, to: Isac, amount: 350}`, `{from: João, to: Isac, amount: 137.50}` *(net entre o que Isac deve a João no Mercado e o que João deve a Isac em Água#2+Placa Solar)*, `{from: Maria, to: João, amount: 112.50}` *(cota de Maria no Mercado)*, `{from: novemaxdev, to: João, amount: 112.50}`; para todo `user_id`, soma de `amount` onde `to_user_id = X` menos soma onde `from_user_id = X` bate exatamente com `balance` de X em `balances[]`.
- **TASK-002**: `php artisan migrate` local aplica sem erro; `GroupCycleSnapshot` existente (criado antes da migration) continua legível com `settlements = null`; `GroupCycleSnapshot::create([...'settlements' => [...]])` via tinker persiste e recupera como array.
- **TASK-003**: `GET /groups/{id}/expenses/summary` (ciclo aberto, fechado automaticamente e fechado manualmente) devolve `settlements` no JSON nos 3 casos; `POST /groups/{id}/expenses/close` grava `settlements` no snapshot (`GroupCycleSnapshot::find(...)->settlements` não é `null` depois); ciclo fechado automaticamente consultado 2x devolve o mesmo `settlements` (imutável, não recalcula).
- **TASK-004**: `php artisan test` verde cobrindo: reconciliação `settlements` × `balances` para todo `user_id`; nenhuma entrada com `from_user_id === to_user_id`; par que se deve mutuamente em despesas diferentes gera só 1 entrada (a diferença líquida); cenário de regressão com os 4 membros e 5 despesas desta conversa batendo com os valores do critério de TASK-001; ciclo fechado não recalcula `settlements` após nova despesa criada depois do fechamento.
- **TASK-005**: abrir `/groups/{id}/summary` de um grupo com despesas cruzadas mostra bloco "Quem paga a quem" com uma linha por item de `settlements`, nome resolvido via `balances`, valor formatado em R$; grupo sem `settlements` (lista vazia) não mostra o bloco.
- **TASK-006**: `npx vitest run` verde cobrindo o cenário de TASK-005 (mock de `settlements` na resposta da API, texto renderizado conferido via testing-library).
