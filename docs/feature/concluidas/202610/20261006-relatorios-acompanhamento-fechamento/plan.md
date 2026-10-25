# Plan — Acompanhamento e Fechamento nos Relatórios

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.2 · Criado em: 20261006 · Atualizado em: 20261024

> **Atualização de 2026-10-22** (TASK-426 da feature `20261010-painel-financeiro-pessoal`). **Nenhuma decisão do `specify.md` mudou.** O que mudou é o código em que este plan se apoia: o Painel financeiro pessoal moveu o cálculo de ciclo do `ExpenseController` para `App\Services\CycleSummaryService`. **Esta atualização vale depois do merge dessa feature na `dev`**; antes disso o código da `dev` ainda tem o cálculo dentro do controller.
>
> **Como ler as referências:** as linhas deste plan foram conferidas em 2026-10-22 contra a branch do Painel. **Valem o nome do método e do arquivo; a linha é só dica**, porque a feature `20261022-editar-comprovante-depois-de-paga` também mexe no `ExpenseController` e vai deslocá-las de novo. Reconferir com `grep -n` ao gerar o `tasks.md`.
>
> | Estava neste plan | Passa a ser |
> |---|---|
> | `computeCycleSummary`, `collectCycleEntries`, `attachSettlementConfirmations` no controller | `CycleSummaryService::compute`, `entries`, `withConfirmations` |
> | §0.3: extrair `summaryIsFullySettled` | **Já existe**: `CycleSummaryService::summaryIsFullySettled(array $summary, $groupId, Carbon $start)`, com a assinatura que este plan propunha. O `cycleIsFullySettled` do controller é só um wrapper de `isFullySettled`, mantido porque `ExpenseControllerCycleIsFullySettledTest` o chama por reflexão. Nada a extrair |
> | `self::FOCUS_LOOKBACK` no controller | `CycleSummaryService::FOCUS_LOOKBACK` (= 12). A constante **não existe mais** no controller: copiar o pseudocódigo do §1 sem trocar o prefixo dá erro |
> | `focusCycle()` com o laço | `CycleSummaryService::focus()`; o controller só delega |
> | Escolha "snapshot se selado, senão ao vivo" copiada de `summary()` (§2.1) | `CycleSummaryService::view($group, $cyclesAgo)`, que já faz isso **sem selar** e devolve `in_grace` e `closed_manually`. Resolve por `cycles_ago`, e o Fechamento resolve por `cycle_start`: ver D-N3 |
>
> **Atualização de 2026-10-24 (v1.2).** O usuário **aprovou D-N1, D-N2 e D-N3 como recomendado (A, A, A)** no gate deste plan; o `tasks.md` foi gerado a partir daqui. O Painel e as features `20261022-editar-comprovante-depois-de-paga` e `20261023-members-index-checagem-de-membro` já estão na `dev`, então as linhas foram reconferidas com `grep -n` contra a `dev` (`1264738705`). O que mudou, só de posição (nenhum comportamento citado mudou): chamadas de `sealCycleIfSettled` em `pay()` `:1053` → `:1065` e em `confirmSettlement()` `:1216` → `:1352`; `Notifier::cycleSettled` `:1321` → `:1457`; rota `expenses/cycles` `routes/api.php:72` → `:73`; ramo do snapshot de `summary()` `:671-702` → `:664-700`. Seguem iguais: `summary()` `:696`, `close()` `:789`, `rejectIfCycleClosed` `:390` (dispensa `FIXED` em `:392`), `update()` `:160`, `destroy()` `:365`, `BillingCycle::statusFor` `:49`. Premissas conferidas na `dev`: `view()` e `focus()` existem em `CycleSummaryService` (`:527`, `:418`) e `viewAt`/`pendingCycles` ainda **não**; `firstPossibleEntryDate` é `private` (`:482`), serve por morar no mesmo serviço; `paymentNote` e `confirmedNote` já vêm no `summary` (`CycleSummaryService.php:70`, `:340`) e nos tipos de `useGroupCycle.ts` (`:41`, `:79`). `GroupReports.tsx` ainda é só o Histórico (título em `:27`).
>
> **Decisões novas de desenho** (**aprovadas em 2026-10-24 como recomendado**; opções B ficam registradas como descartadas):
>
> | # | Decisão | Opções | Recomendação | Por quê |
> |---|---|---|---|---|
> | D-N1 | O Fechamento mostra a descrição do pagamento | A) mostra `paymentNote` (por despesa) em `CycleExpenseList` e `confirmedNote` (por acerto) em `SettlementList` com `showConfirmation`<br>B) não mostra | **A** | A feature `comprovante-ao-marcar-paga` (já na `dev`) põe as duas no `summary`, que o Fechamento reaproveita. O Fechamento é o documento que registra como cada coisa foi paga ("paguei por fora"). Texto puro, truncado (até 500 caracteres); snapshot antigo sem `paymentNote` vale `null` |
> | D-N2 | Onde mora a varredura do Acompanhamento (§1) | A) método novo no `CycleSummaryService` (por exemplo `pendingCycles(Group)`), e o controller só chama<br>B) laço no controller, como no plan 1.0 | **A** | Controller fino (Constitution §1, item 3), e a varredura de 13 ciclos é irmã do `focus()`: B repete o laço. Permite reusar `firstPossibleEntryDate` para não calcular ciclo anterior à primeira entrada |
> | D-N3 | Resolução do Fechamento por `cycle_start` (§2.1) | A) `CycleSummaryService::viewAt(Group, Carbon $start)` (snapshot por `cycle_start` primeiro, senão o ciclo que contém a data), com `view()` passando a delegar a ele<br>B) as ~10 linhas no controller, como no plan 1.0 | **A** | Uma só regra de "selado ou ao vivo, sem selar" para o Painel e para Relatórios, sem cópia para manter. Exige que os testes de `view()` do Painel sigam verdes **sem edição** |

