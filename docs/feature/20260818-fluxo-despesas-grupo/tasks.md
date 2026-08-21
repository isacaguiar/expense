# Tasks — Fluxo de Despesas do Grupo

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs a partir de `TASK-033` — maior ID já usado no projeto antes desta feature: `TASK-032` (`docs/feature/20260817-infra-testes-frontend/tasks.md`).

Versão: 1.0 · Criado em: 20260818

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-033 | Corrigir leitura do parâmetro de rota (`id`, não `groupId`) em `ExpenseManager.tsx` | frontend | plan.md §1 | nenhum | Concluída (PR #12 mergeado em `dev`) |
| TASK-034 | Criar `GET /api/groups/{groupId}/expenses` (listagem individual por mês) com checagem de membership | backend | plan.md §2 | nenhum | Concluída (PR #14 mergeado em `dev`) |
| TASK-035 | Consumir o endpoint novo em `ExpenseManager.tsx` (troca a URL de origem dos dados da tabela) | frontend | plan.md §2 | nenhum | Concluída (PR #15 mergeado em `dev`) |
| TASK-036 | Adicionar checagem de membership e forçar `user_creator_id` do usuário autenticado em `ExpenseController::store` | backend | plan.md §3 | nenhum | Concluída (PR #17 mergeado em `dev`) |
| TASK-037 | Alinhar formulário "Nova Despesa" ao payload real de `POST /api/expenses` (seletor de pagador + campos obrigatórios) | frontend | plan.md §3 | nenhum | Concluída (PR #19 mergeado em `dev`) |
| TASK-038 | Criar rota `/expenses` com seleção/redirect automático de grupo (`ExpensesEntry.tsx`) | frontend | plan.md §4 | nenhum | Concluída (PR #20 mergeado em `dev`) |
| TASK-042 | Migration: `FIXED` no enum `expense_type` + coluna `fixed_recurrence_ends_at` em `ex_expenses`; fillable/cast em `Expense` | backend | plan.md §6 | migration destrutiva (alterar tipo de coluna) — local autônomo, ambiente compartilhado/produção exige gate humano | Concluída (local, merge `ffdfc471`) |
| TASK-043 | `ExpenseController::store` aceita `expense_type=FIXED` + validação cruzada (`installments=1`, exatamente 1 quota) | backend | plan.md §7.1 | nenhum | Concluída (merge `ffdfc471`) |
| TASK-044 | Endpoint novo `POST /expenses/{id}/stop-recurrence` | backend | plan.md §7.2 | nenhum | Concluída (merge `ffdfc471`) |
| TASK-045 | Projetar despesas Fixa em `indexByGroup` (2ª query + merge + campo `isFixed`) | backend | plan.md §8 | nenhum | Concluída (merge `ffdfc471`) |
| TASK-046 | Testes backend (store `IN_INSTALLMENTS`/`FIXED`, `stop-recurrence`, projeção em `indexByGroup`) | backend | plan.md §9 | nenhum | Concluída (merge `ffdfc471`) |
| TASK-047 | Seletor "Tipo de despesa" (À Vista/Parcelada/Fixa) + relabel do campo de data por tipo | frontend | plan.md §10.1-10.2 | nenhum | Concluída (merge `9586acdd`) |
| TASK-048 | Seletor multi-select de participantes da divisão (`payers[]`, default todos marcados) | frontend | plan.md §10.3 | nenhum | Concluída (merge `9586acdd`) |
| TASK-049 | Fluxo Parcelada completo: campo Nº parcelas + cálculo de `quotas[]` com arredondamento | frontend | plan.md §10.4 | nenhum | Concluída (merge `9586acdd`) |
| TASK-050 | Fluxo Fixa completo: payload `FIXED` com quota simbólica `paid:false` | frontend | plan.md §10.4 | nenhum | Concluída (merge `9586acdd`) |
| TASK-051 | Ação "Remover" (diálogo com 2 opções) nas linhas Fixa da tabela | frontend | plan.md §10.5 | nenhum | Concluída (merge `9586acdd`) |
| TASK-052 | Testes frontend (`ExpenseManager.test.tsx`: 3 fluxos de criação + seletor de participantes + diálogo remover) | frontend | plan.md §9 | nenhum | Concluída (merge `9586acdd`) |
| TASK-053 | Corrigir `indexByGroup`: despesa Fixa some do próprio mês de criação quando `fixed_recurrence_ends_at` é esse mesmo mês (achado de code review pós-merge, branch `backend/20260819-fluxo-despesas-correcoes-review`) | backend | plan.md §8 | nenhum | Concluída |
| TASK-054 | Ressincronizar `participantIds` com `members` quando o modal "Nova Despesa" já está aberto (achado de code review: abrir o modal antes do `GET /members` resolver deixa a lista de participantes vazia) | frontend | plan.md §10.3 | nenhum | Concluída |
| TASK-055 | Validação cruzada de `quotas` para `expense_type=IN_INSTALLMENTS` em `ExpenseController::store` (contagem de quotas = `installments`, soma das quotas = `total_value`) | backend | plan.md §7.1 | nenhum | Concluída |

## Critérios de aceite

- **TASK-042**: `php artisan migrate` local aplica sem erro; `Expense::create([...'expense_type'=>'FIXED'...])` via tinker persiste; `expense_type='IN_CASH'` e `'IN_INSTALLMENTS'` continuam funcionando (regressão); coluna `fixed_recurrence_ends_at` aceita `NULL` e uma data.
- **TASK-043**: `POST /expenses` com `expense_type=FIXED, installments=1`, 1 quota → `201`; com `installments=2` ou 2 quotas → `422`.
- **TASK-044**: `POST /expenses/{id}/stop-recurrence` com membro do grupo e despesa `FIXED` → `200` e `fixed_recurrence_ends_at` persistido; não-membro → `404`; despesa não-`FIXED` → `422`; `year`/`month` anterior ao mês de criação → `422`.
- **TASK-045**: despesa `FIXED` criada em mês N aparece em `GET /groups/{id}/expenses?year&month` para todo mês > N (com `isFixed:true`), some a partir do mês configurado em `fixed_recurrence_ends_at` (inclusive), e continua aparecendo nos meses anteriores ao corte.
- **TASK-046**: `php artisan test` verde incluindo os novos casos (store `IN_INSTALLMENTS`/`FIXED`, `stop-recurrence`, projeção em `indexByGroup`).
- **TASK-047**: no browser, selecionar "Parcelada" ou "Fixa" no modal muda o formulário (campo de parcelas aparece/some) e o label do campo de data muda conforme o tipo.
- **TASK-048**: multi-select mostra todos os membros do grupo pré-marcados; desmarcar um e salvar reflete em `payers[]` do payload enviado (confirmável via `read_network_requests`); tentar salvar com 0 marcados é bloqueado no formulário.
- **TASK-049**: criar despesa parcelada em N parcelas gera N registros em `ex_quotas` (confirmável via `tinker`), com `date_expected` incrementando 1 mês por parcela e soma de `value_quota` batendo com o valor total (arredondamento absorvido na última parcela).
- **TASK-050**: criar despesa fixa gera 1 `Expense` com `expense_type=FIXED` e 1 quota `paid:false`; aparece no mês de criação e nos meses seguintes (validação cruzada com TASK-045).
- **TASK-051**: clicar "Remover" numa despesa fixa abre o diálogo, escolher cada uma das 2 opções chama `stop-recurrence` com o `year`/`month` correto (confirmável via `read_network_requests`), e a tabela reflete o corte após reload.
- **TASK-052**: `npx vitest run` verde cobrindo os 3 fluxos de criação, o seletor de participantes e o diálogo de remoção.
- **TASK-033**: navegar para `/groups/{id}/expenses` faz `loadExpenses()` disparar uma chamada de rede de verdade (visível na aba Network do browser) em vez de retornar cedo por `groupId` indefinido — mesmo que a chamada ainda falhe (endpoint da TASK-034 não existe ainda), confirma que o parâmetro chega correto.
- **TASK-034**: usuário autenticado membro do grupo recebe `200` com array de despesas (`id`, `description`, `date`, `value`, `payerName`) filtradas por `year`/`month`; usuário autenticado que não é membro do grupo recebe `404` (não `403` — ajustado na execução após revisão de segurança, mesmo padrão de `GroupController::authorizeMembership`, evita confirmar a existência do grupo pra quem não é membro); teste automatizado ou chamada manual via `curl`/Tinker documentando os dois casos.
- **TASK-035**: com uma despesa de teste criada (via `tinker`/seed), a tabela de `ExpenseManager.tsx` mostra a linha correta (descrição, valor, data, pagador) para o mês/ano selecionado — validado no browser, não só por tipo TypeScript batendo.
- **TASK-036**: `POST /api/expenses` com `group_id` de um grupo ao qual o usuário autenticado não pertence retorna `404` (mesmo padrão de `Controller::authorizeGroupMembership`, criado na TASK-034) e não cria registro; `POST /api/expenses` com `user_creator_id` diferente do usuário autenticado no payload cria a despesa mas com `user_creator_id` = usuário autenticado (não o valor enviado) — confirmado consultando o registro criado.
- **TASK-037**: preencher e submeter o modal "Nova Despesa" (com um pagador selecionado da lista de membros do grupo) cria uma despesa real via `POST /api/expenses`; a despesa aparece na tabela (TASK-035) depois do modal fechar, sem reload manual da página.
- **TASK-038**: acessar `/expenses` com um usuário que pertence a exatamente 1 grupo redireciona automaticamente para `/groups/{id}/expenses` desse grupo; com mais de 1 grupo, mostra uma lista para escolher; com 0 grupos, mostra mensagem informativa (nenhuma das três situações resulta em tela em branco ou 404).
- **TASK-053**: despesa `FIXED` criada no mês N com `fixed_recurrence_ends_at = N` (mesmo mês) não aparece mais em `GET /groups/{id}/expenses?year&month` para o mês N; despesa sem corte, ou com corte em mês futuro, continua aparecendo normalmente no mês de criação (regressão).
- **TASK-054**: abrir "Nova Despesa" antes do `GET /groups/{id}/members` responder e só depois a resposta chegar → `participantIds` reflete todos os membros marcados (não fica vazio).
- **TASK-055**: `POST /expenses` com `expense_type=IN_INSTALLMENTS`, `installments=3` e 2 quotas → `422`; com 3 quotas cuja soma não bate com `total_value` → `422`; caso consistente (já coberto pela TASK-046) continua `201`.
