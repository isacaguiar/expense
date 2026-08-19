# Specify — Fluxo de Despesas do Grupo

> Feature: corrige o acesso à tela de despesas de um grupo, hoje quebrado em duas frentes independentes — navegação pelo menu e contrato de dados com o backend. Origem: itens 006 e 009 do backlog (`docs/backlog/expense-manager-groupid-param-mismatch.md`, `docs/backlog/despesas-menu-tela-branco-frontend.md`).

Versão: 1.0 · Criado em: 20260818

---

## 1. Problema

Duas falhas distintas impedem qualquer usuário de ver ou lançar despesas de um grupo hoje:

1. O link "Despesas" do menu (`Navbar.tsx`) leva para `/expenses`, uma rota que não existe em `App.tsx` — não há tela para escolher em qual grupo ver despesas quando o usuário pertence a mais de um grupo.
2. Mesmo acessando `/groups/:id/expenses` diretamente, a tela (`ExpenseManager.tsx`) nunca carrega nada: o parâmetro de rota lido (`groupId`) não bate com o declarado (`:id`), e mesmo corrigindo isso, o contrato de dados que o componente espera do backend não existe da forma que ele assume.

## 2. Achados confirmados

### 2.1 Link "Despesas" do menu não tem rota correspondente

`Navbar.tsx:22` linka para `/expenses`. `App.tsx` não declara essa rota (só `/groups/:id/expenses`, que exige um `id` de grupo na URL) — a navegação cai no catch-all `*` (`App.tsx:34`, "404: Página não encontrada"), lido pelo usuário como tela em branco.

### 2.2 Nome do parâmetro de rota não bate

A rota `/groups/:id/expenses` (`App.tsx:29`) declara o parâmetro como `id`, mas `ExpenseManager.tsx:38` lê `useParams<{ groupId: string }>()`. `groupId` fica sempre `undefined`, e `loadExpenses()` (`ExpenseManager.tsx:74`) retorna cedo por causa do `if (!groupId) return;`, então a chamada à API nunca acontece.

### 2.3 GET de despesas não existe com o formato que o frontend espera

`ExpenseManager.tsx:82` chama `GET /api/groups/{groupId}/expenses` (não existe — confirmado via `php artisan route:list` e `curl` retornando 404 mesmo com token inválido, ou seja nem chega a validar autenticação). O único endpoint real de leitura é `GET /api/groups/{groupId}/expenses/monthly` (`ExpenseController@getMonthlyExpenses`, `backend/app/Http/Controllers/ExpenseController.php:72`), que devolve **totais agregados por mês** (`year, month, total_expenses, total_value`) via `DB::table('ex_expenses')->selectRaw(...)->groupBy(...)`, não uma lista de despesas individuais — não tem `id`, `description`, `payerName` por registro, que é o que `ExpenseManager.tsx`'s tipo `Expense` (linhas 29-35) e a tabela renderizada (linhas 210-238) esperam.

### 2.4 POST de nova despesa não bate com o contrato real

`ExpenseManager.tsx:140` chama `POST /api/groups/{groupId}/expenses` com `{ description, value, date }` — rota inexistente. O endpoint real de criação é `POST /api/expenses` (`ExpenseController@store`, `backend/app/Http/Controllers/ExpenseController.php:13`), que valida um payload bem mais rico: `date_payment`, `description`, `expense_type` (`IN_CASH`|`IN_INSTALLMENTS`), `installments`, `total_value`, `group_id`, `user_creator_id`, `user_payer_id`, `payers[]` (array de `user_id`), `quotas[]` (array de `{date_expected, number, paid, value_quota}`). O model `Expense` (`backend/app/Models/Expense.php`) confirma essas colunas e relações (`payers()` many-to-many via `ex_expenses_payers`, `quotas()` hasMany).

### 2.5 Endpoints de apoio já existentes e reaproveitáveis

- `GET /api/groups/{groupId}/members` (`GroupMemberController@index`) — lista membros do grupo, necessário para popular o seletor de pagador(es) no formulário de nova despesa.
- `GET /api/me` (`AuthController@me`) — usuário autenticado atual, necessário para preencher `user_creator_id` automaticamente.
- `GET /api/groups` (`GroupController@index`) — já usado por `GroupList.tsx`, reaproveitável para a tela de seleção de grupo do item 009.

