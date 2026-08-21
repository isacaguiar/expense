# Implementar ExpenseController::show/update/destroy (rotas já registradas sem método)

ID: 023
Origem: docs/feature/20260820-atualizacao-layout-paginas/specify.md (achado ao avaliar o mockup de Despesas — telas "Visualizar"/"Editar"/"Excluir")
Criado em: 2026-08-20
Prioridade: ALTA
Status: Promovido para TASK-126

## Descrição

`backend/routes/api.php:35` registra `Route::apiResource('expenses', ExpenseController::class)`, que cria rotas para `show` (`GET /expenses/{id}`), `update` (`PUT/PATCH /expenses/{id}`) e `destroy` (`DELETE /expenses/{id}`) — mas `ExpenseController` (`backend/app/Http/Controllers/ExpenseController.php`) não define nenhum desses 3 métodos. Chamar qualquer uma dessas rotas hoje resulta em erro (`BadMethodCallException`/rota não resolvida). É o mesmo padrão de violação já registrado em `00-constitution.md` §2.4 (rota registrada sem método existente).

## Por que importa

Bloqueia diretamente as telas "Visualizar despesa", "Editar despesa" (página cheia) e o fluxo de exclusão do mockup `desktop.png`/`site-full.png` — a feature `atualizacao-layout-paginas` optou por fazer essas interações só no cliente (sobre os dados já carregados), justamente por essas rotas não funcionarem. Implementar de verdade também precisa decidir: quem pode editar/excluir uma despesa (authorization — dono? qualquer membro do grupo?), e se exclusão é soft delete (coluna `deleted`, já existe na tabela) — não pode ser `DELETE` físico sem gate humano, conforme `CLAUDE.md`.

Tipo sugerido: backend
