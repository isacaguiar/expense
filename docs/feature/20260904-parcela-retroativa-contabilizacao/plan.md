# Plan — Contabilização da parcela retroativa

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260904

---

Toda a mudança de código é em dois arquivos: `backend/app/Http/Controllers/ExpenseController.php` e `backend/app/Models/Quota.php`, mais uma migration nova. O ajuste dos dados de produção (`specify.md` §2.5) é um script SQL, executado pelo usuário.

## 0. Decisões transversais

### 0.1 Marcador é coluna nova `ex_quotas.born_paid`, não heurística

`store()` e `pay()` gravam os **mesmos** campos numa parcela quitada: `paid = true`, `paid_at = now()`, `paid_by = user_payer_id` (em `pay()`, `auth()->id()`, que a autorização força `=== $expense->user_payer_id`). Não dá pra distinguir "nasceu quitada retroativa" de "credor pagou depois" sem um marcador explícito — heurística por `paid_at ≈ created_at` é frágil (skew de relógio, e não sobrevive a `pay()`+`unpay()`).

- Migration nova `2026_09_04_000000_add_born_paid_to_ex_quotas_table.php`:
  - `up()`: `$table->boolean('born_paid')->default(false)->after('paid_by');`
  - `down()`: `$table->dropColumn('born_paid');`
- Aditiva, com default → sem downtime (Constitution §4.2). `20260903-deploy-backend-migracao-automatica` aplica no deploy.
- `backend/app/Models/Quota.php`: `$fillable` (`:15-24`) += `'born_paid'`; `$casts` (`:26-31`) += `'born_paid' => 'boolean'`.

### 0.2 Filtro no `computeCycleSummary()`, não no `collectCycleEntries()`

`collectCycleEntries()` (`ExpenseController.php:1380-1473`) é fonte comum de `computeCycleSummary()`, `grossDebts()` e (via `computeCycleSummary`) `cycleIsFullySettled()`/`sealCycleIfSettled()`. Filtrar lá mudaria `grossDebts` (que já filtra `paid` por conta própria) e a materialização de FIXED sem necessidade. A parcela `born_paid` **precisa** continuar em `expenses[]` como linha "Paga" (`specify.md` §2.2), então o filtro não pode ser "não colete" — é "colete, marque, e ignore só onde monta dinheiro". Logo: `collectCycleEntries()` **propaga** o flag; `computeCycleSummary()` **decide** onde ele pesa.

## 1. `computeCycleSummary()` exclui parcela `born_paid` do acerto (`specify.md` §2.1 e §2.3)

`ExpenseController.php:1114-1232`.

### 1.1 `collectCycleEntries()` propaga `bornPaid`

Nos 3 `push` de entry, incluir a chave `bornPaid`:

- `$direct` (`:1400-1406`) — `$quota` pode ser `null`: `'bornPaid' => (bool) ($quota->born_paid ?? false)`.
- `$installmentQuotas` (`:1421-1428`) — `$quota` sempre existe: `'bornPaid' => (bool) $quota->born_paid`.
- `$fixedCandidates` (`:1459-1465`) — `$quota` pode ser `null`; FIXED nunca é `born_paid`: `'bornPaid' => (bool) ($quota->born_paid ?? false)`.

Atualizar o `@return` docblock (`:1378`) com a chave nova.

### 1.2 `computeCycleSummary()` — laço de `balances`/`$owed`/`settlements`

No `foreach ($entries as $entry)` (`:1164-1186`), primeira instrução:

```php
foreach ($entries as $entry) {
    // Parcela retroativa nascida quitada (born_paid): é registro histórico, não
    // dívida — não entra em balances/settlements. NÃO filtrar por $entry['paid']:
    // uma quota que o credor pagou depois via pay() continua gerando settlement
    // até o devedor confirmar (feature 20260902-pagamento-ciclo-fechado).
    if ($entry['bornPaid']) {
        continue;
    }

    $expense = $entry['expense'];
    // ... resto igual
}
```

