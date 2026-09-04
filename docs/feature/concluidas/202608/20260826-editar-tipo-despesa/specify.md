# Specify — Editar Tipo de Despesa (com trava para parcelada já iniciada)

> Feature: permite trocar o tipo de uma despesa (À Vista ↔ Parcelada) na tela de edição (`ExpenseView.tsx`), desde que ainda não paga; para despesas parceladas especificamente, bloqueia a edição inteira assim que a primeira parcela já foi paga. Origem: pedido novo do usuário nesta conversa, sem task/épico prévio em `03-tasks.md`. Planejado em modo `/plan` — plano aprovado em `C:\Users\Isac Aguiar\.claude\plans\recursive-puzzling-wirth.md`.

Versão: 1.0 · Criado em: 20260826

---

## 1. Problema

O usuário quer poder corrigir o tipo de uma despesa já cadastrada (ex.: cadastrou como "À Vista" mas era "Parcelada") direto na tela de edição — hoje isso não existe. Ele também quer uma trava mais forte para despesas parceladas: uma vez que a 1ª parcela foi paga, a despesa não deve mais poder ser editada de forma alguma ("só permite alteração no mês de cadastro").

Perguntei sobre o escopo exato (quais transições permitir, se o bloqueio é só do campo Tipo ou do formulário inteiro); as respostas do usuário ("não entendi a dúvida" / "isso deve ser apenas para as compras parceladas") não fecharam todos os detalhes. As decisões abaixo assumem o default mais conservador em cada ponto em aberto — revisáveis.

## 2. Achados confirmados

### 2.1 `ExpenseController::update()` hoje ignora `expense_type`/`installments`/`quotas` — silenciosamente

`backend/app/Http/Controllers/ExpenseController.php:114-149`: valida só `description`/`date_payment`/`total_value`/`user_payer_id`/`payers`. Um teste existente trava exatamente esse comportamento atual: `ExpenseControllerShowUpdateDestroyTest::test_update_ignores_expense_type_installments_and_quotas` (`backend/tests/Feature/ExpenseControllerShowUpdateDestroyTest.php:140-160`) — manda `expense_type: 'FIXED', installments: 5, quotas: [...]` e confirma que nada muda (200, mas ignorado). Esse teste precisa ser substituído (ver §3).

### 2.2 Bloqueio de edição hoje só cobre `total_value`, e só quando há **qualquer** quota paga

Linhas 136-140: `if ($expense->expense_type !== 'FIXED' && array_key_exists('total_value', $data) && $expense->quotas()->where('paid', true)->exists())` → 422. Outros campos (descrição, data, credor, participantes) continuam editáveis mesmo com quota paga — confirmado por `test_update_allows_non_value_changes_when_expense_is_paid` (despesa À Vista) e `test_update_rejects_total_value_change_for_installments_when_any_quota_is_paid` (parcelada, só `total_value` testado). Nenhum teste hoje bloqueia a edição **inteira**.

### 2.3 `destroy()` já tem o precedente de bloquear a ação inteira quando há quota paga

`ExpenseController::destroy()` (151-171): se `$expense->quotas()->where('paid', true)->exists()`, rejeita a exclusão inteira (422), diferente de `update()` que hoje só bloqueia um campo. É o modelo que a trava nova de parcelada replica para `update()`.

### 2.4 `store()` já valida `installments`/`quotas` do mesmo jeito que a feature precisa para `update()`

`ExpenseController::store()` (219-276): `expense_type in:IN_CASH,IN_INSTALLMENTS,FIXED`; para `FIXED` exige `installments===1` e 1 quota; para `IN_INSTALLMENTS` exige `count(quotas) === installments` e soma das quotas igual a `total_value` (tolerância 0,01). Quotas sempre nascem `paid => false`, mesmo se o client mandar `paid: true`.

### 2.5 O rateio de parcelas já existe, mas vive no frontend, não no backend

`frontend/src/pages/ExpenseForm.tsx`: `buildInstallmentQuotas(totalValue, installmentsCount, startDate)` + `addMonthsClamped` — o client monta o array de `quotas` e manda pronto pro backend, que só valida (não calcula). `update()` deve seguir o mesmo padrão — o rateio novo entra em `ExpenseView.tsx`, reaproveitando essa mesma lógica extraída para um módulo compartilhado (não duplicada, não portada para PHP).

