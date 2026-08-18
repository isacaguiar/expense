# ExpenseManager nunca carrega despesas (duas causas: param errado + rota inexistente)

ID: 006
Origem: docs/feature/20260817-config-url-api-frontend/implementation.md (achado durante validação da TASK-029)
Criado em: 2026-08-17
Prioridade: ALTA
Status: Promovido para TASK-033

## Descrição

Duas causas independentes, ambas com o mesmo sintoma (tela de despesas nunca carrega), encontradas ao validar a TASK-029 no browser:

1. **Nome de parâmetro de rota errado**: a rota `/groups/:id/expenses` (`frontend/src/App.tsx:27`) declara o parâmetro como `:id`, mas `ExpenseManager.tsx:38` lê `useParams<{ groupId: string }>()` — nome diferente do declarado na rota. `groupId` fica sempre `undefined`, e `loadExpenses()` (`ExpenseManager.tsx:72-73`) tem `if (!groupId) return;` como primeira linha, então a chamada à API nunca é feita.
2. **Endpoint chamado pelo frontend não existe no backend**: mesmo corrigindo (1), `ExpenseManager.tsx:81` chama `GET ${API_BASE_URL}/api/groups/${groupId}/expenses` — mas `php artisan route:list` no backend não tem essa rota. As rotas reais para despesas de grupo são `GET /api/groups/{groupId}/expenses/monthly` (`ExpenseController@getMonthlyExpenses`) e `GET /api/groups/{groupId}/expenses/report/{year}` (`GroupExpenseReportController@reportByGroupAndYear`) — nenhuma delas bate com o path que o frontend chama (confirmado com `curl` retornando 404, mesmo passando um `Authorization` inválido — ou seja, nem chega a validar o token, a rota não existe).

## Por que importa

A tela de despesas do grupo nunca carrega nada em produção hoje — sempre fica vazia/em loading eterno, independente de autenticação ou dado existente. É um bug funcional visível para qualquer usuário que abrir "Despesas do Grupo", não um débito técnico silencioso. Corrigir só a causa 1 sem also alinhar o path com a rota real do backend (ou criar a rota que falta) não resolve o problema.

Tipo sugerido: frontend (causa 1) + backend ou contrato de API (causa 2) — decidir ao promover se a rota `GET /api/groups/{groupId}/expenses` deve ser criada no backend, ou se o frontend deve passar a chamar `/expenses/monthly`.
