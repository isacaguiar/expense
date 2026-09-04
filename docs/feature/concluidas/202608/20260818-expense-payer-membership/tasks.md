# Tasks — Checagem de membership em despesas (criação e leitura)

> Cada task aponta para uma seção de `plan.md`. Sem dependência técnica entre elas — ordem abaixo segue severidade (leitura antes de escrita, ver `plan.md` §5).

Versão: 1.0 · Criado em: 20260818

---

## TASK-039 — Checagem de membership em `getMonthlyExpenses`

Aponta para: `plan.md` §2.

**Arquivo**: `backend/app/Http/Controllers/ExpenseController.php` (método `getMonthlyExpenses`).

**Mudança**: adicionar `$group = Group::findOrFail($groupId); $this->authorizeGroupMembership($group);` no início do método, antes da query em `DB::table('ex_expenses')` — mesmo padrão já usado em `indexByGroup`.

**Teste**: novo `backend/tests/Feature/ExpenseControllerGetMonthlyExpensesTest.php`, mesmo padrão de `ExpenseControllerIndexByGroupTest.php`.

**Critério de aceite**: membro do grupo recebe `200` com os totais mensais; não-membro recebe `404`; teste PHPUnit cobrindo os dois casos passa.

## TASK-040 — Checagem de membership nos relatórios de grupo

Aponta para: `plan.md` §3.

**Arquivo**: `backend/app/Http/Controllers/GroupExpenseReportController.php` (métodos `reportByGroupAndYear` e `reportByGroupAndYearMonthlySettlement`).

**Mudança**: manter o `Group::find($groupId)` + retorno `404` manual já existente em ambos; adicionar `$this->authorizeGroupMembership($group);` logo depois, nos dois métodos, antes de qualquer query a `ex_expenses`.

**Teste**: novo `backend/tests/Feature/GroupExpenseReportControllerTest.php` — par de testes (membro `200` / não-membro `404`) para cada um dos dois métodos (4 testes no total).

**Critério de aceite**: membro do grupo recebe `200` com o relatório (ambos os endpoints); não-membro recebe `404` (ambos os endpoints); os 4 testes PHPUnit passam.

## TASK-041 — Validação de membership de `user_payer_id`/`payers` no `store()`

Aponta para: `plan.md` §1.

**Arquivo**: `backend/app/Http/Controllers/ExpenseController.php` (método `store`).

**Mudança**: trocar a regra `exists:ex_users,id` de `user_payer_id` e `payers.*` por `Rule::exists('ex_groups_members', 'user_id')->where('group_id', $request->group_id)` (import `Illuminate\Validation\Rule`).

**Teste**: adicionar a `backend/tests/Feature/ExpenseControllerStoreTest.php` (já existe) os casos `test_payer_must_be_member_of_group` e `test_all_payers_must_be_members_of_group`.

**Critério de aceite**: request com `user_payer_id` ou algum item de `payers` fora do grupo recebe `422`; request válido (todos membros do grupo) continua `201`; os testes novos passam.
