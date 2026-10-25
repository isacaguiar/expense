# Consultas de banco — carregamento da tela de Relatórios

Referência para validar/diagnosticar o que a tela de Relatórios carrega do banco. A tela tem duas
abas e o Relatório de Fechamento:

| Parte da tela | Endpoint | Consultas em | Quando a requisição sai |
| --- | --- | --- | --- |
| Aba **Histórico** | `GET /groups/{groupId}/expenses/cycles?page=N` | seções 1 a 3 | ao abrir a aba e a cada página |
| Aba **Acompanhamento** (a que abre por padrão) | `GET /groups/{groupId}/expenses/pending-cycles` | seção 4 | toda vez que a aba abre |
| **Relatório de Fechamento** | `GET /groups/{groupId}/expenses/closing-report?cycle_start=` | seção 5 | ao escolher um ciclo (`?ciclo=` na URL) |

As seções 1 a 3 descrevem o Histórico e não mudaram de regra. As seções 4 e 5 vieram com a feature
`20261006-relatorios-acompanhamento-fechamento`, e as consultas delas foram capturadas por
execução real (ver "Como estas consultas foram obtidas", na seção 4).

## Cadeia de chamadas (aba Histórico)

| Camada | Arquivo |
| --- | --- |
| Tela | `frontend/src/pages/GroupReports.tsx` |
| Hook | `frontend/src/hooks/useGroupCycleHistory.ts` |
| Endpoint | `GET /api/groups/{groupId}/expenses/cycles?page=N` |
| Controller | `ExpenseController::cycleHistory()` (`backend/app/Http/Controllers/ExpenseController.php`) |
| Tabela lida | `ex_group_cycle_snapshots` (model `App\Models\GroupCycleSnapshot`) |

O controller **só lê snapshots já persistidos** — nunca recalcula ciclo.

---

## 1. Consulta principal (popula a lista de ciclos)

Gerada pelo `GroupCycleSnapshot::where(...)->orderByDesc('cycle_start')->paginate(10)` em
`ExpenseController::cycleHistory()`.

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

## 4. Aba Acompanhamento — `GET /groups/{groupId}/expenses/pending-cycles`

Lista os ciclos **encerrados (ou em carência) que ainda aguardam quitação**, do mais recente ao
mais antigo. Ao contrário do Histórico (que só lê snapshots: 5 consultas no total), **calcula ao
vivo** cada ciclo que não está selado — por isso faz muito mais consultas. **Só lê:** não sela, não
grava snapshot e não notifica.

### Cadeia de chamadas

| Camada | Arquivo |
| --- | --- |
| Tela | `frontend/src/pages/GroupReports.tsx` → `frontend/src/components/PendingCyclesList.tsx` |
| Hook | `frontend/src/hooks/useGroupPendingCycles.ts` |
| Endpoint | `GET /api/groups/{groupId}/expenses/pending-cycles` |
| Controller | `ExpenseController::pendingCycles()` |
| Serviço | `CycleSummaryService::pendingCycles()` → `firstPossibleEntryDate()` (privado), então, por ciclo: `view()` → `viewAt()` → `compute()` → `entries()`, `withConfirmations()`, e `summaryIsFullySettled()` |
| Tabelas lidas | `ex_expenses`, `ex_quotas`, `ex_expenses_payers`, `ex_users`, `ex_groups_members`, `ex_group_cycle_snapshots`, `ex_settlement_confirmations` |

A requisição sai **toda vez que a aba abre** (trocar para o Histórico e voltar pede de novo); o
número de ciclos varrido é no máximo 13 (`CycleSummaryService::FOCUS_LOOKBACK` = 12, mais o vigente).

### Como estas consultas foram obtidas

As consultas abaixo vêm de **execução real**, não de memória: um teste de feature descartável (não
versionado) ligou `DB::listen` e chamou o endpoint no banco de teste (`ex-db`, MySQL 8.0.17), com
`Carbon::setTestNow('2026-10-03')` e um grupo de 3 membros, `closing_day` nulo (ciclos = meses
calendário, carência de 5 dias). Os valores ligados (`?`) aparecem aqui como `:parâmetros`.

