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

## 9. specify §2.8 — Janela de carência de 5 dias

- `backend/app/Support/BillingCycle.php`:
  - `public const GRACE_DAYS = 5;`
  - novo `public static function closesAt(Carbon $boundary): Carbon` → `$boundary->copy()->addDays(self::GRACE_DAYS)`. Público porque `ExpenseController` usa para montar `cycle.closes_at`.
  - `statusOf(Carbon $start, Carbon $end, Carbon $referenceStart)` (`:67`): o ramo `closed`
    passa de `$end->lt($referenceStart)` para `$referenceStart->gte(self::closesAt($end))`.
    `future` (`$start->gt($referenceStart)`) e `open` (default) inalterados.
  - **`boundariesFor()` NÃO muda.** O rollover (`$referenceStart->gt($currentBoundary)`,
    `:54`) continua no fim do ciclo — `cycleFor(..., 0)` segue sendo o ciclo do
    mês-calendário e sempre `open` relativo a `now` (invariante que `close()`/`reopen()`
    assumem, `:582`). A carência só amplia a janela `open` dos ciclos `cycles_ago >= 1`.
- `cycle.closes_at` (aditivo): `summary()` (`:516`), `close()` (`:627`), `grossDebts()`
  incluem `'closes_at' => BillingCycle::closesAt($end)->toDateString()` no objeto `cycle`,
  ao lado de `settled` (§0.4). `reopen()` também, por uniformidade.
  - Alternativa descartada: devolver `grace_days: 5` e o front somar. Espalha regra de data
    pro cliente; `closes_at` pronto é o menor acoplamento.
- Consumidores que passam a ver o novo timing **sem mudança de código**:
  `rejectIfCompetenceClosed()` (`:300`, via `store`/`update`/`destroy`), `stopRecurrence()`
  (`:443`), e o ramo `open` de `summary()` (já serve o ciclo em carência ao vivo). É o "R3"
  do pedido — edição total na carência, trava em F.
- **Testes** — `backend/tests/Unit/BillingCycleTest.php`:
  - reescrever `test_cycle_closed_the_day_after_closing_day` (`:56`): o rollover de fronteira
    **não muda** (`cycleFor(10, '2026-01-14', 0)` continua `[Jan 11, Feb 10]`, `open`), mas o
    ciclo anterior `[Dec 11, Jan 10]` — `cycleFor(10, ref, 1)` — fica `open` consultado em
    `2026-01-14` (carência) e `closed` em `2026-01-16`. Renomear para a nova regra.
  - novos casos: `closing_day=null` → o ciclo de janeiro (`cycleFor(null, ref, 1)`) é `open`
    consultado em `2026-02-04`, `closed` em `2026-02-05`; `closing_day=20` → ciclo anterior
    `closed` só a partir do `dia 25`; um caso afirmando
    `BillingCycle::closesAt(Carbon::parse('2026-01-31'))->toDateString() === '2026-02-05'`.
  - conferir e, se ficarem em cima da linha, mover a data de: `test_null_closing_day_previous_month_is_closed`
    (ref `2026-01-15`, F = `2026-01-05` — ok), `test_closing_day_clamps_*`
    (F = `2026-03-05`/`2028-03-05` — ok), `test_status_for_computes_status_of_the_cycle_containing_an_arbitrary_date`
    (ref `2026-08-21`; `2026-08-05` → F `2026-09-05`, ainda `open`; `2026-07-05` → F
    `2026-08-05`, `now` `2026-08-21` ≥ F → `closed`; ambos ok).
- **Ripple** nos Feature tests que congelam o relógio perto da virada — TASK-258, separada,
  logo após TASK-256 (senão a suíte fica vermelha e trava o resto — `00-constitution.md` §2.4).
- **Gate:** antes do deploy — muda, para todo grupo em produção, a data em que o ciclo
  vigente trava (dia 1 → dia 5 do mês seguinte, no caso padrão). O banner de §2.10 é o
  aviso ao usuário; ainda assim, deploy consciente.

## 10. specify §2.9 — Home no ciclo em carência

- `focusCycle($groupId)` (`:546`): no loop, depois de `$cycleIsClosed` (`:562`):
  ```php
  $inGrace = $cycle['status'] === 'open'
          && Carbon::now()->startOfDay()->gt($cycle['end']);
  if (! $cycleIsClosed && ! $inGrace) {
      continue;
  }
  ```
  `$inGrace` é sempre `false` para `$ago = 0` (o ciclo corrente nunca tem `end < now`).