- `expenses[]` (`:1118-1145`): **não muda o filtro** — a parcela `born_paid` continua listada. Acrescentar `'bornPaid' => $entry['bornPaid']` ao array mapeado (`:1122-1139`) — usado pelo guard de §1.3 e disponível pro frontend estilizar "histórico".
- `totals` (`:1147-1151`): **não muda** — `pending` já filtra `paid == false`; `total`/`paid` incluem a linha paga (coerente com "linha Paga").
- `balances` inicializa todo membro em `0.0` (`:1153-1156`); com o `continue`, uma competência só-retroativa devolve todos os saldos `0.0` e `settlements: []`.

### 1.3 `sealCycleIfSettled()` — guard de competência só-retroativa

`ExpenseController.php:1312-1352`. Hoje o guard (`:1324`) é:

```php
if (empty($summary['expenses']) && empty($summary['settlements'])) {
    return false;
}
```

Com a parcela `born_paid` permanecendo em `expenses[]`, um mês passado cujo único conteúdo é ela teria `expenses` não-vazio → `sealCycleIfSettled` selaria e dispararia `Notifier::cycleSettled` pra todos. Novo guard:

```php
$hasRealExpense = collect($summary['expenses'])
    ->contains(fn ($e) => empty($e['bornPaid']));

if (! $hasRealExpense && empty($summary['settlements'])) {
    return false;
}
```

`cycleIsFullySettled()` (`:1273-1302`) **não muda**: recebe de `computeCycleSummary()` os `settlements` já sem o par fantasma e `totals.pending == 0` → devolve `true` pra competência só-retroativa → `focusCycle()` (`:592-619`) pula com o `continue` de `:616` sem mudança de código.

### 1.4 Callers de `computeCycleSummary()` — nenhum muda

7 call sites (`summary` `:551`/`:558`, `close` ~`:656`, `reopen` ~`:732`, `confirmSettlement` ~`:1046`, `cycleIsFullySettled` `:1275`, `sealCycleIfSettled` `:1318`) seguem passando `($group, $groupId, $start, $end)`. O filtro é interno, guiado por `$entry['bornPaid']` originado em `collectCycleEntries`. `close()`/`reopen()` operam em `cycles_ago = 0` (sempre `open`) → nunca há quota `born_paid` ali; snapshot manual inalterado.

## 2. `store()` grava `born_paid` (`specify.md` §2.4)

`ExpenseController.php:434-441`. O `$bornPaid` já é calculado (`:427-432`). Acrescentar uma chave ao `create()`:

```php
$expense->quotas()->create([
    'date_expected' => $quotaData['date_expected'],
    'number' => $quotaData['number'],
    'paid' => $bornPaid,
    'paid_at' => $bornPaid ? now() : null,
    'paid_by' => $bornPaid ? $request->user_payer_id : null,
    'born_paid' => $bornPaid,
    'value_quota' => $quotaData['value_quota'],
]);
```

`update()` (recria quotas na troca de tipo, `paid => false`) e `pay()`/`unpay()` não passam `born_paid` → fica `false` pelo default da coluna. `materializeFixedOccurrenceQuota()` idem.

## 3. Script SQL de produção (`specify.md` §2.5)

Arquivo versionado: `docs/feature/20260904-parcela-retroativa-contabilizacao/fix-prod-3878.sql`. **Não roda em CI/deploy** — é operação manual do usuário no banco `ex-db` (`expense-api.novemax.com.br`), **gate de produção** (Constitution §5.2). Sequência:

1. **Deploy da correção de código primeiro** (migration cria `born_paid`; código novo lê o flag e o guard novo de selagem). Rodar o SQL antes disso não corrige o Bug 2 e a desselagem de julho dispararia `Notifier::cycleSettled` espúrio.
2. Backup: `CREATE TABLE _bkp_ex_quotas_20260904 AS SELECT * FROM ex_quotas WHERE expense_id IN (8658,8659);` e idem para as 2 linhas de `ex_group_cycle_snapshots`.
3. Dentro de transação, com `SELECT` de conferência antes de cada `UPDATE`:

