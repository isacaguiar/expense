# Plan — Pagamento em ciclo fechado

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260902

Todo o backend é em `backend/app/Http/Controllers/ExpenseController.php` salvo indicação
contrária. Referências `:NNN` são linhas do estado atual desse arquivo.

---

## 0. Decisões transversais

### 0.1 Ciclo de vida da competência

| Estado | Editar despesa | Credor `pay`/`unpay` | Devedor `confirmSettlement` | `summary()` serve |
|---|---|---|---|---|
| `open` | ✅ | ✅ | ❌ 422 "após o fechamento" | ao vivo |
| `closed_manually` | ❌ | ✅ | ✅ | ao vivo *(hoje: JSON congelado)* |
| `closed` (por data) | ❌ | ✅ | ✅ | ao vivo enquanto não selado *(hoje: congela na 1ª leitura)* |
| selado (`settled_at`) | ❌ | ✅ (`unpay` **dessela**) | ❌ (nada pendente) | snapshot congelado |
| `future` | ❌ | ❌ 422 | ❌ 422 | ao vivo |

"Fechado" para efeito do §2 = `status === 'closed'` **ou** (`status === 'open'` **e**
`GroupCycleSnapshot::isManuallyClosedAndActive()`).

### 0.2 Migration `settled_at` (aditiva)

`backend/database/migrations/2026_09_02_000000_add_settled_at_to_ex_group_cycle_snapshots_table.php`:

- `up()`: `$table->timestamp('settled_at')->nullable()->after('reopened_at');` +
  backfill num segundo statement — `DB::table('ex_group_cycle_snapshots')->whereNull('settled_at')->update(['settled_at' => DB::raw('updated_at')]);`
  (toda linha que já existe representa, no modelo antigo, um ciclo já imutável → tratar como selado).
- `down()`: `dropColumn('settled_at')`.
- `GroupCycleSnapshot` (`backend/app/Models/GroupCycleSnapshot.php`): `settled_at` em `$fillable`
  e `$casts` (`'settled_at' => 'datetime'`); novo método `isSealed(): bool => $this->settled_at !== null`.

### 0.3 Helpers de quitação (privados em `ExpenseController`)

- **`cycleIsFullySettled(Group $group, $groupId, Carbon $start, Carbon $end): bool`**
  - `$s = $this->computeCycleSummary($group, $groupId, $start, $end)` (ao vivo).
  - `if (round($s['totals']['pending'], 2) > 0) return false;`
  - `settlements` vazio → `return true;`
  - carrega `SettlementConfirmation` de `(group_id, cycle_start)`, monta set `"from-to"`;
    se algum par de `$s['settlements']` não estiver no set → `return false;` senão `true`.
- **`sealCycleIfSettled(Group, $groupId, $start, $end): void`** — se `cycleIsFullySettled`,
  `GroupCycleSnapshot::updateOrCreate(['group_id','cycle_start'], [cycle_end, totals, expenses,
  balances, settlements, 'settled_at' => Carbon::now()])`. `updateOrCreate` não toca
  `closed_manually_at`/`reopened_at` (não passados) — preserva o registro de fechamento manual.
- **`unsealIfBroken(Group, $groupId, $start, $end): void`** — carrega o snapshot; se
  `isSealed()` e `! cycleIsFullySettled(...)` → `$snap->update(['settled_at' => null])`.

### 0.4 Campo `cycle.settled` na resposta

`summary()`, `close()`, `reopen()`, `grossDebts()` passam a devolver
`cycle: { start, end, status, settled: bool }`. `settled` = existe snapshot com
`settled_at`. O front usa para o gate do devedor (§2) e para o chip de status.

### 0.5 `cycleSnapshotFor()` (`:910`) sai

Com o `summary()` reescrito (§3) ninguém mais chama `cycleSnapshotFor()` — remover o
método. O congelamento passa a ser responsabilidade única de `sealCycleIfSettled` / `close()`.

---

## 1. specify §2.1 — Pagar despesa em ciclo fechado

