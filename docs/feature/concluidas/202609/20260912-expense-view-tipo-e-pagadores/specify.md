# Specify — Página da despesa: tipo, cronograma de parcelas e pagadores

> Feature: a página `/groups/:id/expenses/:expenseId` (`ExpenseView`) passa a mostrar o tipo real da despesa, o cronograma completo das parcelas e os pagadores com valor individual; junto, os tipos hoje redigitados página a página no frontend web viram um módulo compartilhado. Promoção dos itens de backlog **039** (`expense-view-tipo-e-pagadores.md`) e **003** (`tipos-duplicados-frontend.md`) via `/promover-backlog 039` — agrupamento decidido pelo usuário em 2026-09-12.

Versão: 1.1 · Criado em: 20260912 · Emendado em: 20260912 (§2.9 e §3.6, por decisão do gate do `plan.md` §6)

---

## 1. Problema

**1. Duas telas do mesmo produto discordam sobre a mesma despesa (item 039).** A feature `docs/feature/concluidas/202609/20260904-detalhe-despesa-tipo-parcela-valores/` consertou o modal "Detalhes da despesa" do `ExpenseManager` — que passou a dizer `Parcelada 3/6` e a listar cada pagador com o valor dele (TASK-001 + TASK-002, já em `dev` via [PR #153](https://github.com/isacaguiar/expense/pull/153)). A **página** de detalhe da mesma despesa continua dizendo `Variável` e não mostra pagador nenhum no modo de visualização. O usuário optou, na época, por tratar só o modal (§4 do `specify.md` daquela feature), registrando o resto como item de backlog.

O custo não é estético: a página de detalhe é onde se confere "o que exatamente é essa despesa". Se ela contradiz o modal, ela corrói justamente a confiança no número que a feature anterior quis consertar.

**2. Não existe módulo de tipos compartilhado no frontend web (item 003).** Cada página redigita o tipo do recurso que consome. Sem isso, esta feature nasceria criando a **terceira** cópia de `ExpenseType` e a terceira de `GroupMember` — o débito cresce exatamente no arquivo que estamos abrindo.

## 2. Achados confirmados

### 2.1 `typeLabel` achata À Vista e Parcelada no mesmo rótulo

`frontend/src/pages/ExpenseView.tsx:79-83` mapeia `IN_CASH` **e** `IN_INSTALLMENTS` para `'Variável'`; só `FIXED` tem rótulo próprio. O `Chip` do modo de visualização (`:385`) mostra esse rótulo. Logo, uma despesa parcelada é indistinguível de uma à vista, e o número da parcela não aparece em lugar nenhum da tela.

### 2.2 O modo de visualização não mostra pagadores nem valor por pessoa

O bloco de visualização (`:375-433`) renderiza descrição, chip de tipo, `total_value`, `date_payment`, **credor** (`:396`, com avatar) e link de comprovante. `payers` só é consumido no **modo de edição**, como checkboxes (`setParticipantIds(expense.payers.map(p => p.id))`, `:160`). Não há lista de pagadores nem valor individual em nenhum dos dois modos.

### 2.3 A página não tem noção de competência — e, pela decisão tomada, não precisa ter

A rota `/groups/:id/expenses/:expenseId` não recebe `cycles_ago`. O `ExpenseManager` tem `cyclesAgo` em mãos (`useGroupCycle`, `ExpenseManager.tsx:100`) mas não o propaga em nenhum dos três links que levam a esta página (`:392`, `:442`, `:643`). Portanto "qual parcela está sendo paga neste mês" não é derivável aqui sem inventar uma fonte.

**Decisão do usuário (2026-09-12):** em vez de escolher uma parcela, a página mostra o **cronograma completo**. Isso resolve o problema do item sem acoplar a página à navegação (nenhum query param novo, link direto e refresh continuam válidos) e usa o espaço que o modal não tem.

### 2.4 O dado já chega — esta feature não toca o backend

`ExpenseController::show()` (`backend/app/Http/Controllers/ExpenseController.php:116-123`) faz `load(['payers', 'quotas'])` e devolve o model inteiro. Já vêm, portanto:

| Dado | Origem |
|---|---|
| `expense_type`, `installments`, `total_value` | `Expense` (`backend/app/Models/Expense.php:14-34`) |
| `payers[]` com `id`, `name`, `avatar_url` | `belongsToMany(User)` (`Expense.php:60-63`); `avatar_url` tem accessor (`User.php:66`) |
| por quota: `number`, `date_expected`, `paid`, `value_quota`, `payment_proof_url` | `Quota` (`backend/app/Models/Quota.php:16-36`) |

O tipo local `ExpenseQuota` (`ExpenseView.tsx:33-38`) declara só 4 desses campos — falta `value_quota`, que é justamente o valor de cada parcela.

### 2.5 O rateio é sempre igualitário, e a última parcela absorve o arredondamento

Não há coluna de valor nem de percentual na pivot `ex_expenses_payers` (`Expense.php:60-63`) — o rateio é sempre igual entre os pagadores, como já assumia `computeCycleSummary()` (`valuePerPerson = value / payers->count()`). Valor por pessoa é, portanto, derivável no cliente: **sem campo novo no backend**.

Mas as parcelas **não são todas iguais**: `buildInstallmentQuotas()` (`frontend/src/utils/installments.ts`) distribui centavos iguais e joga o resto na **última** parcela. Então "valor por pessoa" tem de ser calculado por parcela (`value_quota / n`), não uma vez para a despesa inteira.

### 2.6 Despesa `FIXED` não tem cronograma para mostrar

`FIXED` **nasce sem Quota** na competência — a Quota é materializada sob demanda quando alguém marca como paga (`ExpenseController.php:877`, e o comentário de `unpay` em `:954`). O `quotas[]` de uma despesa fixa é, portanto, esparso: contém os meses em que houve pagamento, não um cronograma. Listar isso como "parcelas" seria mentira de tela.

### 2.7 Há implementação de referência no modal, mas sobre outra forma de dado

`detailTypeLabel()` (`ExpenseManager.tsx:83-94`) e `renderDetailPayers()` (`:322-356`) já resolvem rótulo e lista de pagadores com avatar, valor e marcação `(credor)` — porém sobre `SummaryExpense` (`hooks/useGroupCycle.ts:32`): camelCase, vindo do `summary`, com **uma** quota já escolhida pelo backend (`expenseType`, `valuePerPerson`, `installmentNumber`, `participantDetails`). Esta página recebe `ExpenseDetail`: snake_case, vindo do `show`, com `quotas[]` inteiro e sem `valuePerPerson`.

Consequência: reaproveitar o **componente** exigiria normalizar as duas formas (trabalho maior que a feature); reaproveitar o **vocabulário** (os rótulos, a ordem dos campos, o `(credor)`) é direto e é o que garante que as duas telas concordem.

### 2.8 Tipos duplicados: o estado real hoje (item 003)

O item 003 foi escrito em 2026-08-17 e citava `Dashboard.tsx`, `GroupList.tsx` e `GroupForm.tsx`. `GroupList.tsx` **não existe mais** (consolidado no Dashboard pela TASK-092, item de backlog 027). Não existe `frontend/src/types/`. O que há hoje, verificado:

- **`type Group` em 8 arquivos não-teste, em 4 formas distintas** — não são 8 cópias de um tipo, são 4 projeções diferentes do mesmo recurso:

  | Forma | Campos além de `id`/`name`/`description` | Arquivos |
  |---|---|---|
  | A | `create_date`, `expenses_max_date_payment` | `ExpensesEntry.tsx:9`, `MembersEntry.tsx:8`, `PaymentsEntry.tsx:8`, `ReportsEntry.tsx:8`, `SummaryEntry.tsx:8` — **5 idênticas** |
  | B | `create_date`, `created_by`, `creator?`, `members[]`, `cycle_snapshots_exists` | `Dashboard.tsx:48` |
  | C | `create_date`, `closing_day` | `GroupForm.tsx:16` |
  | D | `creator?` | `GroupMembersForm.tsx:24` |

- **`type ExpenseType`** (`'IN_CASH' | 'IN_INSTALLMENTS' | 'FIXED'`) — 3 declarações idênticas: `ExpenseForm.tsx:23`, `ExpenseView.tsx:31` e `SummaryExpenseType` (`useGroupCycle.ts:30`, mesmo union com outro nome, usado num único lugar: `:54`).
- **`type GroupMember`** (`{ id; name; avatar_url }`) — idêntico em `ExpenseForm.tsx:25` e `ExpenseView.tsx:29`.
- **`type GroupOption`** (`{ id; name }`) — idêntico em `layouts/GroupShellLayout.tsx:13` e `layouts/group/GroupHeader.tsx:16`.
- `type Member` (`{ id; name; email }`, `Dashboard.tsx:46`) e `type User` (`{ id; email }`, `GroupMembersForm.tsx:23`) — projeções distintas de usuário, não cópias.

Ou seja: o que é duplicação de verdade (mesma forma, mesmo nome) são a **forma A do `Group` (5×)**, `ExpenseType` (3×), `GroupMember` (2×) e `GroupOption` (2×). As formas B/C/D do `Group` são projeções legítimas de endpoints diferentes — unificá-las num tipo só com tudo opcional apagaria a informação de qual endpoint devolve o quê.

### 2.9 A mesma tela já exibe `date_payment` com um dia a menos (achado do planejamento)

`ExpenseView.tsx:393` faz `new Date(expense.date_payment).toLocaleDateString('pt-BR')`. Como `show()` devolve o model cru sem formatar data (todo ponto do controller que entrega data ao frontend chama `->toDateString()` explicitamente — `ExpenseController.php:46`, `:97`, `:1126`), essa string é interpretada como meia-noite UTC e, em fuso negativo (`America/Sao_Paulo`), a data exibida cai **um dia para trás**. É exatamente o bug que o item de backlog 013 documentou e a TASK-133 corrigiu nos outros 7 arquivos que formatam data (`ExpenseManager.tsx:61-63`, `GroupSummary.tsx:13-15`, `GroupReports.tsx:11-13`, `CycleDetailPanel.tsx:12-14`, `GroupGrossDebtsPanel.tsx:13-15`, `Payments.tsx:36-38`, `CycleClosingAlert.tsx:18`) — esta página ficou de fora.

Achado **pré-existente**, fora dos dois itens de backlog promovidos. Entra no escopo por decisão explícita do usuário no gate do `plan.md` (2026-09-12, opção (a) do §6): o cronograma de §3.2 introduz o helper de parse correto a 8 linhas dessa chamada, e deixar as duas leituras de data conviverem no mesmo card produziria uma inconsistência nova (`31/07/2026` no topo, `08/2026` na parcela) que seria atribuída a esta feature.

## 3. Requisitos

### 3.1 Chip de tipo correto, com o mesmo vocabulário do modal

| `expense_type` | Rótulo |
|---|---|
| `FIXED` | `Fixa` |
| `IN_CASH` | `À Vista` |
| `IN_INSTALLMENTS` | `Parcelada (6x)` — `installments` no lugar do 6 |

O chip **não** traz número de parcela (a página não tem competência — §2.3); quem responde "qual parcela" é o cronograma de §3.2. Os três rótulos são os mesmos de `detailTypeLabel()` (§2.7), para as duas telas não voltarem a divergir.

### 3.2 Cronograma completo das parcelas (só `IN_INSTALLMENTS`)

Uma seção "Parcelas", ordenada por `number`, uma linha por parcela com:

- `n/N` (ex.: `3/6`);
- mês da parcela (`date_expected` formatado `mmm/aaaa`);
- valor da parcela (`value_quota`) e, como texto secundário, quanto **cada pessoa** paga naquela parcela (`value_quota / payers.length`, 2 casas);
- estado `Paga` / `Pendente` (`paid`).

Acima da lista, o total: `Total da despesa: R$ 1.754,40 em 6x`. Para `IN_CASH` e `FIXED` a seção não aparece (§2.6) — nada muda nesses dois tipos além do rótulo de §3.1.

O valor por pessoa é calculado **por parcela**, não uma vez para a despesa, porque a última parcela absorve o arredondamento (§2.5). Limitação aceita e idêntica à do modal: `value_quota / n` arredondado a 2 casas pode somar alguns centavos a menos/mais que a parcela cheia — a linha mostra os dois números, então a diferença fica visível em vez de escondida.

### 3.3 Pagadores com valor individual no modo de visualização

Uma seção "Pagadores", uma linha por pessoa de `payers[]`, com avatar + nome + valor individual, e a marcação `(credor)` em quem tem `id === user_payer_id` — mesmo formato de `renderDetailPayers()` (§2.7).

O valor mostrado aqui é a cota da pessoa no **total** da despesa (`total_value / payers.length`): a página já destaca `total_value` no topo, e o valor por parcela já está em cada linha do cronograma (§3.2). Assim cada número aparece uma vez, com base explícita, sem repetir o mesmo dado com significados diferentes.

Não há fallback a escrever: diferente do modal, `payers[]` vem do `show` (nunca de snapshot congelado) e sempre traz `id`/`name`/`avatar_url`.

### 3.4 Módulo de tipos compartilhado para o que é duplicação de verdade

Criar `frontend/src/types/` e mover para lá o que tem forma única e nome igual (§2.8): `ExpenseType`, `GroupMember`, `GroupOption`, e a forma A do `Group` (consumida pelas 5 páginas de entrada). Cada arquivo que hoje declara uma dessas passa a importar.

As formas B/C/D do `Group` permanecem declaradas na página que as consome, como projeção explícita daquele endpoint (§2.8) — não viram campos opcionais de um tipo único. `SummaryExpenseType` passa a ser o `ExpenseType` compartilhado.

Critério: esta parte **não muda comportamento**. `npx tsc --noEmit` sem erro e a suíte de testes verde são a prova de que a troca foi só de origem do tipo.

### 3.5 Nenhuma mudança de backend e nenhuma mudança de contrato

Todos os dados de §3.1–§3.3 já vêm do `GET /api/expenses/{id}` (§2.4). Esta feature não altera controller, model, migration nem o payload — só adiciona `value_quota` ao tipo `ExpenseQuota` do frontend, que já recebia o campo e o ignorava.

### 3.6 `date_payment` no modo de visualização passa a usar o mesmo parse do cronograma

A data em destaque no card (`:393`) passa a ser formatada pelo mesmo helper de §3.2 — o que corrige o deslocamento de um dia descrito em §2.9. Mudança de uma linha, sem alteração de layout nem de texto: o formato exibido continua `dd/mm/aaaa`, só passa a mostrar o dia certo.

Critério: um teste com `date_payment` em ISO-8601 (`'2026-08-01T00:00:00.000000Z'`) exibe `01/08/2026`, não `31/07/2026`.

## 4. Fora de escopo desta feature

- **Cronograma para `FIXED`** — `quotas[]` de despesa fixa é esparso, não um cronograma (§2.6). Mostrar aquilo como "parcelas" seria pior que não mostrar.
- **Propagar `cycles_ago` na navegação** `ExpenseManager` → página de detalhe, e qualquer noção de competência nesta tela (§2.3, decisão do usuário).
- **Modo de edição da página** — continua exatamente como está (checkboxes de pagadores, trava de parcelada paga).
- **Unificar as formas B/C/D do `Group`** num tipo único com campos opcionais (§2.8, §3.4).
- **`formatMoney` duplicado em 9 arquivos** (`BalanceCards`, `CycleDetailPanel`, `GroupGrossDebtsPanel`, `PayableSettlementList`, `PixPaymentDialog`, `SettlementList`, `GroupReports`, `ExpenseView`, e `formatValue` em `ExpenseManager`) — é duplicação de **helper**, não de tipo; fora do item 003. Vira item novo em `docs/backlog/` (regra 7 do `CLAUDE.md`), não task desta feature.
- **Rateio não-igualitário** (percentual/valor por pagador) — não existe no modelo (§2.5).
- **Campo categoria** de despesa (item de backlog 024).
- **App Expo** (`app/`).