```sql
-- 3.1  Marca as parcelas jun/jul de 8658/8659 como retroativas (mata o fantasma).
--      Espera 4 linhas afetadas (8658 #1,#2 e 8659 #1,#2), todas paid=1, paid_by=5573.
SELECT id, expense_id, number, date_expected, paid, paid_by, born_paid
FROM ex_quotas WHERE expense_id IN (8658, 8659) AND paid = 1 ORDER BY expense_id, number;

UPDATE ex_quotas SET born_paid = 1 WHERE expense_id IN (8658, 8659) AND paid = 1;
-- NÃO tocar em paid/paid_at/paid_by. NÃO tocar nas parcelas de agosto+ (ago = dívida real).

-- 3.2  Desselar julho (parcela de jul passa a aparecer como linha "Paga").
SELECT id, cycle_start, cycle_end, settled_at, closed_manually_at
FROM ex_group_cycle_snapshots WHERE group_id = 3878 AND cycle_start = '2026-07-01';

UPDATE ex_group_cycle_snapshots SET settled_at = NULL
WHERE group_id = 3878 AND cycle_start = '2026-07-01';

-- 3.3  Desselar agosto (parcela de ago vira pendência cobrável ao vivo).
SELECT id, cycle_start, cycle_end, settled_at, closed_manually_at
FROM ex_group_cycle_snapshots WHERE group_id = 3878 AND cycle_start = '2026-08-01';

UPDATE ex_group_cycle_snapshots SET settled_at = NULL
WHERE group_id = 3878 AND cycle_start = '2026-08-01';
```

4. Diagnóstico — outras parceladas retroativas afetadas (rodar **antes**, decidir escopo):

```sql
SELECT q.expense_id, e.group_id, e.description, COUNT(*) AS quotas_pagas
FROM ex_quotas q JOIN ex_expenses e ON e.id = q.expense_id
WHERE e.expense_type = 'IN_INSTALLMENTS' AND q.paid = 1
  AND q.paid_by = e.user_payer_id AND q.payment_proof_path IS NULL
  AND ABS(TIMESTAMPDIFF(SECOND, q.created_at, q.paid_at)) <= 120
GROUP BY q.expense_id, e.group_id, e.description;
```