## 3. Requisitos

- **R1**: Criar uma rota `/expenses` que, ao ser acessada: se o usuário pertence a exatamente um grupo, redireciona automaticamente para `/groups/{id}/expenses` desse grupo; se pertence a mais de um, mostra uma tela para escolher o grupo (reaproveitando os grupos já carregáveis via `GET /api/groups`); se não pertence a nenhum, mostra uma mensagem apropriada (não uma tela em branco).
- **R2**: Corrigir `ExpenseManager.tsx` para ler o parâmetro de rota correto (`id`, alinhado com `App.tsx:29`).
- **R3**: Criar um endpoint de backend que devolva a lista de despesas individuais de um grupo em um mês (`id`, `description`, `date_payment`, `total_value`, pagador(es)) — não o agregado que `/expenses/monthly` já fornece hoje (esse continua existindo, sem alteração, para quem já o consome). Ajustar `ExpenseManager.tsx` para consumir o novo endpoint.
- **R4**: Ajustar o formulário "Nova Despesa" de `ExpenseManager.tsx` para montar e enviar o payload real esperado por `POST /api/expenses` (incluindo seleção de pagador via `GET /api/groups/{groupId}/members`, e um caso simples de `expense_type: IN_CASH` / `installments: 1` / uma única quota cobrindo o valor total).
- **R5**: O formulário "Nova Despesa" passa a suportar os 3 tipos reais de despesa — **À Vista** (`IN_CASH`, comportamento do R4 inalterado), **Parcelada** (`IN_INSTALLMENTS`, com campo "Quantidade de parcelas" e cálculo client-side de `quotas[]`, uma por mês a partir da data informada, valor dividido igualmente com arredondamento absorvido na última parcela) e **Fixa** (`FIXED`, novo valor de `expense_type`, tabela `ex_expenses` estendida via migration aditiva — sem tabela própria de tipos). O campo de data existente é reaproveitado como "mês de início" das parcelas/recorrência, editável (não trava no mês da compra — compra no cartão pode só cobrar a partir do mês seguinte).
- **R6**: Despesa do tipo Fixa aparece automaticamente em `GET /groups/{groupId}/expenses` (`indexByGroup`) em todo mês subsequente ao de criação, até ser removida. Remoção é feita por um endpoint novo (`POST /expenses/{id}/stop-recurrence`) que recebe o mês de corte calculado pelo frontend a partir de um diálogo com 2 opções ("a partir deste mês" / "a partir do mês que vem") — o backend não assume qual das duas, só persiste o `year`/`month` recebido.
- **R7**: O formulário "Nova Despesa" ganha um seletor de participantes da divisão (`payers[]`, multi-select dos membros do grupo, default todos marcados) — hoje o formulário só escolhe quem pagou (`user_payer_id`) e sempre manda `payers` com um único elemento; nem todo membro do grupo participa de toda despesa, e o backend já valida/usa `payers[]` como array (TASK-041, relatórios em `docs/sdd/01-specify.md` §3.4).

## 4. Fora de escopo desta feature

- Edição ou exclusão de despesas já lançadas (`expenses.update`, `expenses.destroy`) — a tela hoje só lista e cria, e esta feature não adiciona esses fluxos.
- Split percentual/customizado entre participantes — R7 permite escolher *quem* participa, mas a divisão continua igualitária entre os selecionados (sem peso por pessoa), mesma regra que os relatórios já aplicam.
- Qualquer mudança em `GroupExpenseReportController` ou na tela de relatório anual (`report/{year}`) — não fazem parte do sintoma relatado, e despesas Fixa não são projetadas nesses relatórios nesta feature.
- Projeção de despesas Fixa em `GET /groups/{groupId}/expenses/monthly` (`getMonthlyExpenses`, agregado mensal) — endpoint sem nenhum consumidor hoje (confirmado por busca no frontend); registrado como ideia futura em `docs/backlog/` para quando houver consumidor real, ver `plan.md` §9.
- Job/scheduler para pré-gerar quotas mensais de despesas Fixa — a recorrência é resolvida por projeção em tempo de consulta (`indexByGroup`), não por geração antecipada de registros, evitando depender de cron/infra nova.