---

## 0. Decisões transversais

### 0.1 Resolução dos pontos em aberto do specify §4

1. **Carência: entra.** Ciclo `open` com `end` no passado aparece em Acompanhamento, com `cycle.in_grace = true` e o rótulo "Fecha em dd/mm" (usa `closes_at`).
2. **Ciclo quitado e não selado: entra, com selo "Quitado".** O caso é raro, mas possível. Só `pay()`, `confirmSettlement()`, `close()` e o ramo preguiçoso de `summary()` chamam `sealCycleIfSettled` (`summary()` no ramo preguiçoso, `close()`, `pay()` e `confirmSettlement()`; em 2026-10-24: `ExpenseController.php:696`, `:789`, `:1065`, `:1352`); `update()` e `destroy()` não chamam. Como ambos **recusam** ciclo já fechado para despesa à vista e parcelada (`rejectIfCycleClosed`, definido em `:390` e chamado em `update()` `:160` e `destroy()` `:365`), o estado "quitado sem snapshot" só nasce quando a quitação muda sem passar por essas quatro ações. Caminhos conhecidos: (a) editar ou excluir despesa **na janela de carência** (o ciclo ainda é `open`, então a trava não vale); (b) despesa `FIXED`, que a trava dispensa (`rejectIfCycleClosed`, `:392`); (c) ciclo que ficou quitado antes da selagem existir (feature `20260902`) e que ninguém reabriu em `summary()`. Nesses casos `isFullySettled` é verdadeiro, não há snapshot, e o `CycleSummaryService::focus()` pula o ciclo sem selar. Selar dentro do GET está descartado: quebraria "nunca grava" (specify §2.1/§2.5) e dispararia `Notifier::cycleSettled` (em `sealCycleIfSettled`, `:1457`) a partir de uma listagem. O item recebe `awaiting_seal: true` e a UI mostra "Quitado". O teste do §6 reproduz o caminho (a); a frequência real em produção não foi medida. A causa raiz vai para o backlog (§8). **Relacionado, em 2026-10-22:** o item 093 do backlog trata o custo desse mesmo estado para o Painel (`focus()` recalcula todo ciclo quitado e não selado a cada chamada). O ciclo só com parcela retroativa `bornPaid` **não** vira `awaiting_seal`: a regra `hasReal` do §1 o exclui, e ele nunca sela por desenho (`sealCycleIfSettled` não congela ciclo sem despesa real nem acerto).
3. **Ciclo vigente no Fechamento: aceito como Prévia.** `cycle.status = 'open'`, sem `settled`.

### 0.2 Convenções que valem para os dois endpoints

