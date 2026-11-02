# `GET /api/expenses` está registrado, mas `ExpenseController@index` não existe

ID: 132
Origem: TASK-559 da feature `docs/feature/20261030-saida-e-remocao-de-membro/` (conferência de `php artisan route:list` ao atualizar a Constitution §2.4 e o `01-specify.md`)
Criado em: 2026-11-02
Prioridade: BAIXA
Status: Aberto

## Descrição
- `backend/routes/api.php:60` registra `Route::apiResource('expenses', ExpenseController::class)`, que inclui `GET /api/expenses` → `ExpenseController@index`. O controller tem `store`, `show`, `update`, `destroy` e `indexByGroup`, mas **não tem `index`**.
- **Reproduzido** em 2026-11-02 no banco de teste, com um token válido, dentro de uma transação desfeita: `GET /api/expenses` → **500**, `BadMethodCallException: Method App\Http\Controllers\ExpenseController::index does not exist.` (`method_exists` é `false`).
- Nenhum cliente chama essa rota: o frontend usa `POST /api/expenses` e `/api/expenses/{id}`, e a listagem vem de `GET /api/groups/{id}/expenses`. É a única rota do `apiResource('expenses')` sem método.

## Por que importa
Quebra a regra da Constitution §2.4 (e do digest §6.0): ao registrar rota, todos os métodos referenciados devem existir. É um 500 acionável por qualquer usuário autenticado. Em produção `APP_DEBUG` é `false`, então o corpo não expõe o caminho do arquivo.

Tipo sugerido: bugfix pelo BFF (`/novo-bug`). Duas saídas, a decidir: restringir o recurso com `->except('index')` (o caminho mais simples, sem mudar nenhum cliente) ou implementar o `index` (exigiria definir o que listar, já que a listagem útil é por grupo).
