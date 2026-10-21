# Plan — Painel Financeiro Pessoal

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261010

Referências de linha são do `ExpenseController.php` em `dev` `5dc5ac9c80` (2026-10-10), já com o rateio de parcelas. Reconferir ao implementar.

---

## 0. Decisões transversais

### 0.1 Camadas (Constitution §1 item 3; `06-context-backend.md`: Controller fino → Service)

- **`App\Services\CycleSummaryService`** (novo, extração): o lado de **leitura** do cálculo de ciclo, que hoje mora privado em `ExpenseController` (1742 linhas). Sem efeito colateral.
- **`App\Services\PersonalOverviewService`** (novo): monta a visão pessoal a partir do serviço acima.
- **`App\Http\Controllers\OverviewController`** (novo, fino): autentica, chama o serviço, devolve JSON.
- O padrão já existe: `PreRegistrationService` injetado por construtor em `PreRegisterController`.

### 0.2 O que sai do `ExpenseController` e o que fica

| Hoje (`ExpenseController.php`) | Destino em `CycleSummaryService` | Observação |
|---|---|---|
| `collectCycleEntries` (`:1573`) | `entries(groupId, start, end)` | também usado por `close()`, `grossDebts()` e `resolveQuotaForCycle` (`:1716`) |
| `computeCycleSummary` (`:1264`) | `compute(group, start, end)` | mesma lógica, sem edição de regra |
| `attachSettlementConfirmations` (`:1430`) | `withConfirmations(groupId, start, settlements)` | |
| `cycleIsFullySettled` (`:1460`) | `isFullySettled(group, start, end)` + `summaryIsFullySettled(summary, groupId, start)` | a segunda evita recalcular quando o summary já está em mãos (o mesmo desenho do plan de Relatórios §0.3) |
| laço de `focusCycle` (`:742-771`) | `focus(group): int` | `FOCUS_LOOKBACK` (`:30`) vai junto |
| *(novo)* | `view(group, cyclesAgo): array` | escolha de fonte de `summary()` (`:664`), **sem selagem preguiçosa**, ver §1.2 |

**Fica no controller:** `sealCycleIfSettled`, `unsealIfBroken` (têm efeito colateral e `Notifier`), `materializeFixedOccurrenceQuota`, `resolveQuotaForCycle`, `rejectIf*`, e as ações `pay`/`unpay`/`confirmSettlement`.

**Restrição que veio de ler os testes:** `ExpenseControllerCycleIsFullySettledTest` chama `cycleIsFullySettled` **por reflexão** (`new ReflectionMethod(ExpenseController::class, 'cycleIsFullySettled')`, `new ExpenseController` sem argumentos), e `ExpenseControllerMaterializeFixedOccurrenceQuotaTest` faz o mesmo com `materializeFixedOccurrenceQuota`. Portanto:

- `ExpenseController` **não ganha construtor** com injeção; resolve o serviço por `app(CycleSummaryService::class)` num helper privado.
- `cycleIsFullySettled` continua existindo no controller como método privado fino que delega para o serviço. Assim a suíte segue verde **sem editar teste algum**.

### 0.3 Convenções de contrato (valem para o endpoint novo)

- Aditivo (Constitution §4.1/§4.3), sob `jwt.auth`, só leitura, sem `Notifier`, sem snapshot, sem selagem.
- Campos novos em `snake_case` em todos os objetos novos (`confirmed_at`, `can_confirm`, `my_share`), um único estilo para web e para o app Flutter. O frontend adapta para o `camelCase` dos componentes existentes (`confirmedAt`, `confirmedProofUrl`).
- Dinheiro: números JSON com 2 casas (os valores de acerto já vêm arredondados por `compute`; somas de acertos são exatas em centavos e passam por `round(…, 2)`).
- Cálculo, `cycles_ago` e flags `can_*` só no servidor (R4 do specify).

### 0.4 Sequência com Relatórios

