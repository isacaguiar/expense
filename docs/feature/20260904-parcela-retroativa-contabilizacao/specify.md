# Specify — Contabilização da parcela retroativa

> Feature: quando uma Parcelada retroativa tem parcelas em competências já fechadas, a pendência dos devedores é contabilizada **só a partir do ciclo aberto** — as parcelas de ciclo fechado (nascidas quitadas) ficam visíveis como histórico "já pago", mas não geram acerto/cobrança. Corrige defeito relatado pelo usuário na feature `20260903-despesa-parcelada-retroativa` (PR #144, já em `dev`); escalado do BFF pela Triagem (caixas 1, 2 e 4 de `docs/bugfix/README.md`).

Versão: 1.0 · Criado em: 20260904

---

## 1. Problema

A feature `20260903-despesa-parcelada-retroativa` fez `ExpenseController::store()` marcar `paid = true` cada parcela cujo `date_expected` cai em competência `closed`. Isso tira a parcela de `totals.pending`, mas **não** a tira do acerto: `computeCycleSummary()` monta `balances` / `$owed` / `settlements` (`backend/app/Http/Controllers/ExpenseController.php:1164-1186`) **sem checar `$entry['paid']`** — só `totals.pending` (`:1150`) filtra. Resultado: cada parcela retroativa quitada ainda gera um par devedor→credor em `settlements` em toda competência passada `closed` e **não selada**.

Isso está registrado como "limitação aceita" em `docs/feature/20260903-despesa-parcelada-retroativa/specify.md` §2.2 ("Ciclo passado `closed` mas ainda não selado: a parcela aparece como linha já paga e **entra no acerto daquele mês**. Limitação aceita") e como item §2 do backlog `docs/backlog/expense-parcela-retroativa-paid-by-sem-consentimento.md` (ID 038). O usuário testou em produção e reportou que o comportamento não serve.

### Sintomas reportados (produção, grupo 3878 – Piatã House)

Duas Parceladas retroativas criadas em 04/06/2026 (competência calendário, `closing_day = null`):

| Despesa | id | Parcelas | Total | Credor | Participantes |
|---|---|---|---|---|---|
| Adestrador | 8658 | 6× R$ 292,40 | R$ 1.754,40 | naumel67 (5573) | 6 |
| Construção parede escritório/demolição stiep | 8659 | 5× R$ 543,00 | R$ 2.715,00 | naumel67 (5573) | 6 |

Ambas: parcelas de jun e jul nasceram `paid = true` (`paid_by = 5573`, o credor); ago em diante `paid = false`. **Todas as parcelas existem, com `date_expected` e `value_quota` corretos** — não há defeito de criação. O born-paid acertou: em agosto a competência ainda estava em carência na criação → parcela nasceu `paid = false`.

1. **"O pagamento dos devedores ficou como a pagar."** Em junho (`closed`, não selado), `GET .../summary?cycles_ago=3` retorna `settlements` com 5 × R$ 139,23 (cada participante devendo ao credor), apesar de as duas parcelas de junho serem `paid = true`. O `GET .../gross-debts?cycles_ago=3` do mesmo mês retorna `creditors: []` (filtra `paid` em `:813`) — **as duas telas se contradizem no mesmo ciclo**.
2. **"Só apareceu no mês de início, não apareceu nos meses subsequentes."** Julho e agosto já tinham `GroupCycleSnapshot.settled_at` quando as despesas foram criadas → `summary()` serve a foto congelada (`:533-541`) e as parcelas novas não aparecem nesses meses. Setembro (aberto) e out/nov (futuro) mostram normalmente.
3. O par-fantasma sem `SettlementConfirmation` mantém `cycleIsFullySettled(junho) = false` → `focusCycle()` (`:587-622`) prende a Home do grupo em junho, reforçando a percepção de que "sumiu".

### Enquadramento na Constitution

- **§4.1 — não quebrar**: `computeCycleSummary()` passa a **excluir do acerto** as parcelas retroativas nascidas quitadas. Despesa normal marcada paga pelo credor via `pay()` num ciclo fechado **continua** gerando `settlement` até o devedor confirmar (feature `20260902-pagamento-ciclo-fechado`).
- **§4.2 — migration**: precisa de coluna aditiva `ex_quotas.born_paid` (boolean `default false`). Hoje não há como distinguir "nasceu quitada no cadastro retroativo" de "o credor pagou depois via `pay()`" — os dois gravam `paid = true`, `paid_at = now()`, `paid_by = user_payer_id`.
- **§2.2 — nova regra de fluxo financeiro** → teste PHPUnit novo/atualizado em `backend/tests/Feature/`.

## 2. Requisitos

### 2.1 Parcela retroativa nascida quitada não entra no acerto

Uma parcela `IN_INSTALLMENTS` que **nasceu** `paid = true` em `store()` por cair em competência `closed` (nova coluna `ex_quotas.born_paid = true`) é **inerte para o dinheiro** em `computeCycleSummary()`:

- não entra em `balances`;
- não entra em `$owed` / `settlements`;
- logo não bloqueia `cycleIsFullySettled()` nem arrasta `focusCycle()` para o passado.

A distinção é **`born_paid`, nunca `paid`**: uma parcela marcada paga **depois** pelo credor via `pay()` (`born_paid = false`, `paid = true`) **continua** entrando em `balances`/`settlements` como hoje — inclusive em ciclo fechado (feature `20260902`).

### 2.2 Parcela retroativa quitada permanece visível como histórico

Nas competências passadas **não seladas**, a parcela `born_paid` continua aparecendo em `summary().expenses` como **linha "Paga"** (`paid: true`) e conta em `totals.total` / `totals.paid` (não em `totals.pending`). O usuário quer ver as parcelas espalhadas mês a mês; muda só a ausência de cobrança/acerto.

Em competência **selada** (`GroupCycleSnapshot.settled_at`), a foto congelada continua sendo servida como está — a parcela criada depois não aparece ali (mesma limitação de `20260903` §2.2, caso "selado"). Reverter isso em competências seladas específicas é **dado**, não código — ver §2.5.

### 2.3 Selagem não dispara para competência só-retroativa

`sealCycleIfSettled()` não sela (nem dispara `Notifier::cycleSettled`) uma competência passada cujo **único** conteúdo é parcela `born_paid` — não há acerto real a congelar. O guard de "ciclo sem conteúdo" (`:1324`) passa a considerar "sem despesa real (não-`born_paid`) **e** sem `settlements`".

### 2.4 `store()` grava o marcador

`store()` grava `born_paid = true` exatamente nas parcelas que já hoje nascem `paid = true` (mesma condição `BillingCycle::statusFor($group->closing_day, $date_expected, now()) === 'closed'`). **Nenhuma outra rota grava `born_paid = true`** — `update()`, `pay()` e a materialização de FIXED mantêm `born_paid = false`.

### 2.5 Ajuste dos dados de produção já afetados (grupo 3878)

As parcelas de 8658/8659 já existem sem o marcador. Entregável separado — **script SQL revisado e executado pelo usuário** (gate de produção; detalhe e sequência em `plan.md`):

- `born_paid = 1` nas parcelas de **jun e jul** de 8658/8659 (as que já estão `paid = 1`);
- parcela de **agosto** permanece `paid = 0` / `born_paid = 0` → **dívida real** que os devedores ainda vão pagar (decisão do usuário);
- **julho**: desselar (`settled_at = NULL`) para a parcela de jul aparecer como linha "Paga";
- **agosto**: desselar para a parcela de ago virar pendência cobrável ao vivo.

O script roda **depois** do deploy da correção de código (senão o filtro `born_paid` não existe e a desselagem de julho pode disparar `Notifier::cycleSettled` espúrio).

## 3. Fora de escopo desta feature

- **Item §1 do backlog 038** (credor não consente / não é avisado quando parcelas nascem `paid_by = <ele>`). Esta feature fecha só o §2 (settlement fantasma); o §1 segue aberto no backlog.
- **`grossDebts()`** — já filtra `paid` (`:813`); não muda. A inconsistência residual entre `grossDebts` (filtra todo `paid`) e `settlements` (passará a filtrar só `born_paid`) para **despesas normais** pagas em ciclo fechado é pré-existente e fica para outro item.
- **Recompor snapshots selados** para mostrar parcela retroativa em meses já selados — não há mudança de código; casos pontuais são dado (§2.5).
- **Backfill amplo de `born_paid`** em outros grupos — `plan.md` traz uma query de diagnóstico; se houver despesas afetadas além de 8658/8659, a decisão de backfill amplo vs pontual fica no `plan.md`.
- **Frontend** (`frontend/`, `app/`) — `settlements`/`expenses`/`balances` mantêm o formato; o sintoma some sem tocar no cliente.
- **`IN_CASH` / `FIXED` retroativos** — `store()` segue recusando com 422 (feature `20260903` §2.1).