- Rotas dentro do grupo `jwt.auth`, junto de `expenses/cycles` (`routes/api.php:73`). Primeira linha do método: `Group::findOrFail` + `authorizeGroupMembership` (Constitution §1, item 6: "quem pode acessar o quê").
- Pure read: nenhum `create`/`update`/`seal`/notificação.
- Valores monetários seguem `CycleSummaryService::compute` (antes `computeCycleSummary`): soma **sem arredondar** por par e `round(…, 2)` só no fim. É o que mantém bruto e líquido reconciliáveis (§2.3).
- JSON em `snake_case` nos campos novos de topo (como `cycles_ago`, `closes_at`) e `camelCase` nos itens reaproveitados de `summary` (`payerName`, `confirmedAt`), para não criar dois formatos dentro do mesmo objeto.

### 0.3 Refatoração mínima de apoio (sem mudar comportamento) — **já feita pelo Painel**

> **Atualização de 2026-10-22: nada a extrair.** `CycleSummaryService::summaryIsFullySettled(array $summary, $groupId, Carbon $start): bool` já existe (introduzido pela feature do Painel) e é exatamente o que este item propunha. O Acompanhamento chama `$this->cycles()->summaryIsFullySettled($summary, $groupId, $start)` com o summary que já tem em mãos, sem recalcular. O `cycleIsFullySettled` do `ExpenseController` virou um wrapper fino de `CycleSummaryService::isFullySettled`.

Texto original (1.0), mantido como histórico: `cycleIsFullySettled` recalculava `computeCycleSummary` por dentro, e o Acompanhamento chamaria o cálculo duas vezes por ciclo; a proposta era extrair o corpo da checagem para `summaryIsFullySettled`. Cobertura que continua valendo como sentinela: `ExpenseControllerCycleIsFullySettledTest`, `FocusCycleTest`, `ExpenseControllerSummaryTest` e `CycleSummaryServiceTest` (devem seguir verdes sem edição).

## 1. specify §2.1 — Aba Acompanhamento: `GET /groups/{groupId}/expenses/pending-cycles`

- **Onde:** `ExpenseController::pendingCycles` + rota em `routes/api.php` (junto de `:73`). **Pela D-N2 (A):** o laço mora em `CycleSummaryService::pendingCycles(Group)`, e o controller só autoriza o membro e devolve `{ "data": [...] }`.
- **Algoritmo** (mesma varredura de `CycleSummaryService::focus()`, antes `focusCycle`):
  ```
  for ($ago = 0; $ago <= CycleSummaryService::FOCUS_LOOKBACK; $ago++) {   // `self::` se o laço morar no serviço
      $cycle    = BillingCycle::cycleFor($group->closing_day, now(), $ago);
      $snapshot = GroupCycleSnapshot::where(group, cycle_start)->first();
      if ($snapshot?->isSealed()) continue;                       // vai para Histórico
      $closed  = $cycle['status'] === 'closed'
              || ($cycle['status'] === 'open' && $snapshot?->isManuallyClosedAndActive());
      $inGrace = $cycle['status'] === 'open' && now()->startOfDay()->gt($cycle['end']);
      if (!$closed && !$inGrace) continue;                        // vigente aberto e futuro ficam fora
      $summary = $this->cycles()->compute(...);                   // antes computeCycleSummary
      $hasReal = collect($summary['expenses'])->contains(fn ($e) => empty($e['bornPaid']));
      if (!$hasReal && empty($summary['settlements'])) continue;  // ciclo vazio (regra de sealCycleIfSettled, `$hasRealExpense`)
      $settlements = $this->cycles()->withConfirmations(...);     // antes attachSettlementConfirmations
      $item = [...];
  }
  ```
- **Item da resposta** (`{ "data": [ ... ] }`, no máximo 13 itens, mais recente primeiro, sem paginação):
  `cycle { start, end, closes_at, status, settled:false, in_grace }`,
  `totals { total, paid, pending }`,
  `settlements_total`, `settlements_confirmed` (conta `confirmedAt !== null`),
  `pending_expenses_count` (entradas com `paid=false`, excluindo `bornPaid`),
  `awaiting_seal` (= `summaryIsFullySettled` verdadeiro mas sem selo, §0.1.2).
