# Specify — Despesa parcelada retroativa

> Feature: permitir cadastrar uma despesa **Parcelada** cujo mês de início cai numa competência já fechada — a despesa é criada, as parcelas dos ciclos fechados nascem quitadas, e a pendência passa a ser contabilizada só a partir do menor ciclo ainda aberto. Pedido novo do usuário (conversa de 2026-09-03), sem épico correspondente em `docs/sdd/03-tasks.md`.

Versão: 1.0 · Criado em: 20260903

---

## 1. Problema

O usuário faz uma compra parcelada no cartão (ex.: adestrador, 6x) e só vai
registrá-la no app semanas depois — quando as primeiras parcelas já venceram e a
competência do mês de início já fechou. Hoje o app **não deixa cadastrar**:

- `ExpenseController::store()`
  ([backend/app/Http/Controllers/ExpenseController.php:353](../../../backend/app/Http/Controllers/ExpenseController.php#L353))
  chama `rejectIfCompetenceClosed($group, Carbon::parse($request->date_payment))`
  para **todo** `expense_type`, olhando só o `date_payment`. Para uma Parcelada
  iniciada num mês fechado, `date_payment` cai num ciclo `closed` → **HTTP 422**
  `{"error":"Não é possível alterar dados de uma competência já fechada."}` e a
  despesa nunca é criada.
- Esse 422 é comportamento **deliberado** hoje: coberto por
  `ExpenseControllerStoreTest::test_rejects_expense_with_date_payment_in_an_automatically_closed_cycle`
  (+ variante `..._manually_closed_cycle` e `..._fixed_expense...`), e o bugfix
  `docs/bugfix/concluidos/20260901-expense-store-update-422.md` afirma
  explicitamente que "criar despesa em competência fechada deve mesmo dar 422".
- Toda `Quota` também nasce `paid = false` por invariante, coberta por
  `test_installments_expense_quotas_start_as_pending_even_if_client_sends_paid_true`
  — o cliente não decide o status inicial de pagamento.

O comportamento esperado pelo usuário: a despesa parcelada retroativa **é
registrada**; as parcelas que caem em ciclos já fechados entram como **quitadas**
(nem o credor está esperando receber, nem os devedores devendo por elas); e a
contabilização de pendência começa a partir do **menor ciclo ainda aberto**
(incluindo o mês em janela de carência), como se a despesa tivesse sido lançada a
tempo em cada mês anterior e já acertada.

### Enquadramento na Constitution

- **§4.1 (compatibilidade de contrato):** não é mudança *breaking*. `store()`
  passa a **aceitar** um caso que hoje recusa (422 → 201) só para
  `IN_INSTALLMENTS`; o formato da resposta de sucesso e o status `201` não mudam,
  e nenhum cliente existente quebra (o comportamento fica estritamente mais
  permissivo). `IN_CASH` e `FIXED` seguem recusados como hoje.
- **§4.2 (migrations aditivas):** **sem migration** — `ex_quotas` já tem
  `paid`, `paid_at`, `paid_by` (migration `2026_08_22_120000_...`).
- **§2.2 (regra de negócio nova exige teste PHPUnit):** a feature altera regra de
  fluxo financeiro → teste novo/atualizado em `backend/tests/Feature/ExpenseControllerStoreTest.php`.

## 2. Requisitos

### 2.1 `store()` aceita despesa Parcelada com início em competência fechada

Para `expense_type === 'IN_INSTALLMENTS'`, a criação **não é mais recusada** só
porque o `date_payment` (mês de início) cai num ciclo `closed`. A composição da
despesa (quotas, soma, quantidade) e as demais validações (`payers` são membros,
grupo não deletado, etc.) permanecem idênticas.

`IN_CASH` e `FIXED` **não mudam**: continuam passando por
`rejectIfCompetenceClosed($group, $date_payment)` e recebendo 422 quando a
competência do `date_payment` está fechada (automática ou manualmente).

### 2.2 Parcelas de ciclo fechado nascem quitadas

Ao criar as `Quota` da despesa, cada parcela cujo `date_expected` cai numa
competência **`closed`** (por data — `BillingCycle::statusFor(closing_day,
date_expected, now) === 'closed'`) nasce com:

- `paid = true`
- `paid_at = now()`
- `paid_by = user_payer_id` (o credor da despesa)

As parcelas do **menor ciclo ainda aberto** (inclui o mês em janela de carência,
que ainda é `open`) e as dos ciclos futuros nascem `paid = false`, como hoje.

Efeito por estado do ciclo passado (decidido com o usuário — abordagem "só marcar
como paga, sem migration"):

- Ciclo passado **selado** (`GroupCycleSnapshot.settled_at`): `summary()` serve o
  snapshot congelado — a parcela nova é **inerte** para aquele mês (não aparece no
  resumo, não reabre acerto, `focus-cycle` não volta para lá).
- Ciclo passado **`closed` mas ainda não selado**: `computeCycleSummary()`
  recalcula ao vivo — a parcela aparece como **linha já paga** e entra no acerto
  daquele mês. Limitação **aceita**: um grupo que fecha e acerta os meses em dia
  não cai nesse caso.

### 2.3 Guarda-corpo: recusar despesa 100% no passado

Se **todas** as parcelas da despesa caem em competências `closed` (nada a
acompanhar num ciclo aberto/futuro), `store()` recusa com **422** e mensagem
acionável (§2.4). O caso de uso é "registrar uma compra parcelada em andamento",
não "reconstruir histórico já encerrado" — e sem parcela em aberto a despesa só
tocaria ciclos fechados.

### 2.4 Mensagem de erro acionável

A mensagem do 422 de §2.3 deixa claro o que fazer — que a despesa parcelada
precisa ter ao menos uma parcela num ciclo ainda aberto (ex.: *"Esta despesa
parcelada está inteira em competências já fechadas. Para registrá-la, ao menos a
última parcela precisa cair num ciclo ainda aberto."*). A mensagem genérica atual
("Não é possível alterar dados de uma competência já fechada.") continua valendo
para `IN_CASH`/`FIXED`.

> A apresentação desse erro no frontend (trocar o `window.alert()` nativo por um
> `<Alert>` no padrão do app) é o **outro entregável**, tratado no BFF
> `docs/bugfix/concluidos/20260903-expense-form-feedback-erro.md` (PR #143) — não
> faz parte desta feature.

### 2.5 Parcelas retroativas não disparam notificação

As parcelas marcadas `paid` na criação **não** disparam `Notifier::expensePaid`
— é registro retroativo de algo já acontecido, não um evento de pagamento agora.
`Notifier::expenseCreated($expense)` (a notificação de despesa criada) continua
disparando normalmente, uma vez, para a despesa.

## 3. Fora de escopo desta feature

- **Frontend** — o form já envia hoje `date_expected` de meses passados
  (`frontend/src/utils/installments.ts`); nenhuma mudança de cliente é necessária
  no caminho feliz. A UX da mensagem de erro é o BFF PR #143.
- **`update()`** — converter uma despesa já existente em Parcelada retroativa, ou
  reparcelar para o passado, continua sob as travas atuais.
- **`IN_CASH` e `FIXED` retroativos** — seguem recusados em competência fechada.
- **Migration / coluna nova** em `ex_quotas` (ex.: marcar "parcela retroativa"
  para excluí-la do cálculo de ciclos fechados não selados) — a abordagem
  escolhida não mexe no schema.
- **Tocar `collectCycleEntries()` / `computeCycleSummary()`** — o cálculo de ciclo
  não muda; a feature só decide o `paid` inicial das quotas em `store()`.
- **App Expo (`expense/app`)** — não existe form de despesa lá ainda.
- **Selar ciclo passado automaticamente** ao criar a despesa retroativa — uma
  parcela isolada não quita um ciclo com outras pendências; `store()` não chama
  `sealCycleIfSettled`.
