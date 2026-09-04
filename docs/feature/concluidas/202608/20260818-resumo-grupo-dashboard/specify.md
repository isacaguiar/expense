# Specify — Resumo do Grupo (Dashboard)

> Feature: fechamento mensal de contas por grupo — cada grupo tem uma data de fechamento configurável (estilo fatura de cartão de crédito); ao virar o ciclo, a tela "Resumo do grupo" mostra o ciclo fechado mais recente com cards de totais e, principalmente, quanto cada pessoa precisa pagar ou tem a receber dos demais membros. Origem: pedido novo do usuário nesta conversa (sem task/épico prévio em `03-tasks.md`); escopo revisado após o primeiro rascunho deste `specify.md` mostrar um "resumo ao vivo do mês corrente" que não era o objetivo real.

Versão: 1.1 · Criado em: 20260818

---

## 1. Problema

Hoje não existe nenhum conceito de "fechamento" de contas do grupo. Despesas são lançadas e consultadas por mês calendário (`year`/`month`) em `ExpenseManager.tsx`/`indexByGroup`, mas nada consolida, ao final de um ciclo, "quem precisa pagar quanto para quem" e apresenta isso de forma definitiva — o único cálculo equivalente (`GroupExpenseReportController::reportByGroupAndYearMonthlySettlement`) exige varrer o ano inteiro e não tem noção de ciclo por grupo, só mês calendário fixo (dia 1 a dia 1). O usuário quer que cada grupo defina sua própria data de fechamento (como uma fatura de cartão — ex.: "fecha todo dia 10"), e que, a partir do primeiro dia após esse fechamento, a tela de Resumo mostre o ciclo recém-fechado com foco em pagar/receber entre os membros.

## 2. Achados confirmados

### 2.1 Não existe hoje tela de resumo, nem noção de "ciclo"/fechamento — só mês calendário

`Dashboard.tsx:44-72` lista grupos sem dado financeiro. `ExpenseManager.tsx` e `ExpenseController::indexByGroup` (`backend/app/Http/Controllers/ExpenseController.php:14-67`) trabalham só com `year`/`month` (mês calendário, dia 1 a fim do mês) — não existe em nenhum lugar do código um conceito de ciclo com data de corte diferente do dia 1.

### 2.2 `Group` não tem campo de data de fechamento — precisa de migration aditiva

`ex_groups` (`backend/database/migrations/2025_06_07_033033_create_ex_groups_table.php`) só tem `name`, `description`, `deleted`, `create_date`. Model `Group` (`backend/app/Models/Group.php:11-16`) só expõe esses campos em `$fillable`. `GroupController@store`/`update` (`backend/app/Http/Controllers/GroupController.php:39-42,70`) só validam/persistem `name`/`description`. Nenhum lugar guarda um "dia de fechamento" por grupo — precisa de coluna nova (migration aditiva, dentro do gate autônomo de `00-constitution.md` §5.2 para ambiente local/branch).

### 2.3 `GroupForm.tsx` não tem campo para configurar isso

`GroupForm.tsx:94-130` só tem os campos "Nome" e "Descrição" no formulário de criar/editar grupo — não há onde o usuário informaria a data de fechamento do grupo.

### 2.4 Padrão de "clamp" de dia-do-mês para datas recorrentes já existe no código (referência para o cálculo de ciclo)

Duas rotinas já lidam com "dia do mês que pode não existir em todo mês" (ex.: dia 31 em fevereiro), um padrão direto para o cálculo de fechamento tipo cartão de crédito:
- `ExpenseController::indexByGroup` (linhas 58-59): `$day = min($expense->date_payment->day, $monthStart->daysInMonth);` para projetar despesa Fixa.
- `ExpenseManager.tsx:54-62` (`addMonthsClamped`): mesma lógica no frontend, para gerar `date_expected` das parcelas.

### 2.5 `GET /groups/{groupId}/expenses` (`indexByGroup`) já devolve despesas do mês, mas sem status de pagamento nem noção de ciclo custom

`mapRow` (`ExpenseController.php:26-35`) devolve `id`, `description`, `date`, `value` (sempre `total_value`, não o valor da parcela — ver achado 2.9), `payerName`, `isFixed` — sem nenhum campo de status ("paga"/"pendente"). Além disso, filtra estritamente por mês calendário (`whereYear`/`whereMonth`, linhas 40-41), incompatível com um ciclo que atravessa dois meses calendário (ex.: fechamento dia 10 → ciclo de 11/dez a 10/jan).

### 2.6 Status de pagamento (`Quota.paid`) existe, mas nasce fixo por tipo e nunca muda depois

- `ex_quotas` (`backend/database/migrations/2025_06_12_022708_create_ex_quotas_table.php`) tem coluna `paid` (boolean).
- No cadastro (`ExpenseManager.tsx:219-234`): À Vista nasce `paid:true`; Parcelada nasce cada parcela `paid:false` (`buildInstallmentQuotas`, linhas 67-81); Fixa nasce com 1 quota `paid:false`.
- Não existe endpoint para mudar isso depois: `QuotaController` e `ParticipationController` (`backend/app/Http/Controllers/QuotaController.php`, `ParticipationController.php`) são stubs vazios e **não estão registrados em `routes/api.php`** (confirmado via `grep "Route::"` — só `groups`, `groups/{groupId}/members`, `expenses`, `expenses/{expenseId}/stop-recurrence`, relatórios).
- Para despesas Fixa projetadas em meses além do de criação, não existe nenhuma `Quota` real — a projeção em `indexByGroup` é só virtual (linhas 46-64).