- Efeito: durante a carência, o 1º ciclo do loop com `end` já passado e ainda não quitado é
  o que estava fechando → `focus-cycle` devolve o `cycles_ago` dele. Em F ele vira `closed`,
  o ramo `$cycleIsClosed` assume, **mesmo `cycles_ago`** — sem salto na Home. Quitado → loop
  segue e cai em `0`.
- Custo: +1 `computeCycleSummary` por abertura de grupo durante a janela de carência (o
  ciclo em carência entra no `cycleIsFullySettled`). Pontual, aceitável.
- `close()` / `reopen()` — **sem mudança** (§2.9 do specify).
- **Testes** — `backend/tests/Feature/FocusCycleTest.php` (criada na TASK-248): caso novo —
  `Carbon::setTestNow('2026-02-02')` (carência do ciclo de janeiro, `closing_day=null`),
  ciclo de janeiro com uma quota não paga → `GET .../focus-cycle` → `{"cycles_ago":1}`;
  mesma data, janeiro quitado → `{"cycles_ago":0}`.

## 11. specify §2.10 — Banners de fechamento

- **Componente** `frontend/src/components/CycleClosingAlert.tsx`:
  - props `{ summary: Summary }` (o objeto de `useGroupCycle`).
  - `const today = startOfToday()`, `end = parseISO(summary.cycle.end)`,
    `closesAt = parseISO(summary.cycle.closes_at)`.
  - **pré** (`<Alert severity="info">`): `summary.cycle.status === 'open' && today >= end && today < closesAt`.
    Texto: `Este ciclo fecha em ${format(closesAt, 'dd/MM')}. Registre e acerte as despesas até lá.`
  - **pós** (`<Alert severity="warning">`): `!summary.cycle.settled
    && (summary.cycle.status === 'closed' || summary.cycle.status === 'closed_manually')
    && debtors.length > 0`, onde `debtors = summary.balances.filter(b => b.balance < 0).map(b => b.name)`.
    Texto: `O ciclo fechou e ainda falta acertar: ${debtors.join(', ')}.`
  - fora dessas condições: `return null`. As duas são disjuntas por `status` — nunca as duas.
- **Uso**: importado em `frontend/src/pages/GroupSummary.tsx` e
  `frontend/src/pages/ExpenseManager.tsx`, logo abaixo do cabeçalho de ciclo, antes da
  lista. Recebe o `summary` que a página já tem via `useGroupCycle`.
- **Tipo**: `SummaryCycle` (`frontend/src/hooks/useGroupCycle.ts:11`) ganha `closes_at: string`
  (junto de `settled: boolean` que a TASK-250 já adiciona ali).
- Sem chamada de API nova — tudo do `summary`.
- **Por que um componente e não dois**: a decisão pré/pós é função pura do `summary`; um só
  ponto de render evita divergência e os dois nunca coexistem.
- **Testes** — `frontend/src/components/CycleClosingAlert.test.tsx` (Vitest): (1)
  `status:'open'`, `end` = ontem, `closes_at` = +3 dias → renderiza "fecha em"; (2)
  `status:'closed'`, `settled:false`, `balances` com dois saldos negativos → renderiza os
  dois nomes; (3) `settled:true` → `null`; (4) `status:'open'`, `end` no futuro → `null`.

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

**Carência + banners (§9/§10/§11 — TASK-256..259):**

1. **§9** (TASK-256) entra no bloco backend depois de §0. Edita `BillingCycle` +
   `ExpenseController` (`cycle.closes_at`) — serializa com §1–§4/§7.
2. **TASK-258** (ripple dos Feature tests) imediatamente após TASK-256 — mantém
   `php artisan test` verde antes de seguir.
3. **§10** (TASK-257) depois de §9 (usa `statusOf`/`closesAt` novos) e de §4-backend
   (o `focusCycle` já existe).
4. **§11** (TASK-259, frontend) depois de §9 (campo `closes_at`) **e** de TASK-250 (que
   liga o `focus-cycle` no hook e adiciona `settled`/`closes_at` ao tipo — o banner precisa
   aparecer no ciclo certo).
5. `security-reviewer` cobre também TASK-256/257 (tocam `summary`/`grossDebts`/`focusCycle`
   em `ExpenseController`). O ponteiro em
   `docs/feature/20260902-fechamento-ciclo-carencia-dia-5/` já foi criado nesta rodada.