O plan de `20261006-relatorios-acompanhamento-fechamento` prevê extrair só `summaryIsFullySettled` dentro do controller e cita linhas antigas (deslocadas ~137 pontos). Depois desta feature, ele passa a **usar `CycleSummaryService`**; atualizar aquele plan antes de gerar o `tasks.md` dele (§9).

## 1. specify §2.1 — `GET /api/me/overview`

### 1.1 Rota e resposta

- `routes/api.php`, junto de `/me` (`:35`), dentro de `jwt.auth`: `Route::get('/me/overview', [OverviewController::class, 'show']);`
- Raiz: `{ "user": {id,name}, "groups": [ ... ] }`. O `user` evita uma chamada extra a `/api/me` só para saber quem é o usuário (o `PayableSettlementList` precisa do id).

```json
{
  "user": { "id": 7, "name": "Isac" },
  "groups": [{
    "group": { "id": 3, "name": "Residencial" },
    "cycle": { "start": "2026-09-01", "end": "2026-09-30", "closes_at": "2026-10-05",
               "status": "closed", "settled": false, "in_grace": false, "cycles_ago": 1 },
    "owe":     { "total": 439.23, "confirmed": 0.00,  "open": 439.23 },
    "receive": { "total": 120.00, "confirmed": 60.00, "open": 60.00 },
    "to_pay": [{ "to_user": { "id": 9, "name": "Nádia", "email": "…", "pix": "…", "avatar_url": null },
                 "amount": 439.23, "confirmed_at": null, "confirmed_proof_url": null, "can_confirm": true }],
    "to_receive": [{ "from_user": { "id": 4, "name": "Norma", "avatar_url": null },
                     "amount": 120.00, "confirmed_at": "2026-10-08T14:02:11-03:00", "confirmed_proof_url": "…" }],
    "my_items": [{ "expense_id": 51, "description": "Construção parede", "date": "2026-09-10",
                   "value": 543.00, "my_share": 135.75, "paid": false, "role": "debtor",
                   "creditor": { "id": 9, "name": "Nádia" },
                   "installment": { "number": 2, "total": 6 },
                   "can_pay": false, "can_unpay": false }],
    "pending_items_count": 3,
    "has_open": true
  }]
}
```

- `to_pay[].to_user` traz os mesmos campos de credor que `gross-debts` já expõe a membros (`id,name,email,pix`) mais `avatar_url`, para reaproveitar `PixPaymentDialog`. Nada além disso do credor.
- `my_items[].installment` só em `IN_INSTALLMENTS` (`installmentNumber`/`installments` do summary); senão `null`.

### 1.2 Escolha do ciclo e da fonte dos números

- **Ciclo em foco:** `CycleSummaryService::focus($group)` devolve o `cycles_ago` pela mesma varredura de `focusCycle` (`:742-771`). `focusCycle()` no controller passa a só chamar o serviço (comportamento idêntico, coberto por `FocusCycleTest`).
- **Fonte:** `CycleSummaryService::view($group, $cyclesAgo)` replica a escolha de fonte de `summary()` (`:673-709`) **sem a selagem preguiçosa** (`:703`, a única diferença deliberada):
  - snapshot selado → usa o snapshot, `status = 'closed'`, `settled = true`;
  - fechado por data ou fechamento manual ativo e não selado → `compute` ao vivo (`closed_manually` se manual);
  - demais → `compute` ao vivo.
  Devolve `{cycle{start,end,closes_at,status,settled,in_grace}, summary{totals,expenses,balances,settlements}, sealed}`.