- **`status`:** `closed`, `closed_manually` (fechamento manual ativo) ou `open` (carência). Mesmo vocabulário de `summary()`, então `cycleStatusChip` do frontend serve sem mudança.
- **Atalho:** `CycleSummaryService::view($group, $ago)` já devolve, para cada ciclo, o `cycle` (com `status`, inclusive `closed_manually`, e `in_grace`), o `summary` com as confirmações e `sealed`, sem selar. O laço acima pode usá-lo em vez de recalcular status e `in_grace` à mão (um `view()` por `$ago`; o `settled` do `cycle` diz se é selado).
- **Custo:** até 13 × (`compute` + 2 consultas pequenas) por grupo. **Medido no Painel** (item 093 do backlog; banco de testes em Docker, `artisan serve` no Windows): a varredura de 12 ciclos quitados e não selados custa ≈49 consultas e ≈0,5 s **por grupo**. Aqui é um só grupo por chamada, então é aceitável sem otimizar; a validação local mede com 12 ciclos semeados (§6). Se a D-N2 for A, a varredura pode reusar `firstPossibleEntryDate` para nem calcular ciclo anterior à primeira entrada (esses ciclos seriam descartados pela regra do ciclo vazio de qualquer jeito).
- **Efeito colateral:** nenhum. Não usa `sealCycleIfSettled`.

## 2. specify §2.2 — Relatório de Fechamento: `GET /groups/{groupId}/expenses/closing-report?cycle_start=YYYY-MM-DD`

### 2.1 Resolução do ciclo (`cycle_start` → ciclo)

- Validação: `cycle_start` obrigatório, `date_format:Y-m-d`.
- **1º:** procurar `GroupCycleSnapshot` com esse `group_id` + `cycle_start`. Se existir e estiver selado, usa o snapshot e o `cycle_end` gravado, `status = 'closed'`, `settled = true`. Isso protege ciclos antigos caso o `closing_day` do grupo tenha mudado depois (recalcular os limites daria 404 para um snapshot legítimo).
- **2º:** senão, `BillingCycle::cycleFor($group->closing_day, $start)` (o ciclo que contém essa data) e exigir que `start` devolvido seja igual ao pedido, senão 404. O status vem de `BillingCycle::statusFor($group->closing_day, $start, now())` (`BillingCycle.php:49`): `future` → 404; `closed` → `closed`; `open` → `open`, ou `closed_manually` se houver snapshot de fechamento manual ativo. Sem laço de `cycles_ago`, então não há teto de 12 ciclos aqui.
- Fonte dos números: snapshot se selado, `CycleSummaryService::compute` ao vivo nos demais. **Não** chama `sealCycleIfSettled` (é a diferença deliberada em relação a `summary()`). Não refatora `summary()`, que continua selando. **Pela D-N3 (A):** a escolha de fonte é `CycleSummaryService::viewAt(Group, Carbon $start)`, e `view($group, $cyclesAgo)` do Painel passa a delegar a ele (os testes de `view()` seguem verdes sem edição). **Com B:** são ~10 linhas no controller, comentadas com a referência ao ramo do snapshot de `ExpenseController::summary()` (em 2026-10-24, `:664-700`).
- `settlements` passam por `CycleSummaryService::withConfirmations` nos dois caminhos, como em `summary()`; `view()`/`viewAt` já o aplicam.

### 2.2 Agregação credor → devedores: `App\Support\ClosingBreakdown`

- Classe pura (array entra, array sai), como `BillingCycle`, para testar sem HTTP e manter `ExpenseController` (já com ~1400 linhas) sem crescer à toa (Constitution §1, item 3: controllers finos).
- `ClosingBreakdown::build(array $expenses, Collection $members): array` recebe `expenses` **no formato de summary** (vivo ou snapshot) e devolve:
  ```
  creditors: [ { creditor{id,name,avatarUrl}, advanced_total,
                 debtors[ {id,name,avatarUrl,amount} ] } ]
  ```
- Regras: ignora entrada com `bornPaid` verdadeiro; credor = `userPayerId` (fallback por `payerName` → membro); devedor = participante ≠ credor; valor de cada fatia = `value / max(count(participants), 1)`, somado por par **sem arredondar** e arredondado no fim; `advanced_total` = soma de `value` das despesas do credor (sem `bornPaid`). Ordena credores por `advanced_total` decrescente e devedores por `amount` decrescente.
- **Snapshot antigo:** `participantDetails` ausente → resolve ids e avatares pelos `members` do grupo casando por nome; `bornPaid` ausente = `false`; `valuePerPerson` não é usado (recalcula de `value`). Participante que não casa com nenhum membro vira devedor sem `id` (não derruba o relatório).
- Não duplica regra no cliente (`05-context-frontend.md`, "Convenções fixas").