Se aparecer despesa além de 8658/8659: se o padrão for claro (mesmo intervalo PR #144 → fix, sem `pay()` posterior), o `UPDATE` de 3.1 vira o `WHERE` do diagnóstico (backfill amplo); senão, tratar caso a caso. Registrar a decisão em `implementation.md`.

5. Verificação pós-script (`GET` na API com token):
   - `summary?cycles_ago=3` (jun): `settlements: []`, `totals.pending: 0`, 2 linhas `paid:true`.
   - `summary?cycles_ago=2` (jul): 2 linhas `paid:true`, `settlements: []`, `cycle.settled` pode voltar a `false` (não re-selado — ok).
   - `summary?cycles_ago=1` (ago): as 2 parcelas de ago aparecem `paid:false`; `settlements` inclui R$ 139,23 de cada devedor (1, 5574, 5575, 5576, 5577) → 5573, além dos itens já existentes.
   - `focus-cycle` → aponta pra agosto (mês com dívida real mais antigo).
   - `gross-debts?cycles_ago=3` e `settlements` de jun **batem** (ambos sem as parcelas retroativas).

## 4. Testes (`specify.md` §1, Constitution §2.2)

`backend/tests/Feature/`. Grupo de teste com `closing_day = null` (ciclo = mês calendário, `closesAt` = dia 5). Relógio fixo com `Carbon::setTestNow()`.

| # | Arquivo::teste | Cenário | Relógio | Espera |
|---|---|---|---|---|
| 1 | `ExpenseControllerStoreTest::test_retroactive_quota_persists_born_paid_flag` | POST Parcelada 6x compartilhada (credor + 1 devedor), início `2026-06-05`, quotas jun–nov | `2026-09-20` | `ex_quotas.born_paid = 1` p/ jun/jul/ago; `= 0` p/ set/out/nov; `paid` idem |
| 2 | `ExpenseControllerSummaryTest::test_retroactive_installment_is_paid_line_without_settlement_in_past_closed_cycle` | despesa do #1 | `2026-09-20` | `summary?cycles_ago=1..3`: `expenses` tem linha `paid:true`; `settlements == []`; todo `balances[*].balance == 0`; `totals.pending == 0`; `totals.paid > 0` |
| 3 | `FocusCycleTest::test_retroactive_shared_installment_does_not_drag_home_back` | despesa do #1, sem snapshot | `2026-09-20` | `GET focus-cycle` → `cycles_ago == 0` |
| 4 | `ExpenseControllerSummaryTest::test_closed_cycle_expense_paid_via_pay_still_generates_settlement` (regressão 20260902) | IN_CASH split, quota `paid = true` **por update direto** (`born_paid = false`), competência de julho | `2026-09-15` | `summary?cycles_ago=2`: `settlements` tem `from devedor to credor`; `balances` credor `+`, devedor `−` |
| 5 | `ExpenseControllerCycleIsFullySettledTest::test_paid_unconfirmed_real_settlement_in_closed_cycle_still_blocks` (regressão) | quota `paid = true`, `born_paid = false`, julho, sem `SettlementConfirmation` | `2026-09-15` | `cycleIsFullySettled()` → `false`; após inserir a confirmação → `true` |
| 6 | `SettlementConfirmationControllerTest::test_confirming_the_last_pending_item_seals_the_cycle` (existente) | — | — | continua **verde sem alteração** |
| 7 | `ExpenseControllerStoreTest::test_installments_expense_starting_in_a_closed_cycle_...` (existente, estender: `payers` = credor + devedor; assert `born_paid`) | — | — | **verde**; novas asserts de `born_paid` |
| 8 | `ExpenseControllerSummaryTest::test_retroactive_only_cycle_does_not_auto_seal_or_notify` | despesa do #1, sem snapshot; `Notification::fake()` | `2026-09-20` | após `GET summary?cycles_ago=3`: `assertDatabaseMissing('ex_group_cycle_snapshots', ['group_id'=>..., 'cycle_start'=>'2026-06-01'])` **ou** `settled_at` nulo; nenhum `CycleSettled` enviado |
| 9 | `ExpenseControllerSummaryTest::test_retroactive_installment_leaves_a_sealed_past_cycle_frozen` | despesa do #1; junho **selado** via `GroupCycleSnapshot(settled_at)` | `2026-09-20` | `summary?cycles_ago=3`: serve a foto congelada (sem a parcela); `cycle.settled == true` |
| 10 | `ExpenseControllerGrossDebtsTest::test_retroactive_paid_installment_absent_and_matches_settlements` | despesa do #1 | `2026-09-20` | `gross-debts?cycles_ago=1..3`: `creditors == []`; paridade com `settlements` do `summary` |
| 11 | `ExpenseControllerStoreTest::test_installments_expense_entirely_in_closed_cycles_is_rejected` + rejeições `IN_CASH`/`FIXED`/manual (existentes) | — | — | **verdes sem alteração** |

`./vendor/bin/pint --test` limpo; `php artisan test` completo verde (sem regressão na suíte).

## 5. Ordem de execução

Uma única mudança de backend coesa (migration + `Quota` + `store()` + `collectCycleEntries` + `computeCycleSummary`/`sealCycleIfSettled`), com os testes no mesmo diff (Constitution §2.2) — **não** são entregas independentes. Depois, o script SQL de produção, que **depende** do deploy da mudança de código.

1. **TASK-001** (backend): migration `born_paid` + `Quota` + `store()` (§0.1, §2) + `collectCycleEntries` propaga (§1.1) + `computeCycleSummary`/`sealCycleIfSettled` filtram (§1.2, §1.3) + toda a matriz de testes (§4). Gate: antes do merge (+ `security-reviewer`, `pr-readiness-checker`).
2. **TASK-002** (infra/doc): `fix-prod-3878.sql` + runbook em `implementation.md` (§3). Gate: **antes do deploy/execução em produção** — o usuário roda, depois do merge/deploy da TASK-001.

Sem dependência interna na TASK-001; a TASK-002 depende da TASK-001 estar em produção.