| Ciclo do cenário | Situação | Consultas | Resultado |
| --- | --- | --- | --- |
| out/2026 | vigente, `open`, fronteira não passou | 9 | calculado e **descartado** |
| set/2026 | `open` em carência (`in_grace`) | 11 | listado, acertos 1/2, 2 despesas pendentes |
| ago/2026 | `closed`, com pendência | 11 | listado, acertos 0/1, 2 despesas pendentes |
| jul/2026 | `closed`, tudo pago e acerto confirmado, sem selo | 9 | listado, `awaiting_seal: true` |
| jun/2026 | selado (`settled_at`) | 2 | pulado |
| mai/2026 | termina antes da 1ª despesa do grupo (10/06) | 0 | a varredura **para** |

**Total: 47 consultas** (5 fixas + 9 + 11 + 11 + 9 + 2), que somaram ≈ 190 ms no MySQL local em
contêiner, com dados mínimos — só ordem de grandeza, sem valor de referência para produção.

### 4.1 Consultas fixas (uma vez por requisição)

```sql
-- 1. usuário logado (JWT)
SELECT * FROM ex_users WHERE id = :authUserId LIMIT 1;

-- 2 e 3. Group::findOrFail e authorizeGroupMembership — iguais às da seção 2
SELECT * FROM ex_groups WHERE ex_groups.id = :groupId LIMIT 1;
SELECT EXISTS(SELECT * FROM ex_users INNER JOIN ex_groups_members
              ON ex_users.id = ex_groups_members.user_id
              WHERE ex_groups_members.group_id = :groupId AND user_id = :authUserId) AS `exists`;

-- 4. primeira despesa viva do grupo; NULL => devolve [] sem varrer nada (e pula a consulta 5)
SELECT MIN(date_payment) AS aggregate
FROM ex_expenses
WHERE group_id = :groupId AND deleted = 0;

-- 5. primeira quota de despesa viva (parcela retroativa pode vencer antes do date_payment)
SELECT MIN(date_expected) AS aggregate
FROM ex_quotas
WHERE EXISTS (SELECT * FROM ex_expenses
              WHERE ex_quotas.expense_id = ex_expenses.id
                AND group_id = :groupId AND deleted = 0);
```

A menor das duas datas (`firstPossibleEntryDate`) é o piso da varredura: um ciclo que **termina
antes** dela não tem entrada nenhuma, então nem é consultado.

### 4.2 Consultas por ciclo (repetidas de `ago = 0` até o piso)

Ordem real no ciclo calculado ao vivo. `:cycleStart` e `:cycleEnd` são as datas do ciclo
(`YYYY-MM-DD`).

```sql
-- a. snapshot do ciclo — viewAt() procura primeiro a foto, pelo cycle_start
SELECT * FROM ex_group_cycle_snapshots
WHERE group_id = :groupId AND cycle_start = :cycleStart
LIMIT 1;
```

Se o snapshot está **selado** (`settled_at` preenchido), o ciclo sai pela foto e só faz a consulta
**f** abaixo (2 consultas no total) — e a lista o pula. Senão, calcula ao vivo:

