# Consultas de banco — carregamento da tela de Relatórios

Referência para validar/diagnosticar o que a tela **"Histórico de ciclos fechados"** carrega.

## Cadeia de chamadas

| Camada | Arquivo |
| --- | --- |
| Tela | `frontend/src/pages/GroupReports.tsx` |
| Hook | `frontend/src/hooks/useGroupCycleHistory.ts` |
| Endpoint | `GET /api/groups/{groupId}/expenses/cycles?page=N` |
| Controller | `ExpenseController::cycleHistory()` (`backend/app/Http/Controllers/ExpenseController.php:693`) |
| Tabela lida | `ex_group_cycle_snapshots` (model `App\Models\GroupCycleSnapshot`) |

O controller **só lê snapshots já persistidos** — nunca recalcula ciclo.

---

## 1. Consulta principal (popula a lista de ciclos)

Gerada pelo `GroupCycleSnapshot::where(...)->orderByDesc('cycle_start')->paginate(10)` em
`ExpenseController.php:703`.

```sql
-- Página exibida
SELECT *
FROM ex_group_cycle_snapshots
WHERE group_id = :groupId
  AND cycle_start < :currentCycleStart   -- 'YYYY-MM-DD' — início da competência vigente
  AND settled_at IS NOT NULL             -- só ciclo selado (100% quitado)
ORDER BY cycle_start DESC
LIMIT 10 OFFSET :offset;                 -- OFFSET = (page - 1) * 10
```

```sql
-- Count disparado pelo paginate(10) (define o número de páginas)
SELECT count(*) AS aggregate
FROM ex_group_cycle_snapshots
WHERE group_id = :groupId
  AND cycle_start < :currentCycleStart
  AND settled_at IS NOT NULL;
```

### Os três filtros

| Filtro | Significado |
| --- | --- |
| `group_id = :groupId` | ciclos daquele grupo |
| `cycle_start < :currentCycleStart` | exclui a competência vigente (essa fica na navegação principal, não no relatório) |
| `settled_at IS NOT NULL` | só ciclo **selado** — toda conta paga e todo acerto confirmado; um `unpay` que quebre a quitação limpa `settled_at` e o ciclo some daqui |

### Como obter `:currentCycleStart`

É `BillingCycle::cycleFor($group->closing_day, now())['start']` — o início da competência
atual, derivado de `ex_groups.closing_day`. Para conferir manualmente:

```sql
SELECT id, name, closing_day
FROM ex_groups
WHERE id = :groupId;
```

Regra: se hoje o dia do mês já passou de `closing_day`, a competência vigente começa em
`closing_day` do mês atual; senão, em `closing_day` do mês anterior. Use essa data (formato
`YYYY-MM-DD`) como `:currentCycleStart`.

---

## 2. Consultas auxiliares (rodam antes da principal, na mesma request)

```sql
-- Group::findOrFail($groupId)
SELECT * FROM ex_groups WHERE id = :groupId LIMIT 1;
```

```sql
-- authorizeGroupMembership(): retorna 404 se o usuário logado não for membro
SELECT EXISTS(
  SELECT *
  FROM ex_users
  INNER JOIN ex_groups_members ON ex_users.id = ex_groups_members.user_id
  WHERE ex_groups_members.group_id = :groupId
    AND user_id = :authUserId
) AS `exists`;
```

---

## 3. Consulta de diagnóstico

Lista **todos** os snapshots do grupo (sem os filtros da tela) para entender por que uma
linha aparece ou não:

```sql
SELECT
  id,
  group_id,
  cycle_start,
  cycle_end,
  settled_at,
  closed_manually_at,
  reopened_at,
  JSON_EXTRACT(totals, '$.total') AS total
FROM ex_group_cycle_snapshots
WHERE group_id = :groupId
ORDER BY cycle_start DESC;
```

### Interpretação

| Situação da linha | Efeito na tela |
| --- | --- |
| `settled_at IS NULL` | **não aparece** — ciclo fechado mas ainda com pendência (fica no `focus-cycle` da navegação principal) |
| `cycle_start >= currentCycleStart` | **não aparece** — é a competência vigente (mesmo fechada manualmente, pois `reopen()` é reversível) |
| `settled_at` preenchido **e** `cycle_start < currentCycleStart` | **aparece**, ordenada da mais recente para a mais antiga, 10 por página |

Colunas JSON úteis (`totals`, `expenses`, `balances`, `settlements`) são devolvidas como
está no snapshot — o controller só as repassa no JSON da resposta.

---

## Observação — relatório anual antigo

Existe outro controller, `GroupExpenseReportController`
(`backend/app/Http/Controllers/GroupExpenseReportController.php`), nas rotas:

- `GET /api/groups/{groupId}/expenses/report/{year}`
- `GET /api/group/{groupId}/report-monthly/{year}`

Esse **consulta `ex_expenses` em tempo real** (`Expense::with(['payer','payers'])->where('group_id', ...)->get()`)
e recalcula parcelas/rateios em PHP. **Não é** o que a tela de Relatórios chama hoje —
fica registrado aqui só para não confundir as duas fontes.