- **Risco de deriva** entre `view()` e `summary()`: `summary()` **não é refatorado** nesta feature (menos risco), e um teste de reconciliação compara `cycle`, `totals` e `settlements` do overview com a resposta de `summary?cycles_ago=N` do mesmo ciclo (§6).
- **Custo:** por grupo, `focus` calcula cada ciclo fechado/em carência candidato até achar pendência (o vigente aberto é pulado sem calcular) e `view` recalcula o escolhido: tipicamente 1 a 3 `compute` por grupo. A TASK-427 acrescentou o curto-circuito dos ciclos anteriores à primeira entrada possível do grupo (a menor entre `date_payment` das despesas vivas e `date_expected` das quotas); a validação local mede com vários grupos e ciclos (§6).

### 1.3 Derivação por usuário (`PersonalOverviewService`)

Para cada grupo, sobre o `summary` do `view()`:

- **`to_pay`:** acertos com `from_user_id = eu`; `to_user` resolvido pelos `members` do grupo (carregados em `with('members')`). `can_confirm` = status `closed` ou `closed_manually` **e** `settled = false` (a mesma condição de `confirmSettlement`, `:1185-1192`).
- **`to_receive`:** acertos com `to_user_id = eu`; `from_user` pelos membros.
- **`owe` / `receive`:** `total` = soma dos `amount`; `confirmed` = soma dos que têm `confirmed_at`; `open` = `total − confirmed`. Tudo `round(…, 2)`.
- **`my_items`:** entradas de `summary.expenses` em que sou participante ou credor, excluindo `bornPaid` (histórico, não dívida; mesma regra de `compute`).
  - `role = 'creditor'` se `userPayerId = eu`, senão `'debtor'`.
  - `my_share` = `valuePerPerson`; é a parte de quem participa (0 se só sou credor e não participo).
  - `can_pay` = credor e `paid = false`; `can_unpay` = credor e `paid = true`. Ciclo futuro nunca é escolhido por `focus`, então não precisa de guarda extra.
  - Participantes de snapshots antigos sem `participantDetails`: resolver id por nome pelos `members` do grupo.
- **`pending_items_count`:** itens com `paid = false`. **`has_open`:** `owe.open > 0` ou `receive.open > 0`.
- **Ordenação dos grupos** (D5): `has_open` primeiro, depois nome. Grupo sem nada em aberto aparece, com totais zerados ("Em dia" é rótulo do cliente).
- Usuário sem grupos: `groups: []`.

### 1.4 Autorização e segurança

- Escopo **na consulta**: `Group::where('deleted', 0)->whereHas('members', fn ($q) => $q->where('user_id', auth()->id()))`, o mesmo filtro de `GroupController::index` (`:19-21`). Não há grupo alheio para filtrar depois.
- Nada de `authorizeGroupMembership` por grupo (já escopado), mas teste explícito de que o grupo de outro usuário nunca aparece (Constitution §1 item 6, §6.5).
- Rodar o agent `security-reviewer` na rota nova (dado financeiro, e-mail e Pix do credor já visíveis a membros).

## 2. specify §2.2 — Página "Painel" (`frontend/src`)

- **Tipos** em `types/overview.ts` (padrão de `types/group.ts`: tipado pelo endpoint).
- **`hooks/useMyOverview.ts`:** mesmo padrão de `useGroupCycleHistory.ts` (axios, `Authorization`, 401 → `navigate('/', {replace:true})`), expondo `{ data, loading, error, reload }`.
- **`pages/MyPanel.tsx`**, envolta em `DespesasThemeScope` como `Payments.tsx`: carregando, erro, vazio ("Você ainda não participa de nenhum grupo."), lista de `GroupPositionCard`. Snackbars de sucesso/erro iguais aos de Payments.
- **`components/GroupPositionCard.tsx`:** nome, chip de ciclo (`cycleStatusChip` de `useGroupCycle.ts:85`; carência = "Fecha em dd/mm"), três totais (A pagar = `owe.open`, A receber = `receive.open`, Já confirmado = `owe.confirmed`), selo "Em dia" quando `has_open = false`, alerta quando `has_open`, link "Ver ciclos" para `/groups/:id/payments`. `Collapse` abre o detalhe.
- **Detalhe:**
  - **Você paga:** reaproveita `PayableSettlementList` por um adaptador (`to_pay` → `SummarySettlement[]` + `SummaryBalance[]`), `canConfirm = to_pay.some(s => s.can_confirm)` (a condição é do ciclo, igual para todos).
  - **Você recebe:** componente pequeno `ReceivableList` (nome, valor, "Comprovante enviado" ou "Aguardando"); o `SettlementList` atual não mostra confirmação e não é alterado aqui (Relatórios prevê mexer nele).
  - **Seus itens:** `MyItemsList` (descrição, data, parte, credor, Paga/Pendente, "Parcela n/N"), com "Marcar como paga" / "Desfazer" quando `can_pay`/`can_unpay`.
