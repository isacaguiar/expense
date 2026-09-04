# Specify — Expense show/update/destroy

> Feature: implementar `ExpenseController::show/update/destroy`, hoje ausentes apesar de as rotas já estarem registradas via `apiResource`. Promovida do item 023 do backlog (`docs/backlog/expense-show-update-destroy-ausentes.md`), origem `docs/feature/concluidas/202608/20260820-atualizacao-layout-paginas/specify.md`.

Versão: 1.0 · Criado em: 20260821

---

## 1. Problema

`backend/routes/api.php:37` registra `Route::apiResource('expenses', ExpenseController::class)`, que cria rotas para `show` (`GET /expenses/{id}`), `update` (`PUT/PATCH /expenses/{id}`) e `destroy` (`DELETE /expenses/{id}`) — mas `ExpenseController` (`backend/app/Http/Controllers/ExpenseController.php`) só define `indexByGroup`, `store`, `stopRecurrence`, `getMonthlyExpenses` e `summary`. Chamar qualquer uma dessas 3 rotas hoje resulta em `BadMethodCallException` (Laravel tenta invocar um método que não existe no controller).

Isso é o mesmo padrão de violação já registrado em `docs/sdd/00-constitution.md` §2.4 ("Não deixar controllers stub registrados em rotas ativas") — aqui a rota está ativa mas o método correspondente não existe, o que é pior (erro em runtime, não em boot).

Bloqueia diretamente as telas "Visualizar despesa" e "Editar despesa" (página cheia) e o fluxo de exclusão do mockup da feature `atualizacao-layout-paginas` (`desktop.png`/`site-full.png`), que hoje contorna o problema fazendo essas interações só no cliente sobre dados já carregados.

## 2. Requisitos

### 2.1 `show` — `GET /expenses/{id}`

Retornar os dados de uma despesa (incluindo `payers` e `quotas`, já usados no mockup de edição) se, e somente se, o usuário autenticado for membro do grupo dono da despesa (`Expense::group_id`). Usar o helper já existente `Controller::authorizeGroupMembership(Group $group)` (`backend/app/Http/Controllers/Controller.php:17`), no mesmo padrão já aplicado em `indexByGroup`/`store`/`stopRecurrence` deste próprio controller e em `GroupController::show/update/destroy` (`backend/app/Http/Controllers/GroupController.php:73-105`, que já resolve a checagem de membership — a violação de IDOR ainda descrita em `00-constitution.md` §5.2/§5.3 para `GroupController` está desatualizada, o código já checa). Despesa com `deleted = true` deve retornar 404 (mesmo padrão de "não confirmar existência" já usado no grupo).

### 2.2 `update` — `PUT/PATCH /expenses/{id}`

Checagem de authorization em duas camadas: (1) mesma checagem de membership de 2.1 (senão 404 — não confirma existência a quem nem é do grupo); (2) usuário autenticado precisa ser o **criador** (`user_creator_id`) **ou** o **pagador** (`user_payer_id`) da despesa, senão `403` (a despesa já é visível para qualquer membro via `show`/`indexByGroup`, então aqui não há razão para esconder a existência — só negar a ação). Campos editáveis: `description`, `date_payment`, `total_value`, `user_payer_id`, `payers` (reaproveitar as mesmas regras de validação de `store`, adaptadas para `sometimes`). **Fora de escopo**: alterar `expense_type`, `installments` ou `quotas` de uma despesa existente — isso exigiria recalcular quotas/parcelas e não faz parte do mockup de edição desta feature (ver §3).

Decisão de quem pode editar: **só o criador ou o pagador da despesa**, não qualquer membro do grupo — diferente do padrão de `GroupController::update` (grupo é editável por qualquer membro), porque uma despesa tem dono/responsável explícitos (`user_creator_id`, `user_payer_id`) que o grupo não tem.

### 2.3 `destroy` — `DELETE /expenses/{id}`

Mesma checagem de authorization em duas camadas de 2.2 (membership → 404; criador ou pagador → 403). **Soft delete obrigatório**: `update(['deleted' => true])`, nunca `DELETE` físico — mesmo padrão de `GroupController::destroy` (`backend/app/Http/Controllers/GroupController.php:97-105`) e regra explícita do `CLAUDE.md` raiz e `00-constitution.md` §2.5. Mesma decisão de quem pode excluir: só o criador ou o pagador.

## 3. Fora de escopo desta feature

- Alterar `expense_type`, `installments` ou recriar `quotas` numa despesa existente via `update` — decisão de negócio maior (recálculo de parcelas), não coberta pelo mockup atual.
- Revisar/atualizar a documentação desatualizada de `00-constitution.md` sobre `GroupController` (o texto ainda descreve uma vulnerabilidade de IDOR que o código já não tem) — fica registrado aqui como achado, mas não é escopo desta feature; se quiser, vira item novo de backlog.
- Outros itens do backlog originados da mesma feature (`024` campo categoria, `025` status "Aguardando") — não agrupados nesta promoção.