### 2.3 Resposta

```
{ group{id,name}, cycle{start,end,closes_at,status,settled},
  totals, expenses, balances, settlements,   // mesmo formato de summary
  creditors }                                 // novo, de ClosingBreakdown
```

- Reconciliação testável: para todo par (A, B), `bruto(B→A) − bruto(A→B)` iguala o `settlement` líquido, com tolerância de R$ 0,01 (efeito do arredondamento final). Não se exige igualdade com `grossDebts`, que arredonda cada fatia antes de somar.

## 3. specify §2.3 — Tela de Relatórios com duas abas (`frontend/src`)

- **`pages/GroupReports.tsx`:** título "Relatórios"; `Tabs` MUI (padrão já em `SummarySidePanel.tsx:54`) com `Acompanhamento` | `Histórico`. Estado na URL via `useSearchParams` (`?aba=acompanhamento|historico`, valor inválido ou ausente = `acompanhamento`; `?ciclo=YYYY-MM-DD` = ciclo aberto no Fechamento). Trocar de aba limpa `ciclo`.
- **Histórico:** o conteúdo atual (`useGroupCycleHistory`, lista paginada) sem mudar de regra. Clicar num item faz `setSearchParams({aba, ciclo: cycle.cycle.start})` e renderiza `ClosingReportPanel`, no lugar do `CycleDetailPanel` de hoje.
- **Acompanhamento:** `hooks/useGroupPendingCycles.ts` (mesmo padrão de `useGroupCycleHistory.ts`: axios + `Authorization`, 401 → `navigate('/')`, erro amigável) e `components/PendingCyclesList.tsx`: um cartão por ciclo com
  - período (`formatDate`, mesmo helper local de `GroupReports`),
  - chip de status: `cycleStatusChip[status]` (`useGroupCycle.ts:91`); carência = "Fecha em dd/mm" (`color: 'info'`); `awaiting_seal` = "Quitado" (`color: 'success'`),
  - barra de progresso `pago / total` e "Acertos confirmados X de Y" (omitido quando Y = 0),
  - `X despesas pendentes`,
  - botão "Ver fechamento".
- Estados: carregando (`CircularProgress`), erro (`Typography color="error"`), vazio ("Nenhum ciclo aguardando pagamento.").
- Tipos novos em `hooks/useGroupPendingCycles.ts` (`PendingCycle`) e `hooks/useClosingReport.ts` (`ClosingReport`), reaproveitando `SummaryCycle`/`SummaryTotals`/`SummaryExpense`/`SummaryBalance`/`SummarySettlement` de `useGroupCycle.ts`; `cycle.in_grace` e `awaiting_seal` entram como campos opcionais para não quebrar o tipo de `Summary`.

## 4. specify §2.2 (apresentação) + §2.4 — `ClosingReportPanel` e impressão

- **`hooks/useClosingReport.ts`:** busca `GET .../closing-report?cycle_start=` quando `ciclo` está na URL; refaz ao mudar o ciclo; 404 → mensagem "Ciclo não encontrado." (link de outro grupo ou data inválida).
- **`components/ClosingReportPanel.tsx`**, nesta ordem:
  1. Cabeçalho: nome do grupo, período, chip Prévia / "Aguardando pagamentos" / "Quitado", "Emitido em dd/mm/aaaa" (data do cliente no momento da renderização) e o botão **Imprimir / Salvar PDF**.
  2. Três cartões de totais (reaproveita a visualização de `CycleDetailPanel.tsx:46-90`; extrair para `CycleTotalsCards` em vez de copiar).
  3. **Credores → devedores:** por credor, "Adiantou R$ X", e sob ele a lista "Fulano deve R$ Y". Rótulo de seção: "Valores por despesa (bruto)".
  4. **Acertos:** `SettlementList` com a nova prop opcional `showConfirmation` (default `false`, então o uso atual em `SummarySidePanel` não muda). Quando verdadeira, cada linha mostra o chip "Confirmado em dd/mm" ou "Aguardando comprovante" a partir de `confirmedAt`. **Com a D-N1 (A):** mostra também `confirmedNote` (a descrição que o devedor deu ao enviar o comprovante), como texto, quando houver. Rótulo: "Acertos (líquido entre pares)", com a legenda de uma linha explicando bruto × líquido.
  5. **Despesas do ciclo:** extrair a lista de `CycleDetailPanel.tsx:92-135` para `components/CycleExpenseList.tsx` e usar nos dois lugares (sem duplicar). **Com a D-N1 (A):** `CycleExpenseList` mostra o `paymentNote` da despesa, quando houver (snapshot antigo sem o campo vale `null`).