- **Responsivo:** `Card`/`Stack` do MUI, sem `Table`. pt-BR. Conferir `assets/images/screen/movel.png` e `desktop.png` ao fechar o layout (`05-context-frontend.md`).
- **Rota e menu:** `App.tsx`, dentro do bloco `SimpleShellLayout` (junto de `/meus-grupos`, `:64`): `<Route path="/painel" element={<MyPanel />} />`. Item `{ label: 'Painel', icon: DashboardOutlinedIcon, to: '/painel' }` em `layouts/simpleNavItems.ts` e em `groupNavItems` (`layouts/group/GroupSidebar.tsx:27`), como primeiro item, para ser alcançável de dentro de um grupo (o móvel reaproveita os mesmos itens). O título do cabeçalho sai do item ativo, então `/painel` mostra "Painel".

## 3. specify §2.3 — Ações (regras de hoje, sem regra nova no cliente)

| Ação no painel | Reuso | Detalhe |
|---|---|---|
| Enviar / reenviar comprovante do acerto | **extrair de `Payments.tsx`** `hooks/useConfirmSettlement.ts` (POST `settlements/confirm` com `to_user_id`, `comprovante`, `cycles_ago`) e `components/ProofUploadDialog.tsx` (diálogo de foto, hoje duplicado inline em `Payments.tsx:330-354` e `:367-401`) | `Payments.tsx` passa a usar os dois (refatoração sem mudar comportamento). `cycles_ago` vem do payload do servidor |
| Pagar via Pix | `PixPaymentDialog` como está | sem chave Pix do credor: botão desabilitado com a mesma mensagem de `GroupGrossDebtsPanel` ("ainda não cadastrou uma chave Pix") |
| Marcar como paga | `usePaymentActions(null, null, cycle.cycles_ago, reload).handlePay(id, file)` | os dois primeiros argumentos só alimentam `canPay`/`canUnpay`, que o painel **não usa** (a visibilidade vem de `can_pay`/`can_unpay` do servidor). Foto obrigatória no cliente com `ProofUploadDialog` (D7) |
| Desfazer pagamento | `usePaymentActions(...).handleUnpay(id)` | sem comprovante |

- `usePaymentActions` **não é alterado**. Uma instância por `GroupPositionCard`, porque o hook é por `cyclesAgo` e por `reload`.
- Depois de qualquer ação com sucesso, `reload()` do `useMyOverview` refaz o `GET /me/overview`.

## 4. specify §2.4 — Semântica dos números

- Rótulos do cliente: "A pagar" (`owe.open`), "A receber" (`receive.open`), "Já confirmado" (`owe.confirmed`), com a legenda "Valor líquido entre pares, o mesmo de Pagamentos".
- Paga/Pendente nos itens é informação e nunca entra em `owe`.
- Vocabulário: aqui "devedor" **exclui** o credor, como nos relatórios (`01-specify.md`). A feature `20261010-filtros-credor-devedores-status-despesas` trata o credor como participante no filtro de Despesas; os dois rótulos ficam distintos e documentados (§9).

## 5. specify §2.5 — Compatibilidade e entrega

