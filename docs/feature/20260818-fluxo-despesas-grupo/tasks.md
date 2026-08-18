# Tasks — Fluxo de Despesas do Grupo

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs a partir de `TASK-033` — maior ID já usado no projeto antes desta feature: `TASK-032` (`docs/feature/20260817-infra-testes-frontend/tasks.md`).

Versão: 1.0 · Criado em: 20260818

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-033 | Corrigir leitura do parâmetro de rota (`id`, não `groupId`) em `ExpenseManager.tsx` | frontend | plan.md §1 | nenhum | PR aberto |
| TASK-034 | Criar `GET /api/groups/{groupId}/expenses` (listagem individual por mês) com checagem de membership | backend | plan.md §2 | nenhum | Pendente |
| TASK-035 | Consumir o endpoint novo em `ExpenseManager.tsx` (troca a URL de origem dos dados da tabela) | frontend | plan.md §2 | nenhum | Pendente |
| TASK-036 | Adicionar checagem de membership e forçar `user_creator_id` do usuário autenticado em `ExpenseController::store` | backend | plan.md §3 | nenhum | Pendente |
| TASK-037 | Alinhar formulário "Nova Despesa" ao payload real de `POST /api/expenses` (seletor de pagador + campos obrigatórios) | frontend | plan.md §3 | nenhum | Pendente |
| TASK-038 | Criar rota `/expenses` com seleção/redirect automático de grupo (`ExpensesEntry.tsx`) | frontend | plan.md §4 | nenhum | Pendente |

## Critérios de aceite

- **TASK-033**: navegar para `/groups/{id}/expenses` faz `loadExpenses()` disparar uma chamada de rede de verdade (visível na aba Network do browser) em vez de retornar cedo por `groupId` indefinido — mesmo que a chamada ainda falhe (endpoint da TASK-034 não existe ainda), confirma que o parâmetro chega correto.
- **TASK-034**: usuário autenticado membro do grupo recebe `200` com array de despesas (`id`, `description`, `date`, `value`, `payerName`) filtradas por `year`/`month`; usuário autenticado que não é membro do grupo recebe `403`; teste automatizado ou chamada manual via `curl`/Tinker documentando os dois casos.
- **TASK-035**: com uma despesa de teste criada (via `tinker`/seed), a tabela de `ExpenseManager.tsx` mostra a linha correta (descrição, valor, data, pagador) para o mês/ano selecionado — validado no browser, não só por tipo TypeScript batendo.
- **TASK-036**: `POST /api/expenses` com `group_id` de um grupo ao qual o usuário autenticado não pertence retorna `403` e não cria registro; `POST /api/expenses` com `user_creator_id` diferente do usuário autenticado no payload cria a despesa mas com `user_creator_id` = usuário autenticado (não o valor enviado) — confirmado consultando o registro criado.
- **TASK-037**: preencher e submeter o modal "Nova Despesa" (com um pagador selecionado da lista de membros do grupo) cria uma despesa real via `POST /api/expenses`; a despesa aparece na tabela (TASK-035) depois do modal fechar, sem reload manual da página.
- **TASK-038**: acessar `/expenses` com um usuário que pertence a exatamente 1 grupo redireciona automaticamente para `/groups/{id}/expenses` desse grupo; com mais de 1 grupo, mostra uma lista para escolher; com 0 grupos, mostra mensagem informativa (nenhuma das três situações resulta em tela em branco ou 404).