- **Imprimir:** o botão chama `window.print()`. O painel monta um `GlobalStyles` do MUI enquanto está na tela:
  ```
  @media print {
    .no-print { display: none !important; }
    body { background: #fff !important; }
    @page { margin: 12mm; }
  }
  ```
  e a classe `no-print` vai em: `<nav>` de `layouts/Sidebar.tsx:17`, `<header>` de `layouts/group/GroupHeader.tsx:46`, abas e lista de ciclos e o botão Imprimir em `GroupReports`/`ClosingReportPanel`. Os dois arquivos de layout ganham só um `className`. O drawer móvel (`MobileNavDrawer`) é `temporary` e não existe no DOM fechado, então não precisa da classe.
- **Responsivo:** `Card`/`Stack` do MUI (como `SettlementList`), sem `Table`, para caber em celular. Strings em pt-BR. Sem evento de analytics (`docs/analytics/README.md` não se aplica).

## 5. specify §2.5 — Compatibilidade e entrega

- **API:** dois endpoints aditivos. `cycles`, `summary`, `focus-cycle`, `gross-debts` não mudam (Constitution §4.1). Sem migration.
- **Ordem de deploy:** o merge em `main` dispara backend, frontend e site juntos. Se o frontend publicar antes do backend terminar, as abas mostram o estado de erro por alguns minutos e se recuperam sozinhas. Não exige ação.
- **Segurança:** rodar o agent `security-reviewer` nas duas rotas novas (dado financeiro, `jwt.auth`, checagem de membro) e o `pr-readiness-checker` antes do PR (`.claude/agents/`).
- **App Expo (`app/`):** não muda; o contrato novo é aditivo e fica disponível para a migração futura.

## 6. Testes e validação

- **Backend (Feature, padrão `ExpenseControllerCycleHistoryTest`: `Carbon::setTestNow`, `DatabaseTransactions`):**
  - `ExpenseControllerPendingCyclesTest`: exclui selado, vigente aberto, futuro e ciclo vazio; inclui fechado pendente, fechado manual ativo e carência (`in_grace`); contagem de acertos e de despesas pendentes; `awaiting_seal` quando a última despesa pendente é excluída **na janela de carência** e o ciclo depois fecha por data (caminho (a) do §0.1.2); ordem; não-membro → 404; **não cria snapshot nem notificação**.
  - `ExpenseControllerClosingReportTest`: ciclo selado lê do snapshot; ciclo fechado pendente ao vivo; ciclo vigente = Prévia; futuro → 404; `cycle_start` que não é limite de ciclo → 404; snapshot de grupo com `closing_day` alterado continua abrindo; snapshot antigo sem `participantDetails`/`bornPaid`; reconciliação bruto × líquido (§2.3); não-membro → 404; **não grava**.
  - `ExpenseControllerCycleIsFullySettledTest`, `FocusCycleTest`, `ExpenseControllerSummaryTest` e `CycleSummaryServiceTest` sem edição, verdes (sentinelas: o §0.3 já foi feito pelo Painel, então esta feature não deve alterar o comportamento deles).
  - Se a D-N3 for A: `CycleSummaryServiceTest` ganha os casos de `viewAt` (selado lê o snapshot por `cycle_start` mesmo com `closing_day` alterado; não selado calcula ao vivo; futuro e fora de fronteira sinalizados; **não grava**), e os testes de `view()` e de `PersonalOverviewService` do Painel seguem verdes sem edição.
  - Se a D-N1 for A: um caso por lugar (`CycleExpenseList` com `paymentNote`, `SettlementList` com `confirmedNote`) e um de snapshot antigo sem `paymentNote`.
