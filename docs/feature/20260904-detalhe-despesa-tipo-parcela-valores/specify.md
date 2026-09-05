# Specify — Detalhe da despesa: tipo, parcela e valores por pagador

> Feature: o modal "Detalhes da despesa" passa a mostrar o tipo real da despesa (À Vista / Parcelada `n/N` / Fixa) e o valor individual de cada pagador, além de corrigir em produção as datas das parcelas de duas despesas do grupo 3878. Pedido novo do usuário nesta conversa, sem task prévia em `docs/sdd/03-tasks.md`; planejado em modo `/plan` — plano aprovado em `C:\Users\Isac Aguiar\.claude\plans\vamos-atuar-nas-seguintes-dazzling-pretzel.md`.

Versão: 1.0 · Criado em: 20260904

---

## 1. Problema

Três pedidos do usuário, dois de código e um de dado:

1. No detalhe da despesa deve vir o **tipo** — e, se for parcelada, **qual parcela** está sendo paga naquele mês.
2. Na mesma tela, deve aparecer o **valor total** e, nos **pagadores**, o valor de cada um.
3. As despesas *Adestrador* e *Construção parede escritório* (produção, grupo 3878) tiveram a **1ª parcela paga em maio**, mas estão cadastradas começando em junho.

Os itens 1 e 2 são desenvolvimento novo de UI (`docs/feature/`, não BFF). O item 3 é correção de **dado** em produção — nenhum código —, continuação direta de `docs/feature/20260904-parcela-retroativa-contabilizacao/` (mesmas despesas 8658/8659, mesmo grupo, mesmo padrão de script SQL executado pelo usuário).

## 2. Achados confirmados

### 2.1 O modal de detalhe achata 3 tipos de despesa em 2 rótulos

`frontend/src/pages/ExpenseManager.tsx` renderiza no modal "Detalhes da despesa" um `Chip` com `detailExpense.isFixed ? 'Fixa' : 'Variável'`. Uma despesa `IN_INSTALLMENTS` (Parcelada) aparece, portanto, como **"Variável"** — indistinguível de uma `IN_CASH` (À Vista). Os rótulos corretos "À Vista"/"Parcelada"/"Fixa" existem no projeto, mas só nos `MenuItem` dos formulários (`ExpenseForm.tsx`, `ExpenseView.tsx`).

O mesmo achatamento aparece em `ExpenseView.tsx`, onde `typeLabel` mapeia `IN_CASH` e `IN_INSTALLMENTS` para `'Variável'` — fora do escopo desta feature (§3).

### 2.2 O `summary` não expõe tipo nem número da parcela, embora o dado exista

`GET /groups/{groupId}/expenses/summary` → `ExpenseController::computeCycleSummary()` monta cada item de `expenses` com `id, description, date, value, valuePerPerson, paid, bornPaid, paymentProofUrl, payerName, payerAvatarUrl, participants, participantDetails, isFixed, userPayerId, userCreatorId`. Não há `expense_type` textual, nem `installments`, nem o `number` da parcela, nem o `total_value` da despesa.

Os dados existem: `ex_expenses.expense_type` (ENUM `IN_CASH|IN_INSTALLMENTS|FIXED`), `ex_expenses.installments` (total) e `ex_quotas.number` (nº da parcela). Em `collectCycleEntries()` a `Quota` do ciclo **já está em mãos** nos três laços (direct, installments, fixed) — ela só não é propagada para a entry, que carrega apenas `expense/date/value/paid/bornPaid/paymentProofUrl`. Expor `n/N` é, portanto, campo aditivo sem query nova.

### 2.3 A seção "Pagadores" do modal é uma lista de nomes, sem valor

No mesmo modal, "Pagadores" renderiza `detailExpense.participants.join(', ')` — só nomes concatenados, sem valor e sem avatar. Enquanto isso:

- `valuePerPerson` **já vem** no payload (`computeCycleSummary()`: `round($entry['value'] / max($payers->count(), 1), 2)`) e é exatamente o valor de cada pagador — o rateio é sempre **igualitário**, não há coluna de percentual nem de valor na pivot `ex_expenses_payers`;
- `participantDetails` (com `id`, `name`, `avatarUrl`) já vem no payload e está tipado em `frontend/src/hooks/useGroupCycle.ts`, mas **nenhum componente o consome**.

Ou seja, o valor por pagador não exige campo novo no backend — exige renderizar o que já chega.

### 2.4 Ciclo selado é servido de snapshot congelado

`summary()` devolve o JSON gravado em `ex_group_cycle_snapshots.expenses` quando o ciclo está selado/fechado manualmente. Campos novos em `computeCycleSummary()` **não aparecem** nesses ciclos. Precedente na feature `20260904-avatar-usuario-listagens`: os campos aditivos foram declarados **opcionais** no tipo do frontend, com fallback na UI.

