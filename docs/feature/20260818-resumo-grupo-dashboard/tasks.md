# Tasks — Resumo do Grupo (Dashboard)

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs a partir de `TASK-053` — maior ID já usado no projeto antes desta feature: `TASK-052` (`docs/feature/20260818-fluxo-despesas-grupo/tasks.md`).

Versão: 1.1 · Criado em: 20260818

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-053 | Migration: coluna `closing_day` (nullable) em `ex_groups` + fillable/cast em `Group` | backend | plan.md §1 | nenhum (migration aditiva local) | Pendente |
| TASK-054 | `GroupController::store`/`update` valida e persiste `closing_day` | backend | plan.md §1 | nenhum | Pendente |
| TASK-055 | Campo "Dia de fechamento" em `GroupForm.tsx` (criação e edição) | frontend | plan.md §1 | nenhum | Pendente |
| TASK-056 | Classe `BillingCycle` — cálculo do ciclo fechado (default calendário, `closing_day` custom, clamp de mês curto, navegação `cyclesAgo`) | backend | plan.md §2 | nenhum | Pendente |
| TASK-057 | Testes unitários de `BillingCycle` | backend | plan.md §2 | nenhum | Pendente |
| TASK-058 | Endpoint novo `GET /groups/{groupId}/expenses/summary` (totais + despesas do ciclo com status + saldos por pessoa) | backend | plan.md §3 | nenhum | Pendente |
| TASK-059 | Testes backend do endpoint `summary` (status por tipo de despesa, saldos, navegação `cycles_ago`, membership) | backend | plan.md §3 | nenhum | Pendente |
| TASK-060 | Rota `/groups/:id/summary` + esqueleto `GroupSummary.tsx`: cabeçalho do ciclo, navegação entre ciclos fechados, cards Total/Pago/A pagar | frontend | plan.md §4 | nenhum | Pendente |
| TASK-061 | Lista "Despesas do ciclo" em `GroupSummary.tsx` com status (`Chip` Paga/Pendente) | frontend | plan.md §4 | nenhum | Pendente |
| TASK-062 | Bloco "Saldos por pessoa" em `GroupSummary.tsx` | frontend | plan.md §4 | nenhum | Pendente |
| TASK-063 | Dropdown de troca de grupo dentro de `GroupSummary.tsx` | frontend | plan.md §5 | nenhum | Pendente |
| TASK-064 | Rota `/summary` + `SummaryEntry.tsx` + link "Resumo" no `Navbar` | frontend | plan.md §5 | nenhum | Pendente |
| TASK-065 | Ícone "Resumo" adicional no card de grupo em `Dashboard.tsx` | frontend | plan.md §5 | nenhum | Pendente |
| TASK-066 | Testes frontend (`GroupSummary`: cards/lista/saldos/navegação de ciclo/dropdown; `SummaryEntry` nos 3 cenários; `GroupForm` com `closing_day`) | frontend | plan.md §1, §4, §5 | nenhum | Pendente |

## Critérios de aceite

- **TASK-053**: `php artisan migrate` local aplica sem erro; `Group::create([...'closing_day'=>10...])` via tinker persiste; grupo criado sem `closing_day` continua funcionando (`NULL` aceito, regressão).
- **TASK-054**: `POST /api/groups` com `closing_day: 10` retorna `201` e o registro criado tem `closing_day=10`; com `closing_day: 32` ou `0` retorna `422`; sem `closing_day` no payload, cria normalmente com `NULL` (campo opcional); `PUT /api/groups/{id}` atualiza `closing_day` de um grupo existente.
- **TASK-055**: no formulário de criar/editar grupo, preencher "Dia de fechamento" e salvar reflete no payload enviado (`read_network_requests`); deixar em branco não bloqueia o envio (campo opcional).
- **TASK-056**: `BillingCycle::closedCycle(null, <qualquer data>)` devolve o mês calendário anterior completo (dia 1 ao último dia); `BillingCycle::closedCycle(10, <referência>)` devolve ciclo dia 11 do mês anterior a dia 10 do mês de referência; `closing_day=31` com referência em março devolve fechamento em 28/29 de fevereiro (clamp); `cyclesAgo=1` devolve o ciclo fechado imediatamente anterior ao mais recente.
- **TASK-057**: `php artisan test` verde cobrindo os 4 casos do critério de TASK-056 como testes unitários isolados (sem HTTP/banco).
- **TASK-058**: `GET /groups/{id}/expenses/summary` sem `cycles_ago` devolve o ciclo fechado mais recente (nunca o ciclo que contém a data de hoje); `totals.total` bate com a soma de `expenses[].value`; despesa À Vista aparece com `paid:true`; despesa Fixa projetada num mês do ciclo sem `Quota` própria aparece com `paid:false`; `balances[]` inclui todos os membros do grupo (inclusive saldo `0`) e a soma de todos os `balance` é `0`; usuário não-membro do grupo recebe `404`.
- **TASK-059**: `php artisan test` verde cobrindo os casos de TASK-058 (status por tipo de despesa, saldos com e sem despesas no ciclo, `cycles_ago` navegando para ciclo anterior, `404` para não-membro).
- **TASK-060**: navegar para `/groups/{id}/summary` mostra o ciclo fechado mais recente no cabeçalho (datas de início/fim) e os 3 cards com valores batendo com `totals` da API; seta "ciclo anterior" chama a API com `cycles_ago` incrementado; não existe seta para avançar além do ciclo fechado mais recente.
- **TASK-061**: cada despesa do ciclo aparece na lista com pagador, participantes e `Chip` "Paga" (verde) ou "Pendente" (laranja) condizente com `paid` retornado pela API.
- **TASK-062**: bloco "Saldos por pessoa" lista todos os membros do grupo (inclusive saldo zero) com valor e "a receber"/"a pagar" batendo com o sinal de `balance`.
- **TASK-063**: selecionar outro grupo no dropdown navega para `/groups/{novoId}/summary` e recarrega cabeçalho/cards/lista/saldos do grupo selecionado.
- **TASK-064**: acessar `/summary` com 1 grupo redireciona automaticamente para `/groups/{id}/summary`; com mais de 1 grupo, mostra lista para escolher; com 0 grupos, mostra mensagem informativa; link "Resumo" no `Navbar` navega para `/summary`.
- **TASK-065**: clicar no ícone "Resumo" do card de um grupo em `/dashboard` navega direto para `/groups/{id}/summary`.
- **TASK-066**: `npx vitest run` verde cobrindo os cenários de TASK-055 e TASK-060 a TASK-064 (campo `closing_day` no form, cards, status por Chip, saldos, navegação de ciclo, troca de grupo, e os 3 cenários de `SummaryEntry`).