- `pay($expenseId, Request $request)` (`:726`):
  - validação ganha `'cycles_ago' => 'nullable|integer|min:0'`.
  - `$cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $data['cycles_ago'] ?? 0)`
    (hoje fixo em `cyclesAgo` implícito 0, `:739`).
  - **remover** o bloco `rejectIfCompetenceClosed` (`:741-743`).
  - `if ($cycle['status'] === 'future') return 422 'Não é possível pagar uma competência futura.'`
  - `resolveQuotaForCurrentCompetence` → renomear **`resolveQuotaForCycle`** (assinatura já é
    `(Expense, Carbon $start, Carbon $end)`, `:1245`; só renomear + docblock + o único caller).
  - depois do `$quota->update(...)`: `$this->sealCycleIfSettled($group, $group->id, $cycle['start'], $cycle['end']);`
- `unpay($expenseId, Request $request)` (`:792`):
  - passa a receber `Request`; validação `'cycles_ago' => 'nullable|integer|min:0'`.
  - mesma resolução de `$cycle` por `cycles_ago`; **remover** `rejectIfCompetenceClosed` (`:803-805`);
    mesma recusa de `future`.
  - depois de limpar a quota: `$this->unsealIfBroken($group, $group->id, $cycle['start'], $cycle['end']);`
- Rota `POST /api/expenses/{expenseId}/unpay` (`routes/api.php:42`) já casa com a nova
  assinatura (o `Request` é injetado). Sem mudança de rota.
- **Por que não** um endpoint separado "pagar competência X": `pay`/`unpay` já são
  por-despesa e a única informação faltante é qual competência — um parâmetro aditivo é
  o menor contrato novo (§4.1 da Constitution).

## 2. specify §2.2 — Acerto do devedor só em ciclo fechado

- `confirmSettlement(Request $request, $groupId)` (`:839`):
  - validação ganha `'cycles_ago' => 'nullable|integer|min:0'`.
  - `$cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $data['cycles_ago'] ?? 0)`.
  - **inverter a guarda** (substitui `rejectIfCompetenceClosed`, `:851-853`):
    ```
    $snap = GroupCycleSnapshot::where('group_id',$groupId)->where('cycle_start',$cycle['start']->toDateString())->first();
    $isClosed = $cycle['status'] === 'closed'
             || ($cycle['status'] === 'open' && $snap && $snap->isManuallyClosedAndActive());
    if (! $isClosed) return 422 'O acerto só pode ser confirmado depois que a competência é fechada.';
    if ($snap && $snap->isSealed()) return 422 'Esta competência já foi encerrada.';
    ```
  - resto igual: `computeCycleSummary` do ciclo-alvo → acha o par → `updateOrCreate` →
    WhatsApp `afterResponse`.
  - depois do `updateOrCreate`: `$this->sealCycleIfSettled($group, $groupId, $cycle['start'], $cycle['end']);`
- Front — `frontend/src/components/PayableSettlementList.tsx`:
  - nova prop `canConfirm: boolean`. O bloco `{isDebtor && (<>…botões…</>)}` (`:96-115`) vira
    `{isDebtor && canConfirm && (…)}`.
  - quando `isDebtor && !canConfirm`: um `<Typography variant="caption" color="text.secondary">`
    no lugar ("Disponível após o fechamento do ciclo" / "Ciclo encerrado" se `settled`).
  - `frontend/src/pages/Payments.tsx`: calcula
    `const canConfirm = (summary.cycle.status === 'closed' || summary.cycle.status === 'closed_manually') && !summary.cycle.settled;`
    e passa para `<PayableSettlementList canConfirm={canConfirm} … />`.
  - `frontend/src/components/SettlementList.tsx` (variante só-leitura) inalterado.

## 3. specify §2.3 — Ciclo fechado acionável até quitar + selagem

- `summary()` (`:469-511`) — nova precedência de fonte de dados:
  ```
  $snapshot = GroupCycleSnapshot::where('group_id',$groupId)->where('cycle_start',$start->toDateString())->first();
  $sealed = $snapshot && $snapshot->isSealed();

  if ($sealed) {
      $status = 'closed';
      $summary = [totals/expenses/balances/settlements do $snapshot];
  } elseif ($status === 'closed' || ($status === 'open' && $snapshot && $snapshot->isManuallyClosedAndActive())) {
      if ($status === 'open') $status = 'closed_manually';
      $summary = $this->computeCycleSummary($group, $groupId, $start, $end);        // AO VIVO
      if ($this->cycleIsFullySettled($group, $groupId, $start, $end)) {
          $this->sealCycleIfSettled($group, $groupId, $start, $end);
          $sealed = true; $status = 'closed';
      }
  } else {
      $summary = $this->computeCycleSummary($group, $groupId, $start, $end);        // open normal / future
  }
  ```
  - some a chamada a `cycleSnapshotFor()` (`:491`) e o ramo que servia o JSON do
    `close()` para `closed_manually` (`:492-499`).
  - resposta: `'cycle' => ['start'=>…, 'end'=>…, 'status'=>$status, 'settled'=>$sealed]`.