### 2.5 Dados de produção — grupo 3878, despesas 8658 e 8659

Estado atual, conforme `docs/feature/20260904-parcela-retroativa-contabilizacao/implementation.md` (TASK-002, já executada):

| Despesa | id | Parcelas | Valor | Estado hoje |
|---|---|---|---|---|
| Adestrador | 8658 | 6× R$ 292,40 | R$ 1.754,40 | #1 jun e #2 jul `paid=1`/`born_paid=1`; #3 ago … #6 nov pendentes |
| Construção parede escritório | 8659 | 5× R$ 543,00 | R$ 2.715,00 | #1 jun e #2 jul `paid=1`/`born_paid=1`; #3 ago … #5 out pendentes |

Credor de ambas: `naumel67` (`user_payer_id = 5573`), 6 participantes.

O usuário confirmou que a 1ª parcela foi paga em **maio**, e descreveu a correção como "antecipar um mês, tirar o último pagamento e adicionar um mês antes do primeiro".

## 3. Requisitos

### 3.1 Tipo real e número da parcela no modal de detalhe

O `Chip` de tipo do modal passa a mostrar:

| `expense_type` | Rótulo |
|---|---|
| `FIXED` | `Fixa` |
| `IN_CASH` | `À Vista` |
| `IN_INSTALLMENTS` | `Parcelada 3/6` (nº da parcela **daquele ciclo** / total) |

Quando o payload não trouxer o tipo (ciclo selado antes desta mudança — §2.4), o rótulo cai no comportamento atual (`Fixa`/`Variável`). O chip de status `Paga`/`Pendente` não muda.

### 3.2 Valor total da despesa parcelada visível

Para `IN_INSTALLMENTS`, além do valor em destaque (que é o valor **daquele mês** — `value`), o modal mostra uma linha secundária com o total da despesa e a quantidade de parcelas (ex.: `Total da despesa: R$ 1.754,40 em 6x`). Para os demais tipos, nada muda.

### 3.3 Credor e pagadores com valor individual

- **Credor**: continua com avatar + nome, agora acompanhado do valor daquele mês (`value`) — o que ele desembolsou.
- **Pagadores**: deixa de ser texto concatenado e vira uma lista, uma linha por pessoa, com avatar + nome + `valuePerPerson`. Quem é o credor (`id === userPayerId`) é identificado como tal; os demais são os **devedores** daquela despesa.
- Fallback: quando `participantDetails` não vier (ciclo selado), usa `participants` (só nomes) sem quebrar.

### 3.4 Contrato de API aditivo

`computeCycleSummary()` ganha 4 chaves novas por despesa — `expenseType`, `installmentNumber`, `installmentsTotal`, `totalValue` — sem renomear nem remover nenhuma existente (Constitution §4.1). `isFixed` permanece, porque `ExpenseManager` (filtro/ícone), `Payments` e os testes atuais dependem dele.

### 3.5 Antecipação de um mês nas parcelas de 8658/8659 (produção)

Alvo:

| | 8658 Adestrador (6×) | 8659 Construção (5×) |
|---|---|---|
| Pagas | #1 mai, #2 jun, #3 jul | #1 mai, #2 jun, #3 jul |
| Pendentes | #4 ago, #5 set, #6 out | #4 ago, #5 set |

Deslocar `date_expected` de todas as parcelas em **−1 mês** preserva a numeração 1..N, a quantidade de parcelas e o total — e é equivalente a "tirar o último mês e acrescentar um mês antes do primeiro". Como consequência, o mês que hoje é agosto (pendente) passa a ser julho e precisa ser marcado `paid = 1` / `born_paid = 1`.

**Agosto continua sendo dívida real** dos devedores — mantém a decisão registrada em `20260904-parcela-retroativa-contabilizacao/specify.md` §2.5.

Entregável: **script SQL revisado, executado pelo usuário** (gate de produção — Constitution §5.2). Sequência detalhada em `plan.md` §3.

## 4. Fora de escopo desta feature

- **`ExpenseView.tsx`** (página `/groups/:id/expenses/:expenseId`): tem o mesmo rótulo errado (`IN_INSTALLMENTS` → "Variável") e não lista pagadores. O usuário escolheu tratar só o modal — vira item em `docs/backlog/`.
- **Campo categoria** de despesa (`docs/backlog/expense-campo-categoria.md`, ID 024) — "tipo" aqui é `expense_type`, não categoria.
- `Payments.tsx`, `CycleDetailPanel.tsx`, `indexByGroup()` e os relatórios — continuam como estão.
- **Backfill dos snapshots já selados** para conter os campos novos — a UI degrada com fallback (§3.1, §3.3).
- **App Expo** (`app/`).
- Rateio não-igualitário (percentual/valor por pagador) — não existe no modelo e não entra aqui.