```sql
-- b. despesas À Vista e Fixas com vencimento dentro do ciclo
SELECT * FROM ex_expenses
WHERE group_id = :groupId AND deleted = 0
  AND expense_type != 'IN_INSTALLMENTS'
  AND date_payment BETWEEN :cycleStart AND :cycleEnd
  AND (expense_type != 'FIXED'
       OR fixed_recurrence_ends_at IS NULL
       OR fixed_recurrence_ends_at > :cycleStartDoMes);   -- início do mês de :cycleStart

-- b'. carregamento antecipado de b (só se b devolveu linhas): pagador, participantes, quotas
SELECT * FROM ex_users WHERE ex_users.id IN (:payerIds);
SELECT ex_users.*, ex_expenses_payers.expense_id AS pivot_expense_id,
       ex_expenses_payers.user_id AS pivot_user_id
FROM ex_users INNER JOIN ex_expenses_payers ON ex_users.id = ex_expenses_payers.user_id
WHERE ex_expenses_payers.expense_id IN (:expenseIds);
SELECT * FROM ex_quotas WHERE ex_quotas.expense_id IN (:expenseIds);

-- c. parcelas (IN_INSTALLMENTS) cujo vencimento cai no ciclo
SELECT * FROM ex_quotas
WHERE date_expected BETWEEN :cycleStart AND :cycleEnd
  AND EXISTS (SELECT * FROM ex_expenses
              WHERE ex_quotas.expense_id = ex_expenses.id
                AND group_id = :groupId AND deleted = 0 AND expense_type = 'IN_INSTALLMENTS');

-- c'. (só se c devolveu linhas) despesa, pagador e participantes da parcela
SELECT * FROM ex_expenses WHERE ex_expenses.id IN (:expenseIds);
SELECT * FROM ex_users WHERE ex_users.id IN (:payerIds);
SELECT ex_users.*, ex_expenses_payers.expense_id AS pivot_expense_id,
       ex_expenses_payers.user_id AS pivot_user_id
FROM ex_users INNER JOIN ex_expenses_payers ON ex_users.id = ex_expenses_payers.user_id
WHERE ex_expenses_payers.expense_id IN (:expenseIds);

-- d. Fixas criadas antes do ciclo, projetadas para dentro dele
SELECT * FROM ex_expenses
WHERE group_id = :groupId AND deleted = 0 AND expense_type = 'FIXED'
  AND date_payment < :cycleStart
  AND (fixed_recurrence_ends_at IS NULL OR fixed_recurrence_ends_at > :cycleStart);

-- e. membros do grupo (para os saldos) — só no 1º ciclo calculado da requisição;
--    nos seguintes a relação já está carregada no mesmo objeto Group
SELECT ex_users.*, ex_groups_members.group_id AS pivot_group_id,
       ex_groups_members.user_id AS pivot_user_id
FROM ex_users INNER JOIN ex_groups_members ON ex_users.id = ex_groups_members.user_id
WHERE ex_groups_members.group_id = :groupId;

-- f. confirmações de acerto do ciclo (withConfirmations) — também roda no ciclo selado
SELECT * FROM ex_settlement_confirmations
WHERE group_id = :groupId AND cycle_start = :cycleStart;

-- g. a MESMA consulta f, de novo, em summaryIsFullySettled (para o awaiting_seal) —
--    só quando o ciclo tem acertos e nada a pagar (pending <= 0); com pendência, nem roda
SELECT * FROM ex_settlement_confirmations
WHERE group_id = :groupId AND cycle_start = :cycleStart;
```