- `cycleHistory()` (`:612`): adicionar `->whereNotNull('settled_at')` à query dos snapshots —
  só ciclo selado é "histórico" de Relatórios (os snapshots antigos têm `settled_at` do backfill).
- `reopen()` (`:572`): depois de carregar `$snapshot`, antes do check de
  `isManuallyClosedAndActive` (`:586`), adicionar
  `if ($snapshot && $snapshot->isSealed()) return 422 'Esta competência já foi encerrada.';`
- `close()` (`:524`): no fim, calcular `$sealed = $this->cycleIsFullySettled(...)`; se `true`,
  `sealCycleIfSettled` e devolver `status: 'closed', settled: true`; senão
  `status: 'closed_manually', settled: false`. (Cobre fechar um mês que já está todo pago.)
- `grossDebts()` (`:647`): incluir `'settled' => (bool)($snap && $snap->isSealed())` no objeto `cycle` da resposta (uniformidade; nenhuma lógica nova).
- **Por que servir ao vivo em vez de "congelar e ir atualizando o snapshot"**: o snapshot
  só precisa existir quando o ciclo vira história (selado). Enquanto pendente, recalcular é
  barato (`computeCycleSummary` já roda em `open`) e evita um estado intermediário
  "snapshot parcialmente atualizado" difícil de manter coerente com `unpay`.

## 4. specify §2.4 — Abrir no ciclo fechado com pendência

- **Backend** — rota nova `GET /api/groups/{groupId}/expenses/focus-cycle` em
  `backend/routes/api.php` (grupo `jwt.auth`, junto de `expenses/cycles`, `:50`).
  `focusCycle($groupId)`:
  ```
  authorizeGroupMembership; const FOCUS_LOOKBACK = 12;
  for ($ago = 0; $ago <= self::FOCUS_LOOKBACK; $ago++) {
      $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $ago);
      $snap  = GroupCycleSnapshot::where('group_id',$groupId)->where('cycle_start',$cycle['start']->toDateString())->first();
      if ($snap && $snap->isSealed()) continue;                       // já quitado
      $isClosed = $cycle['status'] === 'closed'
               || ($cycle['status'] === 'open' && $snap && $snap->isManuallyClosedAndActive());
      if (! $isClosed) continue;                                      // aberto/futuro não conta
      if (! $this->cycleIsFullySettled($group,$groupId,$cycle['start'],$cycle['end']))
          return response()->json(['cycles_ago' => $ago]);            // 1º pendente = mais recente
  }
  return response()->json(['cycles_ago' => 0]);                       // nada pendente → ciclo corrente
  ```
  Custo típico: 1 `computeCycleSummary` (o `$ago=1`). Teto de 12 ciclos limita o pior caso.
- **Frontend** — `frontend/src/hooks/useGroupCycle.ts`:
  - novo estado `focusResolved` (default `false`).
  - o `useEffect(() => setCyclesAgo(0), [groupId])` (`:113-115`) é **substituído** por um
    effect que, ao mudar `groupId`: `setFocusResolved(false)` → `GET …/expenses/focus-cycle`
    → `setCyclesAgo(res.data.cycles_ago ?? 0); setFocusResolved(true)` (no `catch`,
    `setCyclesAgo(0); setFocusResolved(true)`).
  - o effect do summary (`:88-110`) ganha guarda `if (!groupId || !focusResolved) return;`
    e `focusResolved` nas deps — evita o flash "ciclo 0 → pula".
  - `SummaryCycle` (`:11`) ganha `settled: boolean`.
- **Por que endpoint dedicado** e não estender `summary`: `summary` sempre recebe um
  `cycles_ago` concreto; a decisão "qual abrir" é anterior a ele e não cabe numa resposta
  que já é por-ciclo.
- **Decisão** "mais recente pendente" (não "mais antigo"): casa com "o último ciclo
  fechado" do pedido. Trocar para o mais antigo = inverter o retorno do loop (guardar o
  último `$ago` pendente em vez de retornar no primeiro).

