# Despesas Parceladas não aparecem nos meses seguintes ao de criação, e valor exibido é o total, não a parcela

ID: 012
Origem: docs/feature/20260818-resumo-grupo-dashboard/specify.md (achado durante investigação da feature "Resumo do Grupo")
Criado em: 2026-08-18
Prioridade: MÉDIA
Status: Promovido para TASK-132

## Descrição

`ExpenseController::indexByGroup` (`backend/app/Http/Controllers/ExpenseController.php:14-67`) monta a lista de despesas de um mês combinando dois conjuntos: `$direct` (despesas cujo `date_payment` cai exatamente no mês/ano pedido, linhas 38-43) e `$projectedFixed` (despesas do tipo `FIXED` projetadas para meses futuros ao de criação, linhas 46-55). Não existe projeção equivalente para despesas do tipo `IN_INSTALLMENTS` (parceladas): uma despesa parcelada em 3x só aparece no mês em que foi criada — nos dois meses seguintes, ela simplesmente não é retornada por `indexByGroup`, mesmo já existindo `Quota` (`ex_quotas`) para cada uma das 3 parcelas (`buildInstallmentQuotas`, `frontend/src/pages/ExpenseManager.tsx:67-81`).

Além disso, mesmo no único mês em que a despesa aparece, `mapRow` (`ExpenseController.php:26-35`) retorna `'value' => $expense->total_value` — o valor **total** da compra, não o valor da parcela daquele mês (`value_quota` da `Quota` correspondente). Ou seja, uma compra de R$ 900,00 em 3x aparece como R$ 900,00 no mês 1, e desaparece completamente nos meses 2 e 3 (onde deveria aparecer como R$ 300,00 cada).

## Por que importa

Qualquer tela que resuma despesas por mês (a tela atual `ExpenseManager.tsx`, e a nova "Resumo do Grupo" em `docs/feature/20260818-resumo-grupo-dashboard/`) fica com números incorretos para grupos que usam parcelamento: o total do mês de criação vem inflado (valor cheio em vez da parcela) e os meses seguintes ficam sem registro nenhum da parcela em aberto, subestimando "quanto ainda falta pagar" no grupo.

Tipo sugerido: backend — estender `indexByGroup` para projetar `IN_INSTALLMENTS` mês a mês (uma linha por `Quota`, no padrão já usado para `FIXED`) e usar `value_quota` em vez de `total_value` em `mapRow`. Decidir ao promover se reaproveita o mesmo padrão de `$projectedFixed` ou se passa a montar a lista inteira a partir de `Quota` (com join em `Expense`) em vez de `Expense`.

## Resolução

Concluído em: 2026-08-21
Feature: docs/feature/20260821-expense-manager-mes-e-data-corretos/
Tasks: TASK-132
PRs: https://github.com/isacaguiar/expense/pull/43 (mergeado em `dev`, agrupado com item 013)
