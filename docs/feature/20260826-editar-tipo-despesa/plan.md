# Plan — Editar Tipo de Despesa (com trava para parcelada já iniciada)

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui. Mesmo conteúdo do plano aprovado em `/plan` (`C:\Users\Isac Aguiar\.claude\plans\recursive-puzzling-wirth.md`), reorganizado no formato padrão do SDD.

Versão: 1.0 · Criado em: 20260826

---

## 1. `ExpenseController::update()` — validação e bloqueios (specify §R1-R4)

- Adiciona à validação: `expense_type => sometimes|required|in:IN_CASH,IN_INSTALLMENTS` (rejeita `'FIXED'` como alvo automaticamente pela regra `in:`), `installments => sometimes|required|integer|min:2`, `quotas => sometimes|required|array|min:1` + `quotas.*.date_expected`/`number`/`value_quota`.
- Se `$expense->expense_type === 'FIXED'` e o payload trouxer `expense_type` → 422 explícito ("Não é possível mudar o tipo de uma despesa fixa.") — a validação `in:` sozinha não cobre esse caso (ela só rejeita o valor alvo, não a origem).
- Se `$expense->expense_type === 'IN_INSTALLMENTS'` e existe quota paga → 422 ("Não é possível editar uma despesa parcelada depois que a primeira parcela foi paga.") — bloqueia a chamada inteira, checado antes de qualquer outro campo do payload. Reaproveita a mesma query `$expense->quotas()->where('paid', true)->exists()` já usada no bloqueio de `total_value` (specify §2.2) — não precisa filtrar por `number === 1` porque, na prática, a quota 1 é sempre a primeira paga.
- Fora do caso acima (não-FIXED, sem cair no bloqueio de `IN_INSTALLMENTS`+paga): se há quota paga e o payload mexe em `total_value` OU `expense_type` → 422 (mensagem do bloqueio existente ampliada pra cobrir os dois campos).

## 2. Regeneração de quotas (specify §R5)

- Quando `expense_type` está no payload: calcula o tipo final (`$data['expense_type']`) e o `total_value` final (`$data['total_value'] ?? $expense->total_value`).
  - `IN_INSTALLMENTS`: exige `installments` (≥2) e `quotas` no payload; valida `count(quotas) === installments` e soma das quotas ≈ `total_value` (tolerância 0,01) — mesma checagem de `store()` (specify §2.4).
  - `IN_CASH`: ignora `quotas`/`installments` do payload (se vierem) e monta uma quota só, server-side: `[{number: 1, date_expected: $data['date_payment'] ?? $expense->date_payment, value_quota: $totalValueFinal}]` — trivial demais pra exigir do client.
- Depois de `$expense->update(...)`: se a estrutura mudou, `$expense->quotas()->delete()` (delete físico — seguro, só alcançado depois de confirmar 0 quotas pagas; `ex_quotas` não tem coluna de soft delete) + recria as quotas calculadas acima, sempre `paid => false` (mesma garantia de `store()`).
- Resposta continua `$expense->fresh(['payers', 'quotas'])` — contrato aditivo.

## 3. Frontend — helper de rateio compartilhado (specify §R6)

- Novo `frontend/src/utils/installments.ts`: `pad`, `addMonthsClamped`, `buildInstallmentQuotas` movidos de `ExpenseForm.tsx` (código idêntico, só de lugar — nenhuma mudança de comportamento). `ExpenseForm.tsx` passa a importar de lá.

## 4. Frontend — `ExpenseView.tsx` (specify §R6, §R7)

- `ExpenseDetail` ganha `installments: number`; `ExpenseQuota` ganha `number: number` (specify §2.7, já vem do backend).
- Novo estado no modo edição: `expenseType`, `installmentsCount` — `startEditing()` pré-preenche a partir de `expense.expense_type`/`expense.installments`.
- Campo Tipo (`TextField select`, opções À Vista/Parcelada — sem Fixa) só aparece quando `expense.expense_type !== 'FIXED'`. Campo de quantidade de parcelas aparece condicionalmente quando `expenseType === 'IN_INSTALLMENTS'`; rótulo de data dinâmico (`dateFieldLabel`) espelhando `ExpenseForm.tsx`.
- Botão "Editar" desabilitado (com `Tooltip` explicando o motivo) quando `expense.expense_type === 'IN_INSTALLMENTS'` e `expense.quotas.some(q => q.paid)` — mesmo dado que a feature anterior (`docs/feature/20260825-pagamentos-grid-pix/`) já passou a carregar.
- `handleSave()`: quando o tipo é editável (despesa não-FIXED), monta `quotas` via `buildInstallmentQuotas` (Parcelada) ou uma quota só (À Vista) e inclui `expense_type`/`installments`/`quotas` no payload do PUT. Despesas `FIXED` continuam mandando o payload de hoje (sem esses campos) — não aciona a validação nova no backend, preserva 100% do comportamento atual pra elas.

## N. Ordem de execução

Sem dependência circular entre backend e frontend na validação em si, mas o frontend só pode ser testado de ponta a ponta depois do backend aceitar os campos novos. Ordem: (1) `update()` — validação + bloqueios (item 1) → (2) regeneração de quotas (item 2, mesmo método) → (3) testes PHPUnit (backend fechado e verificável sozinho) → (4) helper compartilhado no frontend (item 3) → (5) `ExpenseView.tsx` (item 4) → (6) testes frontend.