### 2.6 `ExpenseView.tsx` (edição) hoje não tem nenhum campo de tipo

`frontend/src/pages/ExpenseView.tsx`: estado de edição é só `description, value, date, payerId, participantIds` (linhas 80-87 na versão antes desta feature); `startEditing()` nunca lê `expense.expense_type`. `handleSave()` manda exatamente os 5 campos que `update()` aceita hoje — nenhuma mudança de contrato até agora.

### 2.7 `GET /api/expenses/{id}` já devolve `installments` e `quotas[].number` — só não estava tipado no frontend

`ExpenseController::show()` devolve o model `Expense` cru com `payers`/`quotas` (`$expense->load(['payers', 'quotas'])`) — `installments` já é uma coluna do model, e `Quota::number` já existe; o tipo `ExpenseDetail`/`ExpenseQuota` do frontend (`ExpenseView.tsx`) é que não declarava esses campos ainda (`ExpenseQuota` ganhou `payment_proof_url`/`paid` na feature anterior, `docs/feature/concluidas/202608/20260825-pagamentos-grid-pix/`, mas não `number`).

### 2.8 Nenhuma regra "mês de cadastro" existe hoje no código ou nos docs

Busca por "mês de cadastro"/"registration month" em `docs/`, `backend/`, `frontend/` não encontrou nada — a frase é nova desta conversa. O campo mais próximo é `Expense::create_date` (timestamp real de criação, `$fillable`, cast `datetime`) — não usado nesta feature (a trava usa "1ª parcela paga" como gatilho observável, não uma janela de tempo — ver Decisão 2 em `plan.md`).

## 3. Requisitos

- **R1**: `update()` aceita `expense_type` (`IN_CASH`/`IN_INSTALLMENTS`, nunca `FIXED` como alvo), `installments`, `quotas` — todos `sometimes`, adicionando ao contrato existente sem quebrá-lo (nenhum client atual manda esses campos).
- **R2**: Se a despesa já é `FIXED` e o payload traz `expense_type` → 422. Transições de/para `FIXED` não são suportadas nesta feature.
- **R3**: Se a despesa é `IN_INSTALLMENTS` e existe qualquer quota paga → 422 pra **qualquer** edição (não só tipo/valor) — bloqueio total, antes de olhar outros campos do payload.
- **R4**: Fora do caso do R3, o bloqueio já existente (§2.2) passa a cobrir também `expense_type` além de `total_value`.
- **R5**: Ao mudar `expense_type` de fato: `IN_CASH → IN_INSTALLMENTS` exige `installments`/`quotas` coerentes (mesma checagem de `store()`, achado 2.4) e recria as quotas (delete físico + insert, seguro porque R3/R4 já garantiram nenhuma paga); `IN_INSTALLMENTS → IN_CASH` colapsa pra 1 quota com o `total_value` final.
- **R6**: `ExpenseView.tsx` ganha um seletor de Tipo (À Vista/Parcelada) visível só quando `expense.expense_type !== 'FIXED'`, e um campo de quantidade de parcelas condicional — mesmo padrão visual de `ExpenseForm.tsx`. O rateio de quotas usa a mesma função `buildInstallmentQuotas`, extraída para um módulo compartilhado (achado 2.5).
- **R7**: O botão "Editar" fica desabilitado (com explicação) quando a despesa é `IN_INSTALLMENTS` com alguma quota paga — reflete R3 no cliente antes mesmo de tentar salvar.

## 4. Fora de escopo desta feature

- Converter de/para `FIXED` em qualquer direção (R2) — decisão registrada em `plan.md`, revisável se o usuário pedir depois.
- Qualquer mudança em `ExpenseForm.tsx` além de extrair o helper de rateio pra um módulo compartilhado — a tela de criação continua idêntica.
- Corrigir a lacuna pré-existente (não introduzida por esta feature) de `total_value` não recalcular `quotas` numa despesa parcelada quando só o valor muda, sem trocar tipo — vira item de backlog.
- Qualquer ajuste em `GroupExpenseReportController` ou em `GroupCycleSnapshot` — ambos continuam lendo `expense_type`/`quotas` como leitores downstream, sem mudança de contrato da parte deles; um snapshot já fechado permanece imutável mesmo que o tipo de uma despesa mude depois (mesmo comportamento de qualquer outra edição pós-fechamento, já bloqueada por `rejectIfCycleClosed`).
