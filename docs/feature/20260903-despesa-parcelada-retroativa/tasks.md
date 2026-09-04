# Tasks — Despesa parcelada retroativa

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260903

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-001 | Aceitar Parcelada retroativa em `store()`, com parcelas de ciclo fechado quitadas + testes PHPUnit | backend | plan.md §0, §1, §2, §3, §4 | antes do merge | Pendente |

Task única: o ramo de guarda por `expense_type` (§1) e o `paid` inicial por
parcela (§2) são o mesmo método (`ExpenseController::store()`), o mesmo diff — não
são entregas independentes. O teste PHPUnit anda junto (Constitution §2.2 — regra
de fluxo financeiro nova).

## Critérios de aceite

- **TASK-001**:
  - `POST /api/expenses` com `expense_type = IN_INSTALLMENTS` e `date_payment` /
    primeiras `quotas.*.date_expected` numa competência já fechada (por data)
    responde **201** e cria a despesa — não mais **422**.
  - Cada `ex_quotas` criada cujo `date_expected` cai num ciclo `closed`
    (`BillingCycle::statusFor($group->closing_day, $date, now()) === 'closed'`)
    fica com `paid = 1`, `paid_at` não nulo e `paid_by = user_payer_id`; as demais
    (menor ciclo aberto / carência / futuro) ficam `paid = 0`, `paid_at`/`paid_by`
    nulos.
  - `POST /api/expenses` com `IN_INSTALLMENTS` cujas **todas** as parcelas caem em
    ciclo `closed` responde **422** com a mensagem "Esta despesa parcelada está
    inteira em competências já fechadas. Para registrá-la, ao menos a última
    parcela precisa cair num ciclo ainda aberto." e **não** grava nada em
    `ex_expenses`.
  - `IN_CASH` e `FIXED` com `date_payment` em competência fechada (automática ou
    manual) continuam **422** — `test_rejects_expense_with_date_payment_in_an_automatically_closed_cycle`,
    `test_rejects_fixed_expense_with_date_payment_in_an_automatically_closed_cycle`
    e `test_rejects_expense_with_date_payment_in_a_manually_closed_cycle` seguem
    verdes sem alteração.
  - `store()` **não** dispara `Notifier::expensePaid` para as parcelas quitadas na
    criação; `Notifier::expenseCreated` segue disparando uma vez.
  - `backend/tests/Feature/ExpenseControllerStoreTest.php` cobre: (a) Parcelada
    retroativa criada com parcelas passadas quitadas e `paid_by` correto; (b)
    Parcelada 100% no passado recusada com 422; (c) ajuste de nome/docblock em
    `test_installments_expense_quotas_start_as_pending_even_if_client_sends_paid_true`
    deixando claro que a regra "cliente não decide `paid`" continua para ciclo
    aberto.
  - `backend/tests/Feature/ExpenseControllerSummaryTest.php` (ou `StoreTest`)
    cobre: criada a Parcelada retroativa e selados os ciclos passados
    (`GroupCycleSnapshot.settled_at`), `summary()` do ciclo corrente devolve
    `totals.pending` == só o valor da parcela do menor ciclo aberto (as
    retroativas não entram).
  - `cd backend && ./vendor/bin/pint --test` limpo nos arquivos tocados;
    `php artisan test` verde (suíte completa, sem regressão).
  - Agent `security-reviewer` rodado (toca `ExpenseController` / dado financeiro) e
    `pr-readiness-checker` antes de abrir o PR.
  - `docs/feature/20260903-despesa-parcelada-retroativa/implementation.md` com o
    comando real + resultado de cada verificação acima.
