# Specify — ExpenseManager: mês e data corretos

> Feature: corrige dois bugs de exibição de despesas na tela "Despesas do Grupo" (`ExpenseManager.tsx` + `ExpenseController::indexByGroup`) — parcelas que não aparecem nos meses seguintes ao de criação (com valor total em vez do valor da parcela), e data exibida com 1 dia a menos em fusos negativos. Promove os itens 012 e 013 do backlog, agrupados por tocarem a mesma tela e terem sido descobertos na mesma investigação (`docs/feature/concluidas/202608/20260818-resumo-grupo-dashboard/`).

Versão: 1.0 · Criado em: 20260821

---

## 1. Problema

Dois bugs de exibição, independentes entre si (arquivos e camadas diferentes), na mesma tela:

**012 — parcelas não projetadas por mês.** `ExpenseController::indexByGroup` (`backend/app/Http/Controllers/ExpenseController.php:17-77`) monta a lista de despesas de um mês combinando `$direct` (linhas 43-53, despesas cujo `date_payment` cai exatamente no mês pedido) e `$projectedFixed` (linhas 56-65, despesas `FIXED` projetadas mês a mês). Não existe projeção equivalente para `IN_INSTALLMENTS`: uma compra parcelada em 3x só aparece no mês de criação — nos meses seguintes ela não é retornada, mesmo já existindo uma `Quota` (`ex_quotas`) para cada parcela, com `date_expected` correto (`frontend/src/pages/ExpenseForm.tsx:42-56`, função `buildInstallmentQuotas`, confirma que cada quota recebe `date_expected` = data de criação + N meses). Além disso, mesmo no mês em que aparece, `mapRow` (linhas 29-38) usa `$expense->total_value` (valor cheio da compra) em vez do `value_quota` da parcela daquele mês — uma compra de R$ 900 em 3x aparece como R$ 900 no mês 1 e some nos meses 2 e 3, onde deveria aparecer R$ 300 em cada.

**013 — data com 1 dia a menos em fusos negativos.** `ExpenseManager.tsx:243` formata a data com `new Date(exp.date).toLocaleDateString('pt-BR')`. `exp.date` vem da API como string `YYYY-MM-DD`; `new Date('2026-07-16')` é interpretada como meia-noite UTC — convertida de volta para um fuso negativo (ex.: `America/Sao_Paulo`, UTC-3), cai no dia anterior. `GroupSummary.tsx:58-62` já tem a correção certa para o mesmo problema (`formatDate`, constrói a `Date` a partir de `year, month-1, day` em vez de parsear a string ISO) — só não foi replicada em `ExpenseManager.tsx` por estar fora do escopo daquela feature.

## 2. Requisitos

### 2.1 Projetar `IN_INSTALLMENTS` mês a mês, usando `value_quota`

`indexByGroup` passa a montar um terceiro conjunto, a partir de `Quota` (não de `Expense`): parcelas cujo `date_expected` cai no mês/ano pedido, da despesa pai `IN_INSTALLMENTS`, não deletada, do grupo pedido — mesmo padrão de "uma linha por ocorrência" já usado para `FIXED`, mas usando o `date_expected` real da `Quota` em vez de calcular uma projeção de dia do mês. `$direct` deixa de incluir despesas `IN_INSTALLMENTS` (para não duplicar a primeira parcela, que também cairia no mês de criação pela query antiga). Cada linha usa `quota.value_quota` como `value` (não `expense.total_value`).

### 2.2 Corrigir parse de data em `ExpenseManager.tsx`

Substituir `new Date(exp.date).toLocaleDateString('pt-BR')` por uma função que constrói a `Date` a partir dos componentes `year, month-1, day` extraídos da string `YYYY-MM-DD` — mesmo padrão de `GroupSummary.tsx:58-62`.

## 3. Fora de escopo desta feature

- Qualquer mudança em `ExpenseController::summary`/`collectCycleEntries` — esse método já usa `quota.value_quota` corretamente (`ExpenseController.php:363,368`), não tem o bug de 012.
- Mudar como `ExpenseForm.tsx` calcula/distribui as parcelas (`buildInstallmentQuotas`) — já está correto, é só consumido incorretamente por `indexByGroup`.
- Reestruturar `mapRow`/os três conjuntos (`$direct`/`$projectedFixed`/novo) para uma única query unificada a partir de `Quota` com join em `Expense` — o item de backlog cita essa alternativa, mas a abordagem aditiva (conjunto novo, mesmo padrão dos existentes) é suficiente e menos arriscada; refatoração maior fica para outra oportunidade se justificar.