- Um endpoint aditivo; `summary`, `focus-cycle`, `gross-debts`, `cycles`, `pay`, `unpay` e `settlements/confirm` mantêm contrato e comportamento. Sem migration.
- A extração do §0.2 é movimento de código: nenhuma regra muda, e os testes atuais são a prova.
- Sem analytics novo: a rota `/painel` já gera `page_view` pelo `RouteTracker` (`App.tsx:40`).
- O contrato único serve ao app Flutter (`07-context-mobile.md`: o cliente não reimplementa saldo, acerto nem ciclo).
- Merge e deploy são gates humanos (Constitution §5.2); sem migration, a ordem backend/frontend não quebra nada (o frontend novo só é alcançável pelo menu novo).

## 6. Testes e validação

**Backend (Feature, padrão `FocusCycleTest`: `Carbon::setTestNow`, `DatabaseTransactions`, `User::factory`, `Group::create`):**

- `OverviewTest`: 401 sem token; usuário sem grupos → `groups: []`; só os grupos do usuário e nunca os de outro; grupo `deleted` fora; ciclo em foco em cada caso (fechado pendente, carência com `in_grace`, vigente, vigente selado); valores de devedor, de credor e dos dois papéis no mesmo grupo; `owe`/`receive` com acertos confirmados e não; `can_confirm` por status (`closed`, `closed_manually`, `open`); `can_pay`/`can_unpay` só para o credor; `bornPaid` fora de `my_items`; ordem (`has_open` primeiro); **não cria snapshot nem notificação**; **reconciliação** com `summary?cycles_ago=N` (`cycle`, `totals`, `settlements` iguais).
- `CycleSummaryServiceTest` (Unit/Feature): `view()` nos 3 ramos de fonte; `focus()` igual ao `focus-cycle` nos casos de `FocusCycleTest`.
- **Sentinelas da extração, sem editar:** a suíte inteira; em especial `ExpenseControllerCycleIsFullySettledTest` e `ExpenseControllerMaterializeFixedOccurrenceQuotaTest` (reflexão), `FocusCycleTest`, `ExpenseControllerSummaryTest`, `ExpenseControllerCycleFreezeTest`, `ExpenseControllerCloseTest`, `ExpenseControllerGrossDebtsTest`, `NotifierTriggersTest`.

**Frontend (Vitest, `vi.mock('axios')`, `MemoryRouter`):**

- `MyPanel.test.tsx`: loading, erro, vazio, ordem dos cartões, selo "Em dia", alerta de aberto, carência.
- `GroupPositionCard.test.tsx`: cada ação chama o endpoint certo com o `cycles_ago` **do payload** (regressão da classe de bug de 2026-10-06), Pix desabilitado sem chave, `reload` depois do sucesso.
- `ProofUploadDialog.test.tsx` e `useConfirmSettlement.test.tsx`; `Payments.test.tsx` segue verde sem mudar asserção (8 testes, inclusive o do `cycles_ago`).
- Layouts: `SimpleShellLayout.test.tsx` e `GroupShellLayout.test.tsx` ganham a asserção do link `/painel`; conferir `Sidebar.test.tsx`, `MobileNavDrawer.test.tsx` e `App.test.tsx`.

**Comandos:** `./vendor/bin/pint`, `php artisan test`, `npx tsc --noEmit`, `npx vitest run --maxWorkers=2`.

**Validação local ponta a ponta:** stack isolada (MySQL descartável na 3307, backend na 8001, Vite na 3001; a 3306 é de outro projeto), com desmontagem no fim, na receita do log do bugfix `20261006-acerto-comprovante-sem-cycles-ago.md` §3. Semear um usuário em 3 grupos: (a) ciclo fechado pendente em que é devedor, (b) outro em que é credor, (c) só vigente. No navegador: `/painel` mostra os cartões com os valores certos; devedor envia comprovante e o status muda; credor marca a despesa como paga e o total cai; Pix abre; viewport móvel; usuário sem grupos; 401. Medir o tempo da chamada com vários grupos. Rodar `security-reviewer` e `pr-readiness-checker`. **Parar e esperar o OK do usuário antes de qualquer push/PR.**

