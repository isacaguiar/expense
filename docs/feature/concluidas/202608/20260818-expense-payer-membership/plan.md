# Plan — Checagem de membership em despesas (criação e leitura)

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260818

---

## 1. `specify.md` §2.1 — `store()`: `user_payer_id`/`payers` devem ser membros do grupo

**Arquivo**: `backend/app/Http/Controllers/ExpenseController.php` (método `store`, regras de validação nas linhas 45-61).

- Trocar a regra `exists:ex_users,id` de `user_payer_id` e `payers.*` por `Rule::exists('ex_groups_members', 'user_id')->where('group_id', $request->group_id)` (`Illuminate\Validation\Rule`). Isso é mais forte que a regra atual — checa membership **e** existência do usuário numa query só, já que `ex_groups_members.user_id` só existe pra usuário real.
- Por quê validação (não checagem manual pós-`Group::findOrFail`, como `authorizeGroupMembership`): o problema aqui não é "esconder a existência do grupo" (quem chama já é membro confirmado do grupo antes desse ponto, via `authorizeGroupMembership($group)` na linha 64) — é um campo de input inválido em relação a outro campo do mesmo request. Isso é validação de request, não autorização de acesso a recurso; resposta correta é `422` (padrão do Laravel para falha de `$request->validate()`), não `404`. Mantém a mesma semântica de erro que o resto do método já usa para os outros campos.
- `group_id` já está disponível em `$request->group_id` no momento de montar o array de regras (mesmo request, sem dependência de ordem de execução das regras).
- Efeito colateral aceito: mensagem de erro passa a citar `ex_groups_members` implicitamente (não vaza nome de tabela pro client — Laravel usa mensagem genérica de `exists`), então não há vazamento de detalhe de schema.

## 2. `specify.md` §2.3 — `getMonthlyExpenses`: adicionar `authorizeGroupMembership`

**Arquivo**: `backend/app/Http/Controllers/ExpenseController.php` (método `getMonthlyExpenses`, linhas 105-121).

- Adicionar, logo no início do método, o mesmo par de linhas já usado em `indexByGroup` (linhas 16-17): `$group = Group::findOrFail($groupId); $this->authorizeGroupMembership($group);` — antes da query em `DB::table('ex_expenses')`.
- Por quê copiar o padrão de `indexByGroup` exatamente: os dois métodos recebem `$groupId` da mesma forma (segmento de rota) e hoje só um dos dois faz a checagem — não há motivo de design para a diferença, é uma omissão. `findOrFail` (404 automático do Eloquent) é consistente com o padrão já usado nesse controller, diferente do `Group::find` + json manual usado em `GroupExpenseReportController` (ver item 3).

## 3. `specify.md` §2.4 e §2.5 — reports: adicionar `authorizeGroupMembership`

**Arquivo**: `backend/app/Http/Controllers/GroupExpenseReportController.php` (métodos `reportByGroupAndYear`, linhas 13-101, e `reportByGroupAndYearMonthlySettlement`, linhas 103-182).

- Manter o `Group::find($groupId)` + retorno `404` manual (`'message' => 'Grupo não encontrado'`) já existente em ambos os métodos — não é o alvo desta feature e mudar o formato da resposta de "grupo não existe" é uma mudança de contrato desnecessária aqui (fora de escopo, ver `specify.md` §3).
- Logo após o `if (!$group) { ... }`, adicionar `$this->authorizeGroupMembership($group);` nos dois métodos, antes de qualquer query a `ex_expenses`. Não-membro recebe o `404` genérico do `abort_unless` (`Controller.php:21`) — resposta diferente do "grupo não encontrado" (esperado: não é o mesmo caso, e o comentário em `Controller.php:14-16` já documenta a intenção de não confirmar a existência do grupo pra quem não é membro).
- Os dois métodos duplicam a mesma lógica de carregar despesas/gerar relatório (achado pré-existente, não desta feature) — a mudança é local a cada método, sem extrair helper novo, pra não aumentar o diff além do necessário.

## 4. Testes (obrigatório por `00-constitution.md` §2.2 — regra de autorização nova exige PHPUnit)

- **Item 1** (`store`): adicionar a `backend/tests/Feature/ExpenseControllerStoreTest.php` (já existe, segue padrão de `test_non_member_cannot_create_expense_in_group`) casos `test_payer_must_be_member_of_group` e `test_all_payers_must_be_members_of_group` — grupo com membro autenticado válido, mas `user_payer_id`/`payers` incluindo um usuário fora do grupo → `422`.
- **Item 2** (`getMonthlyExpenses`): novo arquivo `backend/tests/Feature/ExpenseControllerGetMonthlyExpensesTest.php`, mesmo padrão de `ExpenseControllerIndexByGroupTest.php` (`tokenFor`, `DatabaseTransactions`) — `test_member_can_view_monthly_expenses` (200) e `test_non_member_cannot_view_monthly_expenses` (404).
- **Item 3** (reports): novo arquivo `backend/tests/Feature/GroupExpenseReportControllerTest.php` (não existe hoje) — um par de testes (member 200 / non-member 404) para cada um dos dois métodos (4 testes no total).

## 5. Ordem de execução

Sem dependência técnica entre os itens 1-3 — são três métodos independentes, em dois controllers, cada um com sua própria checagem local. Ordem em `tasks.md` segue a ordem de severidade percebida no `specify.md`: leitura (itens 2 e 3, vazam dado pra qualquer usuário autenticado, sem precisar nem ser membro de algum grupo) antes de escrita (item 1, exige já ser membro autenticado de um grupo — superfície de ataque menor). Testes (item 4) são parte de cada task, não uma task à parte, para não haver task "sem teste" passando pelo checklist pré-PR.
