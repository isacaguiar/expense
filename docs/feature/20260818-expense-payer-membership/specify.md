# Specify — Checagem de membership em despesas (criação e leitura)

> Feature: garante checagem de membership de grupo em todas as rotas de despesa que hoje faltam — na criação (`user_payer_id`/`payers[]` de `POST /api/expenses`) e na leitura (3 endpoints de relatório/total que hoje respondem para qualquer usuário autenticado, de qualquer grupo). Origem: item de backlog 010 (`docs/backlog/expense-store-sem-checagem-membership-payer.md`), mais 3 achados de leitura confirmados durante esta etapa de Specify.

Versão: 1.0 · Criado em: 20260818

---

## 1. Problema

`ExpenseController` e `GroupExpenseReportController` expõem dado financeiro de grupo (despesas, totais, settlement — quem deve quanto pra quem) sem checar consistentemente se quem está pedindo tem relação com aquele grupo. A TASK-036 (feature `fluxo-despesas-grupo`) adicionou checagem de membership do usuário autenticado em parte das rotas — via `authorizeGroupMembership()` (`backend/app/Http/Controllers/Controller.php:17`) —, mas deixou de fora, por escopo, tanto a validação de que `user_payer_id`/`payers` (na criação) pertencem ao grupo, quanto 3 rotas de leitura que nunca tiveram essa checagem.

**Lado escrita**: qualquer membro autenticado de um grupo pode criar uma despesa nesse grupo e apontar `user_payer_id`/`payers` para qualquer `user_id` válido do sistema — inclusive alguém que nunca participou daquele grupo —, gerando cobrança/Pix indevida para essa pessoa.

**Lado leitura**: qualquer usuário autenticado no sistema — mesmo sem nenhuma relação com o grupo — consegue ler dado financeiro completo de qualquer grupo (descrição de despesa, valor, quem pagou, participantes, e no caso do settlement, quem deve quanto pra quem), só sabendo o `groupId`. É um vazamento de dado financeiro mais direto que o de escrita, pois não exige nem ser membro de nenhum grupo.

Ver `00-constitution.md` §5.3 (achados conhecidos de checagem de membership ausente).

## 2. Achados confirmados

### 2.1 Validação atual não checa membership de payer/payers

`backend/app/Http/Controllers/ExpenseController.php:53-55` — a regra de validação de `store()` é:

```php
'user_payer_id'    => 'required|exists:ex_users,id',
'payers'           => 'required|array|min:1',
'payers.*'         => 'exists:ex_users,id',
```

`exists:ex_users,id` só confirma que o `id` existe na tabela `ex_users`, sem relação nenhuma com `group_id` da mesma requisição. O grupo já é carregado logo depois (`ExpenseController.php:63`, `Group::findOrFail($request->group_id)`) e a membership do usuário **autenticado** já é checada (`ExpenseController.php:64`, `$this->authorizeGroupMembership($group)`), mas nenhuma checagem equivalente existe para `user_payer_id`/`payers`.

### 2.2 Padrão existente para checagem de membership

`Group::members()` (`backend/app/Models/Group.php:35-38`) é um `belongsToMany(User::class, 'ex_groups_members', 'group_id', 'user_id')` — a mesma relação usada por `authorizeGroupMembership()`. Uma checagem de "todos os IDs de uma lista são membros do grupo" pode reaproveitar essa relação (ex.: comparar `payers` + `user_payer_id` contra `$group->members()->pluck('id')`), sem precisar de query nova por usuário.

### 2.3 `getMonthlyExpenses` sem checagem de membership

`backend/app/Http/Controllers/ExpenseController.php:105-121` — o método recebe `$groupId` pela URL (`GET /groups/{groupId}/expenses/monthly`), consulta `ex_expenses` direto via `DB::table`, e nunca carrega o `Group` nem chama `authorizeGroupMembership()`. Qualquer usuário autenticado, de qualquer grupo, lê totais mensais agregados de qualquer outro grupo só trocando o `groupId` na URL.

### 2.4 `reportByGroupAndYear` sem checagem de membership

`backend/app/Http/Controllers/GroupExpenseReportController.php:13-101` — carrega o grupo só com `Group::find($groupId)` (linha 17) para checar existência (404 se não achar), mas nunca chama `authorizeGroupMembership()`. Retorna despesa por despesa (descrição, valor, nome do pagador, participantes, valor por pessoa) de qualquer grupo pra qualquer usuário autenticado.

### 2.5 `reportByGroupAndYearMonthlySettlement` sem checagem de membership

`backend/app/Http/Controllers/GroupExpenseReportController.php:103-182` — mesmo padrão do item 2.4 (`Group::find` só pra 404, sem `authorizeGroupMembership`, linha 105), mas retorna também o `finalSettlement` mensal — quem deve quanto pra quem dentro do grupo. É o achado de leitura mais sensível dos três.

## 3. Fora de escopo desta feature

- Checagem de membership em outras rotas/controllers fora de despesas (ex.: `GroupController@show/update/destroy`, `GET /pix/generate`) — achados distintos, já registrados em `00-constitution.md` §5.3, tratados separadamente.
- Implementar os métodos `show`/`update`/`destroy` de `ExpenseController`, hoje registrados via `Route::apiResource('expenses', ...)` sem existir no controller (erro 500, não vazamento) — dívida já rastreada em `00-constitution.md` §2.4/`03-tasks.md`, é implementação de funcionalidade nova, não correção de autorização; tratada em task própria, fora desta feature.
- Mudança no fluxo de UI do frontend para impedir a seleção de um não-membro como pagador, ou para esconder relatórios de grupos que o usuário não participa (a origem do problema é a falta de validação/autorização no backend; UI é hardening adicional, não o requisito desta feature).
- Qualquer alteração em `PATCH`/`PUT` de despesa existente — não coberta aqui por não existir método implementado (ver item acima).