No cenário: out/2026 fez a, b (sem linhas, então sem b'), c (+ c'), d, e e f; set/2026 e ago/2026
fizeram a, b (+ b'), c (+ c'), d e f; jul/2026 fez a, b (+ b'), c (sem linhas), d, f e g; jun/2026
fez a e f.

### Quando o ciclo entra na lista

O ciclo entra se **não está selado**, está **encerrado** (`closed`, `closed_manually` ativo, ou
`open` com a fronteira no passado = carência) e **não é vazio**.

| Situação do ciclo | Na lista? |
| --- | --- |
| selado (`settled_at` preenchido) | **não** — é do Histórico |
| vigente `open`, fronteira não passou | **não** — mas é calculado antes de ser descartado (9 consultas no cenário) |
| `future` | **não** |
| sem despesa real (só `born_paid`) e sem acerto | **não** — vazio |
| `open` em carência | **sim**, com `in_grace: true` |
| `closed` / `closed_manually` com pendência ou acerto sem confirmar | **sim** |
| `closed` já todo pago e confirmado, mas sem selo | **sim**, com `awaiting_seal: true` |

### Observações de custo (para a TASK-497)

- **O ciclo vigente é calculado e descartado.** O laço começa em `ago = 0`; as 9 consultas do
  ciclo de out/2026 não rendem item. Em todo grupo com despesa, isso é custo fixo por requisição.
- **Cada ciclo ao vivo custa 9 a 11 consultas.** O código usa carregamento antecipado (`IN (…)`),
  então o número não deveria crescer com a quantidade de despesas do ciclo; isso ainda precisa
  ser confirmado com 12 ciclos semeados na TASK-497.
- **A consulta g repete a f** nos ciclos quitados sem selo: 1 consulta a mais por ciclo desse tipo.
- **Ciclo selado custa 2 consultas.** Quanto mais ciclos selados, mais barata a requisição.
- **Pior caso** (13 ciclos, nenhum selado, todos com parcela): 5 + 13 × ≈ 11 ≈ **150 consultas**.
  É conta sobre as contagens acima, **não medição**.

### 4.3 Consulta de diagnóstico

Para entender por que um ciclo aparece (ou não) na aba. O estado do snapshot é o mesmo da seção 3;
o que falta é ver as confirmações, que decidem `settlements_confirmed` e `awaiting_seal`:

```sql
SELECT cycle_start, from_user_id, to_user_id, amount, confirmed_at
FROM ex_settlement_confirmations
WHERE group_id = :groupId
ORDER BY cycle_start DESC, from_user_id, to_user_id;
```

E o piso da varredura (a data abaixo da qual nenhum ciclo é calculado):

```sql
SELECT MIN(date_payment) AS primeira_despesa
FROM ex_expenses
WHERE group_id = :groupId AND deleted = 0;

SELECT MIN(q.date_expected) AS primeira_quota
FROM ex_quotas q
INNER JOIN ex_expenses e ON e.id = q.expense_id
WHERE e.group_id = :groupId AND e.deleted = 0;
```

---

## 5. Relatório de Fechamento — `GET /groups/{groupId}/expenses/closing-report?cycle_start=YYYY-MM-DD`

Abre **um** ciclo, escolhido pelo `cycle_start` que a lista (Acompanhamento ou Histórico) devolveu.
Serve o ciclo selado (pela foto), o fechado pendente e o vigente (Prévia). **Só lê:** não sela,
não grava snapshot e não notifica.

### Cadeia de chamadas

| Camada | Arquivo |
| --- | --- |
| Tela | `frontend/src/pages/GroupReports.tsx` (`?ciclo=`) → `frontend/src/components/ClosingReportPanel.tsx` |
| Hook | `frontend/src/hooks/useClosingReport.ts` |
| Endpoint | `GET /api/groups/{groupId}/expenses/closing-report?cycle_start=YYYY-MM-DD` |
| Controller | `ExpenseController::closingReport()` |
| Serviço | `CycleSummaryService::viewAt()` → `compute()` → `entries()` (ao vivo) e `withConfirmations()` |
| Agregação | `App\Support\ClosingBreakdown::build()` — só PHP, **nenhuma consulta** |
| Tabelas lidas | as mesmas da seção 4 |

A requisição sai uma vez por ciclo escolhido (e de novo se o `?ciclo=` muda).

### Ordem das checagens

`Group::findOrFail` → `authorizeGroupMembership` → `validate` (`cycle_start` obrigatório,
`Y-m-d`) → `viewAt`. A checagem de membro vem **antes** da validação, para quem é de fora receber
`404` mesmo com `cycle_start` inválido. `viewAt` devolve `null` (→ `404`) para ciclo futuro e para
uma data que não é o primeiro dia de um ciclo do grupo.

### Consultas por caminho (execução real, mesmo cenário da seção 4)

| Caminho | Resposta | Consultas |
| --- | --- | --- |
| ciclo calculado ao vivo (set/2026, em carência) | `200` | **15** |
| ciclo selado (jun/2026) | `200` | **6** |
| ciclo futuro (nov/2026) | `404` | 4 |
| data que não abre ciclo (15/09/2026) | `404` | 4 |
| `cycle_start` ausente ou mal formado (`abc`) | `422` | 3 |
| usuário que não é membro | `404` | 3 |

As 3 primeiras de todo caminho são as de 1 a 3 da seção 4.1 (usuário logado, grupo e membro); o
`422` e o `404` de não-membro **encerram aí**, antes de qualquer consulta de ciclo.

**Ciclo ao vivo — as 12 seguintes**, na ordem em que rodam:

```sql
-- 4. a (seção 4.2): snapshot do ciclo
-- 5. b: despesas À Vista e Fixas do ciclo, e 6-8. b': pagador, participantes e quotas delas
-- 9. c: parcelas que vencem no ciclo, e 10-12. c': despesa, pagador e participantes (só com linhas)
-- 13. d: Fixas projetadas
-- 14. e: membros do grupo (saldos); o controller reaproveita a relação para montar o `creditors`
-- 15. f: confirmações de acerto do ciclo (withConfirmations)
```

(Os textos SQL são os da seção 4.2; o que muda é a ordem e que cada um roda **uma vez**.)

**Ciclo selado — as 3 seguintes:**

```sql
-- 4. a: snapshot selado (a foto traz totais, despesas, saldos e acertos)
SELECT * FROM ex_group_cycle_snapshots
WHERE group_id = :groupId AND cycle_start = :cycleStart
LIMIT 1;

-- 5. f: confirmações de acerto do ciclo (decora os acertos da foto com comprovante e data)
SELECT * FROM ex_settlement_confirmations
WHERE group_id = :groupId AND cycle_start = :cycleStart;

-- 6. membros do grupo — o controller os usa para resolver credor/devedor no `creditors`
SELECT ex_users.*, ex_groups_members.group_id AS pivot_group_id,
       ex_groups_members.user_id AS pivot_user_id
FROM ex_users INNER JOIN ex_groups_members ON ex_users.id = ex_groups_members.user_id
WHERE ex_groups_members.group_id = :groupId;
```

Os `404` de ciclo futuro e de data fora de fronteira param na consulta `a`: o status e os limites
do ciclo saem de `BillingCycle` (cálculo em PHP), sem tocar nas despesas.

### Interpretação

| `cycle_start` | Resposta |
| --- | --- |
| início de ciclo com snapshot selado | `200`, valores **da foto** (mesmo que o `closing_day` tenha mudado depois) |
| início de ciclo já encerrado, sem selo | `200`, valores **ao vivo**, `cycle.status` `closed` ou `closed_manually` |
| início do ciclo vigente | `200`, **Prévia** (`open`) |
| início de ciclo em carência | `200`, `open` com `cycle.in_grace: true` |
| início de ciclo futuro | `404` |
| data que cai no meio de um ciclo | `404` |
| ausente ou fora de `Y-m-d` (inclusive `2026-02-30`) | `422` |

### Consulta de diagnóstico

A mesma da seção 3 mostra o snapshot (selado ou não) do `cycle_start` que o Fechamento recebeu, e a
de confirmações da seção 4.3 mostra o que decora os acertos.

---

## Observação — relatório anual antigo

Existe outro controller, `GroupExpenseReportController`
(`backend/app/Http/Controllers/GroupExpenseReportController.php`), nas rotas:

- `GET /api/groups/{groupId}/expenses/report/{year}`
- `GET /api/group/{groupId}/report-monthly/{year}`

Esse **consulta `ex_expenses` em tempo real** (`Expense::with(['payer','payers'])->where('group_id', ...)->get()`)
e recalcula parcelas/rateios em PHP. **Não é** o que a tela de Relatórios chama hoje —
fica registrado aqui só para não confundir as duas fontes.
