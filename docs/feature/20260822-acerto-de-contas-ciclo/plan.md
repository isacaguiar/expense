# Plan — Acerto de Contas por Ciclo

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260822

---

## 1. Cálculo do par-a-par (specify §2.1)

- Dentro de `ExpenseController::computeCycleSummary()` (`backend/app/Http/Controllers/ExpenseController.php:636-704`), no mesmo `foreach ($entries as $entry)` que já preenche `$balances` (linhas 672-692), acumular também `$owed[$payerId][$participantId] += $valuePerPerson` (mesma fonte `$entries`/`$valuePerPerson`/`$payerId` já calculados — sem consulta nova).
- Depois do loop, netar par a par (mesma lógica de `GroupExpenseReportController::reportByGroupAndYearMonthlySettlement:168-189`, adaptada para chavear por `user_id` em vez de nome): para cada par `(credor, devedor)` com `credor != devedor`, `net = owed[credor][devedor] - owed[devedor][credor]`; se `net > 0`, emite uma entrada `{from_user_id: devedor, to_user_id: credor, amount: round(net, 2)}`. Cada par gera no máximo 1 entrada (nunca as duas direções).
- Por que dentro de `computeCycleSummary` e não reaproveitando `GroupExpenseReportController`: já explicado em specify §2.2 — o relatório existente é por mês calendário, ignora `Quota.value_quota`, e é chaveado por nome. Reescrever seria mais trabalho e risco do que estender o método que já tem os dados certos (`$entries` via `collectCycleEntries`).
- `computeCycleSummary()` passa a retornar `['totals' => ..., 'expenses' => ..., 'balances' => ..., 'settlements' => ...]`.

## 2. Propagação pelos pontos que já usam `balances` (specify §2.4)

- `summary()` (`:373-413`): incluir `'settlements' => $summary['settlements']` no JSON de resposta, nos 3 ramos (`cycleSnapshotFor`, `$manualSnapshot`, `computeCycleSummary` direto).
- `cycleSnapshotFor()` (`:587-628`): incluir `settlements` no array retornado (both quando lê snapshot existente e quando cria um novo) e no `GroupCycleSnapshot::create([...])`.
- `close()` (`:426-462`): incluir `settlements` no `GroupCycleSnapshot::updateOrCreate([...])` e no JSON de resposta.
- `reopen()` (`:472-500`): incluir `settlements` no JSON de resposta (não persiste snapshot, só recomputa ao vivo, igual já faz hoje com `balances`).
- Migration aditiva: coluna `settlements` (`json`, `nullable`, `after('balances')`) em `ex_group_cycle_snapshots`, seguindo o padrão de `backend/database/migrations/2026_08_22_130000_add_closed_manually_at_and_reopened_at_to_ex_group_cycle_snapshots_table.php`. Nullable porque snapshots já existentes no banco local não têm essa coluna retroativamente calculada.
- `GroupCycleSnapshot` (`backend/app/Models/GroupCycleSnapshot.php`): adicionar `settlements` a `$fillable` e `$casts` (`array`), mesmo padrão de `balances`.

## 3. Testes backend (specify §2.1, §2.4)

- Estender `backend/tests/Feature/ExpenseControllerSummaryTest.php` (mesmo padrão de `test_balances_include_every_member_and_sum_to_zero` e `test_balances_are_unchanged_after_the_fixed_occurrence_quota_is_materialized`) com os casos descritos em `tasks.md` TASK-004.
- `backend/tests/Feature/GroupCycleSnapshotTest.php`: estender para cobrir `settlements` no fluxo de fechamento (automático e manual).

## 4. Frontend (specify §1)

- `frontend/src/hooks/useGroupCycle.ts`: novo tipo `SummarySettlement = { from_user_id: number; to_user_id: number; amount: number }`; campo `settlements: SummarySettlement[]` em `Summary`.
- Novo componente `frontend/src/components/SettlementList.tsx` (paralelo a `BalanceCards.tsx`, mesmo diretório): resolve nome a partir de `user_id` usando `balances` (já presente no mesmo `summary`, sem chamada de API nova) e renderiza "`{fromName}` deve pagar R$ `{amount}` a `{toName}`" por item.
- `frontend/src/pages/GroupSummary.tsx`: novo bloco "Quem paga a quem", logo abaixo do bloco "Saldos por pessoa" existente, condicionado a `summary.settlements.length > 0` (lista vazia = ciclo já liquidado ou sem despesas cruzadas — não mostra o bloco, igual ao padrão já usado para `summary.expenses.length === 0`).

## 5. Testes frontend

- Estender `frontend/src/pages/GroupSummary.test.tsx` com um cenário mockando `settlements` no retorno da API e verificando a renderização da lista (texto "deve pagar" + nomes + valor).

## N. Ordem de execução

Sem dependência entre itens 4/5 (frontend) e o backend além de precisar do contrato de resposta pronto — mas dentro do backend, a ordem é estritamente sequencial: item 1 (algoritmo) → item 2 (migration + propagação, que depende do array já ter `settlements`) → item 3 (testes, que dependem de 1 e 2 implementados). Frontend (item 4) depende do contrato JSON de `settlements` já definido em 1 (mesmo formato), mas pode ser codado em paralelo usando o formato combinado sem esperar o backend estar 100% mergeado, já que é a mesma pessoa/sessão implementando os dois lados nesta feature.