### 2.7 Saldo por pessoa já é calculado par a par para o ano inteiro, não como fechamento de ciclo por grupo

`GroupExpenseReportController::reportByGroupAndYearMonthlySettlement` (`backend/app/Http/Controllers/GroupExpenseReportController.php:108-194`) calcula `finalSettlement[receiver][payer] = valor` por mês calendário (par a par, não um saldo líquido único por pessoa), exige `{year}` inteiro e retorna 404 se o grupo não tiver despesa nenhuma naquele ano — não tem noção de "ciclo fechado de um grupo específico" nem devolve membro com saldo zero.

### 2.8 Endpoints reaproveitáveis sem mudança

- `GET /api/groups` — lista de grupos do usuário, para dropdown de troca de grupo.
- `GET /api/groups/{groupId}/members` — nomes dos membros, para a lista de saldos.
- `GET /api/me` — usuário autenticado.

### 2.9 Achado tangencial (não bloqueia esta feature, registrado em backlog)

`indexByGroup` nunca projeta despesas `IN_INSTALLMENTS` além do mês de criação, e nesse único mês mostra `total_value` (valor cheio) em vez do valor da parcela — debt pré-existente, também presente em `ExpenseManager.tsx` hoje. Registrado como item 012 do backlog (`docs/backlog/expense-manager-installments-nao-projetadas-por-mes.md`).

## 3. Requisitos

- **R1**: Grupo passa a ter uma "data/dia de fechamento" configurável (estilo fatura de cartão de crédito — um dia do mês, ex. 10), definido na criação do grupo e editável depois. Grupo sem valor definido mantém o comportamento equivalente a hoje (ciclo = mês calendário, fecha no fim do mês, disponível a partir do dia 1 seguinte — mesma linguagem do pedido original do usuário).
- **R2**: Um "ciclo" de um grupo é o intervalo entre dois fechamentos consecutivos (ex.: fechamento dia 10 → ciclo de 11 do mês anterior a 10 do mês atual, inclusive), com o mesmo tratamento de "clamp" para meses mais curtos já usado em despesas Fixa/Parceladas (achado 2.4). Um ciclo só é considerado "fechado" a partir do dia seguinte à sua data de fechamento — antes disso, é o ciclo "em andamento".
- **R3**: A tela "Resumo do grupo" abre, por padrão, no **ciclo fechado mais recente** do grupo selecionado, com foco em quanto cada membro deve pagar ou tem a receber dos demais (não no ciclo em andamento). Deve ser possível navegar para ciclos fechados anteriores (setas, mesmo padrão de navegação de mês já usado em `ExpenseManager.tsx`).
- **R4**: A tela mostra, para o ciclo selecionado: cards de totais (Total de despesas / Pago / A pagar), lista de despesas do ciclo (data, descrição, valor, pagador, status Paga/Pendente a partir de `Quota.paid` onde existir — default "Pendente" quando não houver `Quota` real, ex. despesa Fixa projetada) e um bloco "Saldos por pessoa" com o saldo líquido de cada membro do grupo (positivo = a receber, negativo = a pagar), incluindo membros com saldo zero.
- **R5**: Backend expõe um endpoint novo que devolve, para um ciclo (o fechado mais recente por padrão, ou um ciclo anterior via parâmetro), os totais, a lista de despesas com status e os saldos por pessoa — calculado a partir do intervalo de datas do ciclo (não de `year`/`month` calendário), reaproveitando a lógica de projeção de despesa Fixa já existente em `indexByGroup`, adaptada para um intervalo de datas.

## 4. Fora de escopo desta feature

- Redesenho da navegação global para sidebar (mockup mostrava "Despesas/Participantes/Pagamentos/Relatórios/Configurações") — mantém-se `Navbar`/`InternalLayout` atual.
- Rebrand visual (paleta verde, logo, ilustrações do mockup) — `theme.ts`/`LoginPage.tsx` inalterados.
- Qualquer fluxo para marcar uma quota/parcela como paga (endpoint novo em `QuotaController`/`ParticipationController`) — esta feature só lê o status que já existir no banco (achado 2.6), sem adicionar o mecanismo de alterá-lo.
- Rastrear `paid` por mês para despesas Fixa projetadas além do mês de criação — segue exibida como "Pendente" por padrão quando não há `Quota` real (mesma lacuna do achado 2.6, último bullet).
- Mudar `ExpenseController::indexByGroup`, `ExpenseManager.tsx` ou `GroupExpenseReportController` para usar o conceito de ciclo — eles continuam em mês calendário; só o endpoint novo desta feature (R5) usa ciclo. Unificar tudo em torno de ciclo, se fizer sentido no futuro, é decisão maior de arquitetura, não desta feature.
- Notificação/e-mail avisando que o ciclo fechou — a tela só mostra o dado quando o usuário a acessa, não há push/e-mail nesta feature.
- Projeção de `IN_INSTALLMENTS` além do mês de criação (achado 2.9) — backlog item 012.
