# Specify — Relatórios: histórico de ciclos fechados do grupo

> Feature: cria a tela de "Relatórios" do grupo (hoje item desabilitado na sidebar, `GroupSidebar.tsx:33` — sem `to`), mostrando o histórico de ciclos de fechamento já fotografados (`GroupCycleSnapshot`), com totais/saldos/liquidação de cada ciclo passado. Origem: promoção do item de backlog `018` (`docs/backlog/summary-tela-relatorios.md`), reenquadrado em 2026-08-22 após a feature `docs/feature/concluidas/202608/20260822-acerto-de-contas-ciclo/` confirmar que o mecanismo de ciclo (`BillingCycle`/`GroupCycleSnapshot`) é a fonte de verdade do produto — não o antigo `GroupExpenseReportController` (ano/mês calendário), que fica como está, sem uso.

Versão: 1.0 · Criado em: 20260827

---

## 1. Problema

`GroupSidebar.tsx:33` lista "Relatórios" como item de navegação sem `to` (não clicável, `href="#"` na renderização) — não existe tela nem endpoint correspondentes. O usuário não tem hoje nenhuma visão consolidada dos ciclos de fechamento passados de um grupo: `GroupSummary.tsx` (via `useGroupCycle`/`GET /groups/{groupId}/expenses/summary`) só mostra a competência atual ou uma competência específica navegando ciclo a ciclo (`cycles_ago`), uma de cada vez — não há uma lista/histórico.

O dado já existe e já é persistido: `ExpenseController::cycleSnapshotFor` (`backend/app/Http/Controllers/ExpenseController.php:751-793`) grava um `GroupCycleSnapshot` (`ex_group_cycle_snapshots`) na primeira vez que uma competência passada (`status === 'closed'` por data) é consultada, e `ExpenseController::close` (linha 506) grava um snapshot também para fechamento manual da competência vigente. Cada linha tem `totals`/`expenses`/`balances`/`settlements` (JSON) e `cycle_start`/`cycle_end` — exatamente o dado que uma tela de histórico precisaria, sem recalcular nada.

## 2. Requisitos

### 2.1 Endpoint de listagem de ciclos passados por grupo

Novo endpoint `GET /api/groups/{groupId}/expenses/cycles` (nome provisório — ajustável no `plan.md`), retornando os `GroupCycleSnapshot` do grupo cuja `cycle_start` seja anterior ao início da competência vigente (`BillingCycle::cycleFor($group->closing_day, now())['start']`), ordenados do mais recente para o mais antigo, paginado.

- Segue o padrão de autorização já usado em `ExpenseController::summary` (`authorizeGroupMembership($group)` — membro do grupo).
- Não inclui a competência vigente (mesmo se fechada manualmente via `close()`) — fechamento manual é reversível (`reopen()`), então não é "histórico" no sentido de imutável; a competência vigente já é visível em `GroupSummary`. Ver §3 (fora de escopo).
- Cada item da lista traz o resumo (`totals`, `cycle_start`, `cycle_end`) suficiente para uma linha da lista; o detalhe completo (`expenses`, `balances`, `settlements`) de um ciclo específico é obtido ao expandir/selecionar aquele item (reaproveita o mesmo formato de dado que `summary` já devolve para a competência atual — não é um formato novo).

### 2.2 Tela de Relatórios (frontend)

- `GroupSidebar.tsx:33` ganha `to: '/groups/:id/reports'` (ou rota equivalente decidida no `plan.md`), deixando de ser item desabilitado.
- Nova página lista os ciclos passados (data de início/fim, total do ciclo, status) do endpoint acima, mais recente primeiro, com paginação.
- Selecionar um ciclo da lista mostra o detalhe (despesas do ciclo, saldos por pessoa, liquidação "quem paga a quem") — reaproveitando a mesma lógica de apresentação já usada em `GroupSummary.tsx`/`SummarySidePanel` para a competência atual, adaptada para um ciclo já fechado (sem as ações de navegação de ciclo/fechar/reabrir, que só fazem sentido para a competência vigente).

### 2.3 Não recalcula nada

O endpoint só lê `GroupCycleSnapshot` já persistidos — nunca invoca `computeCycleSummary` para popular a listagem (isso já acontece, lazicamente, na primeira vez que `summary()`/`cycleSnapshotFor()` é chamado para aquela competência; se um ciclo passado nunca foi consultado por `summary()`, ele simplesmente não aparece no histórico ainda — comportamento aceito, não é bug desta feature).

## 3. Fora de escopo desta feature

- Mostrar a competência vigente (mesmo fechada manualmente) na lista de histórico — ela já é visível em `GroupSummary`; misturar as duas fontes (mutável vs. imutável) complicaria a semântica de "histórico" sem necessidade concreta.
- Qualquer mudança em `GroupExpenseReportController` (endpoint legado por ano/mês calendário) — permanece como está, sem uso, conforme já decidido em `docs/feature/concluidas/202608/20260822-acerto-de-contas-ciclo/specify.md` §4.
- Exportar relatório (PDF/CSV) — não pedido, fora do mockup original que motivou o item.
- Ações sobre um ciclo passado (reabrir, editar) — snapshots de ciclos fechados por data são imutáveis por design (`cycleSnapshotFor` não os atualiza depois de criados); só a competência vigente tem `close()`/`reopen()`.
- Filtro/busca dentro do histórico (por período, por valor) — lista simples paginada cobre a necessidade inicial; filtro fica como possível item de backlog futuro se o volume de ciclos justificar.