## 5. specify §2.5 — Menu: Pagamentos antes de Participantes

- `frontend/src/layouts/group/GroupSidebar.tsx:31-32`: trocar a ordem das entradas
  `Participantes` e `Pagamentos` no array `groupNavItems`.
- `frontend/src/layouts/simpleNavItems.ts:17-18`: idem no array `simpleNavItems`.
- Sem lógica — ordem literal do array (`NavList.tsx:107` mapeia na ordem).
- Testes: assertivas de ordem em `frontend/src/layouts/GroupShellLayout.test.tsx` e
  `frontend/src/layouts/SimpleShellLayout.test.tsx`.

## 6. specify §2.6 — Habilitar Participantes e Relatórios no menu sem grupo

- Duas telas de entrada novas, cópia 1:1 de `frontend/src/pages/PaymentsEntry.tsx`
  trocando só o destino do `navigate`:
  - `frontend/src/pages/MembersEntry.tsx` → `/groups/${target.id}/members`
  - `frontend/src/pages/ReportsEntry.tsx` → `/groups/${target.id}/reports`
  (reusam `frontend/src/pages/mostActiveGroup.ts`).
- `frontend/src/App.tsx` — no bloco `SimpleShellLayout` (após `:50`): duas rotas novas
  `<Route path="/members" element={<MembersEntry />} />`, `<Route path="/reports" element={<ReportsEntry />} />`
  + imports.
- `frontend/src/layouts/simpleNavItems.ts`: `Participantes` ganha `to: '/members'`,
  `Relatórios` ganha `to: '/reports'`.
- Testes: `SimpleShellLayout.test.tsx` — as assertivas de `href="#"` para Participantes
  (`:60`) e Relatórios (`:78`) passam a esperar as rotas reais.
- **Por que entry-redirect** e não uma tela própria sem grupo: `Pagamentos` já resolve
  assim; manter o mesmo padrão (menor superfície, zero backend).

## 7. specify §2.7 — Não pago em cima, pago embaixo

- `computeCycleSummary()` (`:967-992`): `->sortBy('date')` (`:990`) vira
  `->sortBy([['paid','asc'],['date','asc']])` — `paid=false` ordena antes de `true`.
- `attachSettlementConfirmations()` (`:1090-1108`): depois do `->map(...)` que injeta
  `confirmedProofUrl`/`confirmedAt`, ordenar
  `->sortBy(fn ($s) => [$s['confirmedAt'] === null ? 0 : 1, -$s['amount']])->values()->all()`
  (não confirmado primeiro, depois maior valor). Aplica nos 3 chamadores
  (`summary()`, `close()`, `reopen()`) porque a ordenação está dentro do helper.
- Front: conferir que `frontend/src/pages/ExpenseManager.tsx` (tem `.filter` por tipo,
  `:250-254`) e `frontend/src/pages/Payments.tsx` (`:180`) **não** reordenam as listas por
  cima da ordem do backend. `PayableSettlementList.tsx` mapeia direto — ok.
- Testes: `backend/tests/Feature/ExpenseControllerSummaryTest.php` e
  `ExpenseControllerCloseTest.php` — atualizar assertivas de ordem.

## 8. Ordem de execução

Dependência real:

1. **§0** (migration + `settled_at` no model + helpers `cycleIsFullySettled` /
   `sealCycleIfSettled` / `unsealIfBroken` + `isSealed`) — base de tudo.
2. Depois de §0, em paralelo lógico (mas serializados na prática por editarem o mesmo
   `ExpenseController.php`): **§1**, **§2 (backend)**, **§3**, **§4 (backend)**, **§7**.
3. **§3** precisa aterrissar antes do **frontend de §2 e §4** (ambos leem `cycle.settled`).
   **§4 (backend)** antes do **§4 (frontend)**.
4. **§5** e **§6** são 100% independentes — podem ir a qualquer momento (inclusive primeiro).
5. Testes de cada área junto da área. Fecho: ponteiro de supersessão no
   `specify.md` de `20260821-home-ciclo-corrente-navegacao-futura`, `security-reviewer`
   (mexe em `pay`/`unpay`/`confirmSettlement`), `pr-readiness-checker`, PR único → `dev`.

Critério de ordenação em `tasks.md`: §0 primeiro; backend antes do frontend que o
consome; menu (§5/§6) encaixado no começo por não ter dependência e destravar valor cedo.
