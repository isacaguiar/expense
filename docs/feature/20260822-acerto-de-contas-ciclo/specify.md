# Specify — Acerto de Contas por Ciclo

> Feature: adiciona, ao fechamento do ciclo de um grupo, uma liquidação par-a-par (quem deve pagar quanto a quem) ao lado do saldo líquido já existente. Origem: pedido novo, surgido de uma pergunta do usuário sobre por que o saldo líquido de um membro não batia com a conta manual dele — a investigação revelou que o objetivo documentado do produto sempre foi par-a-par, não o saldo agregado.

Versão: 1.0 · Criado em: 20260822

---

## 1. Problema

A tela de Resumo do grupo (`GroupSummary.tsx`) mostra, no bloco "Saldos por pessoa", um **saldo líquido único** por membro (ex.: "João: R$ 87,50 a receber"). Esse número é a soma de tudo que a pessoa deve e tudo que tem a receber no ciclo, mas não diz **para quem** pagar ou **de quem** cobrar — que é o objetivo documentado do produto desde a baseline: `docs/sdd/01-specify.md` §1 (Objetivo do produto) diz "...enxergue **quanto cada pessoa deve a quem**, mês a mês, incluindo cobrança via Pix."

Isso não é um bug no cálculo atual (o saldo líquido está matematicamente correto — ver conversa de origem desta feature), é uma lacuna de escopo: falta a visão par-a-par que o objetivo do produto sempre pediu.

## 2. Achados confirmados

### 2.1 O saldo líquido hoje é calculado em `computeCycleSummary()`, sem nenhuma estrutura par-a-par

`backend/app/Http/Controllers/ExpenseController.php`, método `computeCycleSummary()` (ver `plan.md` para linhas exatas na branch atual — mover para cá após conferir no código desta branch): para cada despesa do ciclo, participantes que não são o pagador debitam `-valorPorPessoa`, o pagador credita `+valorPorPessoa`, tudo acumulado direto em `$balances[user_id]['balance']` (um único número por pessoa). Não existe, em nenhum momento do cálculo, uma estrutura intermediária que preserve "quem deve a quem" antes de agregar — a informação par-a-par é perdida assim que o saldo líquido é somado.

### 2.2 Já existe implementação parcial do par-a-par, mas fora do conceito de ciclo de fechamento e não usada pela tela de Resumo

`GroupExpenseReportController::reportByGroupAndYearMonthlySettlement` (`backend/app/Http/Controllers/GroupExpenseReportController.php:108-194`) já calcula `finalSettlement[credor][devedor] = valor` líquido par-a-par — mas:
- por **mês/ano calendário** (`date_payment + N meses`), não pelo `BillingCycle`/`closing_day` do grupo;
- recalcula `installmentValue = total_value / installments` do zero, **ignorando `Quota.value_quota`** já persistida (diverge da regra usada em `computeCycleSummary`/`collectCycleEntries`);
- chaveado por **nome** (`$expense->payer->name`), não por `user_id` — fragiliza em caso de nomes repetidos;
- **nenhuma tela do frontend consome este endpoint** hoje.

A variante anual (`reportByGroupAndYear`, mesmo arquivo, linhas 12-106) tem o mesmo cálculo de `finalSettlement` pronto, porém **comentado/desativado** (linhas 89-100).

### 2.3 Nenhuma feature nem task existente cobre "par-a-par no ciclo de fechamento"

- `docs/feature/20260818-resumo-grupo-dashboard/specify.md` §2.7 já registrou a existência do `finalSettlement` do `GroupExpenseReportController` e definiu deliberadamente que a tela de Resumo usaria **saldo líquido**, não par-a-par (ver também §4 "Fora de escopo" desse specify).
- `docs/backlog/summary-tela-relatorios.md` (item 018, prioridade baixa) propõe expor `reportByGroupAndYearMonthlySettlement` numa tela de "Relatórios" — mas é sobre o relatório **anual/mensal calendário** existente, não sobre o ciclo de fechamento do grupo.

### 2.4 O ciclo fechado já tem mecanismo de imutabilidade (`GroupCycleSnapshot`) que a liquidação par-a-par precisa seguir

Para ciclo fechado, `ExpenseController::cycleSnapshotFor()` computa `computeCycleSummary()` uma única vez e persiste o resultado (`totals`, `expenses`, `balances`) em `GroupCycleSnapshot` (`ex_group_cycle_snapshots`) — chamadas seguintes ao mesmo ciclo fechado leem o snapshot já gravado, mesmo que despesas sejam editadas depois. Qualquer estrutura nova de liquidação par-a-par tem que entrar nesse mesmo mecanismo, ou o ciclo fechado ficaria com "saldos" imutáveis mas "quem paga a quem" mudando por baixo — inconsistente.

## 3. Fora de escopo desta feature

- Simplificação de dívida multilateral (ex.: A deve a B, B deve a C, C deve a A → colapsar em menos transferências ainda que o par-a-par direto). O algoritmo proposto só neta pares diretos, igual ao `finalSettlement` já existente — simplificação multilateral é decisão de produto separada, não desta feature.
- Alterar `GroupExpenseReportController` (`reportByGroupAndYear`, `reportByGroupAndYearMonthlySettlement`) ou suas rotas — permanecem como estão, referência histórica / backlog 018.
- Qualquer ação de "marcar como pago" ou transferência real de dinheiro — a feature só **exibe** quem deve pagar a quem, sem alterar `Quota.paid` nem nenhum estado de pagamento.
- Unificar o conceito de ciclo (`BillingCycle`) nos relatórios anuais/mensais do `GroupExpenseReportController` — eles continuam em mês/ano calendário.
