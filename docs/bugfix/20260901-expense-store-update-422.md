# Bugfix — Testes de despesa (store/update/destroy) falham com 422 após virada de mês

Versão: 1.0 · Criado em: 20260901 · Branch: `fix/20260901-expense-store-update-422`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [ ] **Auth / autorização / dado sensível** — a correção é **só em arquivos de teste** (`tests/Feature/ExpenseController*Test.php`). Não toca rota, controller, middleware, nem regra de autorização; nenhum comportamento de produção muda.
- [ ] **Migration ou contrato de API** — sem migration; o contrato da API fica **idêntico**. O 422 em competência fechada é o comportamento correto e permanece.
- [ ] **Causa raiz obscura / correção ampla** — causa raiz confirmada empiricamente (abaixo); correção em 2 arquivos, 1 módulo (testes).
- [ ] **Decisão de produto/arquitetura** — nenhuma; o código de produção está correto e não muda.

Nenhuma marcada → segue no BFF.

## 1. Problema

- **Sintoma:** No `dev`, `php artisan test` tem 15 testes vermelhos, todos em
  `tests/Feature/ExpenseControllerStoreTest.php` (7) e
  `tests/Feature/ExpenseControllerShowUpdateDestroyTest.php` (8). Todos recebem
  HTTP **422** onde esperam **201/200**. 244 testes restantes passam.

- **Reprodução:**
  1. `docker compose up -d` (MySQL) na raiz do repo.
  2. `cd backend && php artisan test --filter=ExpenseControllerStoreTest`.
  3. `7 failed, 11 passed` — ex.: *"member can create expense in own group"*,
     *"member can create fixed expense"*, *"new expense quota starts as pending
     even if client sends paid true"*.
  4. O mesmo suíte passava até **2026-08-31** e começou a falhar em
     **2026-09-01** sem nenhuma mudança de código.

- **Esperado vs. atual:**
  - *Esperado:* os testes de "caminho feliz" de criar/editar/excluir despesa
    passam (201/200), de forma determinística, rodem eles em que dia rodarem.
  - *Atual:* retornam 422 `{"error":"Não é possível alterar dados de uma
    competência já fechada."}` sempre que o relógio real está em um mês
    posterior ao da data fixa usada nas fixtures.

- **Causa raiz:** os 15 testes são *time-bombs* — dependem do relógio do
  sistema cair na mesma competência das datas fixas das fixtures.
  - As fixtures usam `date_payment => '2026-08-15'`
    (`ExpenseControllerStoreTest::payloadFor()` e
    `ExpenseControllerShowUpdateDestroyTest::createExpense()`), mas **não**
    fixam o relógio com `Carbon::setTestNow()`.
  - `ExpenseController::store()`
    ([app/Http/Controllers/ExpenseController.php:344](../../backend/app/Http/Controllers/ExpenseController.php#L344))
    e `update()`/`destroy()` (via `rejectIfCycleClosed` →
    `rejectIfCompetenceClosed`,
    [linhas 134/250/292](../../backend/app/Http/Controllers/ExpenseController.php#L292))
    recusam com 422 quando a competência da data está fechada.
  - `BillingCycle::statusFor(null, 2026-08-15, now)`
    ([app/Support/BillingCycle.php:39](../../backend/app/Support/BillingCycle.php#L39)):
    grupo de teste tem `closing_day = null` → ciclo = mês calendário
    `2026-08-01 .. 2026-08-31`. `statusOf` devolve `closed` quando
    `end (2026-08-31) < now`. Verificado no tinker: `now = 2026-09-01` →
    `closed`; `now = 2026-08-20` → `open`.
  - Logo, a partir de 2026-09-01 o `store`/`update`/`destroy` desses testes
    passa a bater na trava de competência fechada → 422.
  - São exatamente os 15 que (a) esperam sucesso, (b) passam pela checagem de
    competência (todos os tipos no `store`; não-`FIXED` no `update`/`destroy`),
    e (c) usam data fixa de agosto/2026. Os testes que **fixam** o relógio
    (`test_rejects_expense_with_date_payment_in_an_automatically_closed_cycle`
    etc. — `Carbon::setTestNow('2026-08-19')`) continuam verdes.
  - O código de produção está **correto**: criar/editar despesa em competência
    fechada deve mesmo dar 422. O defeito está só na não-determinística das
    fixtures de teste.

## 2. Correção

- **O que muda e por quê:** fixar o relógio nos dois arquivos de teste, no
  mesmo padrão que o resto do suíte já usa (`Carbon::setTestNow('2026-08-19')`
  nos testes de competência fechada). `setUp()` chama
  `Carbon::setTestNow('2026-08-15')` (mesma data das fixtures → competência de
  agosto/2026 sempre `open`); `tearDown()` reseta com `Carbon::setTestNow()`.
  `ExpenseControllerStoreTest` já tem o `tearDown` de reset — só falta o
  `setUp`. Testes que definem o próprio `setTestNow` continuam mandando (o
  valor por-teste sobrepõe o de `setUp`).
- **Arquivos tocados:**
  - `backend/tests/Feature/ExpenseControllerStoreTest.php`
  - `backend/tests/Feature/ExpenseControllerShowUpdateDestroyTest.php`
- **Teste de regressão:** os próprios 15 testes — passam a verde com a
  correção e ficam determinísticos (não voltam a quebrar na próxima virada de
  mês). Nenhum teste novo: o bug é 100% de setup de teste.
- **Riscos / efeitos colaterais:** nenhum no código de produção (não é
  tocado). Nos testes: alguns casos que hoje passam "pelo motivo errado"
  (recebem 422 de competência fechada em vez do 422 de validação que
  pretendem checar) passarão a exercitar o caminho certo — continuam verdes,
  agora validando o que dizem validar. Adicionado `tearDown` de reset de
  `Carbon` no `ExpenseControllerShowUpdateDestroyTest` para não vazar o
  relógio-fake para outros arquivos.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-09-01 | `php artisan test --filter=ExpenseControllerStoreTest` (antes da correção) | 7 failed, 11 passed — 422 onde esperava 201 |
| 2026-09-01 | `php artisan tinker` — `BillingCycle::statusFor(null, '2026-08-15', now())` | `closed` (now=2026-09-01); `open` para now=2026-08-20 |
| 2026-09-01 | `php artisan test --filter='ExpenseControllerStoreTest\|ExpenseControllerShowUpdateDestroyTest'` (após correção) | 43 passed (93 assertions) — 15 que falhavam agora verdes |
| 2026-09-01 | `./vendor/bin/pint --test tests/Feature/ExpenseControllerStoreTest.php tests/Feature/ExpenseControllerShowUpdateDestroyTest.php` | PASS, 2 files |
| 2026-09-01 | `php artisan test` (suíte completa) | 259 passed (781 assertions) — sem regressão |