- **Backend (Unit):** `tests/Unit/ClosingBreakdownTest`: dois credores cruzados, `bornPaid`, participante sem id, arredondamento, entrada vazia.
- **Frontend (Vitest, padrão de `GroupReports.test.tsx`: `vi.mock('axios')`, `MemoryRouter`):**
  - `GroupReports.test.tsx` precisa de ajuste: a aba padrão passa a ser Acompanhamento, então os testes do histórico entram com `initialEntries={['/?aba=historico']}` e o teste de detalhe passa a esperar o `GET closing-report`.
  - Novos: abas e URL (`?aba`, `?ciclo`), `PendingCyclesList` (chips de carência, "Quitado", progresso, vazio, erro), `ClosingReportPanel` (credores → devedores, cada tipo de acerto, 404), `SettlementList` com e sem `showConfirmation` (o caso sem a prop é regressão), clique em Imprimir chama `window.print`, a classe `no-print` presente nos elementos esperados.
- **Comandos:** `vendor/bin/pint`, `php artisan test`, `npx tsc --noEmit`, `npx vitest run --maxWorkers=2` (máquina com pouca RAM livre, como registrado no bugfix `20261006-acerto-comprovante-sem-cycles-ago`).
- **Validação local ponta a ponta:** stack isolada (MySQL 3307, backend 8001, Vite 3001, com limpeza no fim). Semear um grupo com 12 ciclos (um selado, um pendente com acertos sem comprovante, um em carência, um quitado sem selo). Conferir no navegador as duas abas, o Fechamento e o "Salvar como PDF" pelo Ctrl+P (a folha de impressão só se valida na impressão real; o teste automático cobre a classe, não o resultado visual). **Parar e esperar o OK do usuário antes de qualquer push/PR.**

## 7. Ordem de execução

1. ~~§0.3: refatorar `summaryIsFullySettled`~~ — já feito pelo Painel (ver a atualização de 2026-10-22). Se a D-N3 for A: `CycleSummaryService::viewAt` + testes, com `view()` delegando a ele (testes existentes verdes sem edição).
2. §2.2: `ClosingBreakdown` + testes unitários.
3. §1: `pendingCycles` (no `CycleSummaryService`, se a D-N2 for A) + rota + testes de feature.
4. §2: `closingReport` + rota + testes de feature.
5. §3 e §4 (hooks, tipos): `useGroupPendingCycles`, `useClosingReport`.
6. §4: extrações (`CycleExpenseList`, `CycleTotalsCards`), `SettlementList.showConfirmation`, `ClosingReportPanel`, `PendingCyclesList`.
7. §3 e §4: `GroupReports` com abas, impressão e `no-print` nos layouts; ajuste dos testes existentes.
8. Documentação: `consultas-tela-relatorio.md` (consultas das duas abas e do Fechamento), `implementation.md`, item de backlog (§8). `01-specify.md` não descreve Relatórios, então não precisa de edição.
9. Validação local e gate humano antes do PR (fluxo de `docs/sdd/04-implementation.md` §1: um PR único contra `dev`, merge humano).

## 8. Achados tangenciais (não bloqueiam; vão para `docs/backlog/`)

- **`update()`/`destroy()` de despesa e a edição de despesas `FIXED` não selam o ciclo.** Quando uma dessas ações zera a última pendência (na janela de carência, ou em `FIXED`), o ciclo fica quitado sem snapshot e só sela se alguém abrir esse ciclo em `summary()` (origem do estado "Quitado" do §0.1.2). Correção possível: chamar `sealCycleIfSettled` nesses caminhos, ou selar na virada para `closed`. Isso muda a regra de selagem e a notificação `cycleSettled`, então é outra feature. Registrar como item de backlog no fim desta feature (task de doc), prioridade BAIXA, depois de medir em produção quantos ciclos estão nesse estado (consulta: ciclos fechados sem `settled_at` cujo `cycleIsFullySettled` é verdadeiro; o `awaiting_seal` do Acompanhamento já os revela). **Relacionado (2026-10-22):** o item 093 do backlog registra o custo do `focus()` do Painel com esses mesmos ciclos quitados e não selados, e a opção de selar fora da leitura (comando agendado, sem `Notifier` no retroativo) atacaria a origem do `awaiting_seal` (o caso 2 daquele item). Ela **não** resolve o ciclo só com `bornPaid`, que nunca sela por desenho e já fica fora do Acompanhamento.