## 7. Ordem de execução

1. Extrair `CycleSummaryService` (§0.2), sem mudar comportamento. Suíte inteira verde sem edição.
2. `focus()` e `view()` com testes de equivalência (§1.2).
3. `PersonalOverviewService` + `OverviewController` + rota + `OverviewTest` (§1.3, §1.4). Rodar `security-reviewer`.
4. Frontend: extrair `ProofUploadDialog` e `useConfirmSettlement` de `Payments.tsx` (§3), com `Payments.test.tsx` verde.
5. Frontend: tipos, `useMyOverview`, `GroupPositionCard` e listas, `MyPanel` (§2, §3).
6. Rota `/painel`, item de menu nos dois layouts e ajuste dos testes de layout (§2).
7. Validação local, `pr-readiness-checker`, gate humano antes do PR (um PR único contra `dev`, `04-implementation.md` §1).
8. Documentação: `implementation.md`, itens de backlog (§9), atualização do plan de Relatórios (§0.4).

A numeração `TASK-xxx` do `tasks.md` precisa ser reconfirmada contra `03-tasks.md` e as outras features em andamento (a feature de filtros reserva TASK-406 em diante).

## 8. Riscos

| Risco | Mitigação |
|---|---|
| A extração mexe no núcleo do cálculo de ciclo | Só movimento de código; suíte inteira como prova; diff revisado linha a linha; wrappers privados mantêm os testes de reflexão |
| `view()` divergir de `summary()` com o tempo | `summary()` não muda agora; teste de reconciliação overview × summary |
| Custo com muitos grupos (até ~14 `compute` por grupo no pior caso) | **Medido na TASK-414** (local, MySQL em Docker): grupo vazio ≈ 54 consultas e 20 grupos vazios ≈ 1.082 consultas e 2,8 s; grupo com pendência recente 18 consultas (o foco para cedo). O caso caro é o grupo parado ou sem dados. **TASK-427 feita** (`focus()` sem calcular ciclos anteriores à primeira entrada possível, snapshots numa só consulta): grupo vazio 8 consultas e 36 ms, 20 grupos vazios 122 consultas e ~410 ms, 50 grupos vazios 302 consultas e ~1 s; grupo com pendência recente 19 (+1). Falta o teto de grupos (TASK-428, decisão D9) e o throttle por usuário (TASK-429, D10). O limite de 3 vale só para grupos criados, não para participar |
| Snapshot antigo sem `participantDetails` | Resolver por nome pelos membros; teste dedicado |
| Refatorar `Payments.tsx` (a tela do bug de `cycles_ago`) | `Payments.test.tsx` já fixa o `cycles_ago`; extração sem mudar asserção |
| Dois sentidos de "devedor" (painel × filtros de Despesas) | Rótulos distintos e nota no `implementation.md` |

## 9. Achados tangenciais (não bloqueiam; viram `docs/backlog/` no fim da feature)

- `grossDebts` usa `$expense->payer` sem checar nulo (`ExpenseController.php:969`); `compute` usa `?? null`.
- "Informar pagamento" de `GroupGrossDebtsPanel` é só visual: decidir, com o Painel no ar, se some, vira link para o Painel ou continua.
- `GET /api/dashboard` é stub com nome enganoso, e `Navbar.tsx`/`InternalLayout.tsx` são código morto.
- Atualizar o plan de `20261006-relatorios-acompanhamento-fechamento`: linhas deslocadas e passagem a `CycleSummaryService`.
- O escopo v1 do app (`docs/backlog/app-flutter-google-play.md`) não cita um painel pessoal: avaliar incluir `/me/overview`.
- Depois da 075 (pagamento por parte), `my_items[]` ganha o status individual de forma aditiva.
