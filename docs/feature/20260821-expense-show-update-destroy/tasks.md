# Tasks — Expense show/update/destroy

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs seguem a numeração global do projeto (maior existente antes desta feature: TASK-125).

Versão: 1.0 · Criado em: 20260821

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-126 | Implementar `ExpenseController::show`, com helpers `findExpenseForMember`/membership | backend | plan.md §1, §4 | antes do merge | Implementada na branch da feature |
| TASK-127 | Implementar `ExpenseController::update`, com helper `authorizeExpenseOwner` (criador/pagador) | backend | plan.md §2, §4 | antes do merge | Implementada na branch da feature |
| TASK-128 | Implementar `ExpenseController::destroy` (soft delete), reaproveitando `authorizeExpenseOwner` | backend | plan.md §3 | antes do merge | Implementada na branch da feature |

## Critérios de aceite

- **TASK-126**: `GET /expenses/{id}` de despesa de um grupo do qual o usuário autenticado não é membro → `404`. De despesa com `deleted=true` → `404`. De despesa de grupo próprio → `200` com `payers` e `quotas` incluídos no JSON. Teste automatizado cobrindo os 3 casos em `backend/tests/Feature/ExpenseControllerShowUpdateDestroyTest.php`.
- **TASK-127**: `PUT/PATCH /expenses/{id}` de não-membro do grupo → `404`. De membro que não é criador nem pagador → `403`, nenhum campo alterado no banco. De criador → `200`, campos atualizados. De pagador (quando `user_creator_id !== user_payer_id`) → `200`. Envio de `expense_type`/`installments`/`quotas` no payload é ignorado silenciosamente (não quebra a validação, não altera esses campos). Teste automatizado cobrindo os casos acima.
- **TASK-128**: `DELETE /expenses/{id}` de não-membro → `404`. De membro que não é criador nem pagador → `403`, `deleted` continua `false` no banco. De criador ou pagador → `200`, registro no banco com `deleted=true` (nunca ausente — `assertDatabaseHas`, não `assertDatabaseMissing`). Teste automatizado cobrindo os casos acima.
