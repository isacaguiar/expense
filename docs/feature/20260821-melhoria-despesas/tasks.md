# Tasks — Melhoria da Gestão de Despesas do Grupo

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs seguem a numeração global do projeto (maior existente antes desta feature: TASK-148).

Versão: 1.0 · Criado em: 20260822

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-149 | Migration + model — `paid_at`/`paid_by` em `ex_quotas` (`Quota`) | backend | plan.md §6 | antes do merge | Implementada |
| TASK-150 | `ExpenseController::store` — forçar `paid=false` sempre na criação de quotas, ignorando valor enviado pelo cliente | backend | plan.md §6 | antes do merge | Implementada |
| TASK-151 | Testes — despesa nasce sempre com quotas `paid=false`, mesmo se o cliente enviar `paid:true` | backend | plan.md §6 | nenhum | Implementada |
| TASK-152 | `ExpenseController` — método privado que materializa (busca ou cria) a `Quota` da ocorrência mensal de uma despesa `FIXED` | backend | plan.md §3 | antes do merge | Implementada |
| TASK-153 | `collectCycleEntries` — usar `Quota` materializada de `FIXED` quando existir, em vez de projetar sempre ao vivo | backend | plan.md §3 | antes do merge | Implementada |
| TASK-154 | Testes — valor de despesa `FIXED` editado depois não muda mês já materializado/congelado | backend | plan.md §3 | nenhum | Implementada |
| TASK-155 | Migration + model — `closed_manually_at`/`reopened_at` em `GroupCycleSnapshot` | backend | plan.md §4 | antes do merge | Implementada |
| TASK-156 | `ExpenseController` — helper único de "competência fechada" (automática OU manual), substituindo o uso isolado de `rejectIfCycleClosed` | backend | plan.md §4, §9 | antes do merge | Implementada |
| TASK-157 | Rota + `ExpenseController::close` — `POST /groups/{groupId}/expenses/close`, materializa `FIXED` do mês, computa e faz upsert do snapshot manual | backend | plan.md §4 | antes do merge | Implementada |
| TASK-158 | `ExpenseController::summary` — expor estado `closed_manually` distinto de `closed` automático na resposta | backend | plan.md §4 | antes do merge | Pendente |
| TASK-159 | `ExpenseController::store` — bloquear criação de despesa em competência fechada (automática ou manual) | backend | plan.md §9 | antes do merge | Pendente |
| TASK-160 | Testes — fechar/re-fechar (upsert) e bloqueio de criação/edição/exclusão na competência fechada | backend | plan.md §4, §9 | nenhum | Pendente |
| TASK-161 | Rota + `ExpenseController::reopen` — `POST /groups/{groupId}/expenses/reopen`, só permitido na competência ainda vigente | backend | plan.md §5 | antes do merge | Pendente |
| TASK-162 | Testes — reabertura permitida só no mês vigente; negada após a virada mesmo com snapshot manual existente | backend | plan.md §5 | nenhum | Pendente |
| TASK-163 | Rota + `ExpenseController::pay` — `POST /expenses/{expenseId}/pay`, só credor, só competência aberta, materializa `FIXED` se preciso | backend | plan.md §6 | antes do merge | Pendente |
| TASK-164 | Rota + `ExpenseController::unpay` — `POST /expenses/{expenseId}/unpay`, só credor, só competência aberta | backend | plan.md §6 | antes do merge | Pendente |
| TASK-165 | `ExpenseController::update` — bloquear alteração de valor quando a `Quota` da competência já está paga | backend | plan.md §6 | antes do merge | Pendente |
| TASK-166 | Testes — pay/unpay só pelo credor e só com competência aberta; update de valor bloqueado se pago | backend | plan.md §6 | nenhum | Pendente |
| TASK-167 | `ExpenseController::destroy` — bloquear exclusão se a despesa tem `Quota` da competência paga | backend | plan.md §2 | antes do merge | Pendente |
| TASK-168 | Testes — exclusão bloqueada se paga; permitida se pendente e competência aberta | backend | plan.md §2 | nenhum | Pendente |
| TASK-169 | Teste — saldo por pessoa (`computeCycleSummary`) continua correto após materialização de `Quota` para `FIXED` | backend | plan.md §7 | nenhum | Pendente |
| TASK-170 | Extrair hook `useGroupCycle` de `GroupSummary.tsx` para reuso de navegação por ciclo | frontend | plan.md §1, §8 | antes do merge | Pendente |
| TASK-171 | Extrair componente `<BalanceCards />` de `GroupSummary.tsx` para reuso dos cards de saldo por pessoa | frontend | plan.md §1, §7 | antes do merge | Pendente |
| TASK-172 | `ExpenseManager.tsx` — adotar `useGroupCycle`/`GET .../expenses/summary` no lugar da navegação por mês calendário | frontend | plan.md §1, §8 | antes do merge | Pendente |
| TASK-173 | `ExpenseManager.tsx` — layout em grid (listagem principal + `<BalanceCards />`) | frontend | plan.md §1, §7 | antes do merge | Pendente |
| TASK-174 | `ExpenseManager.tsx` — campos completos por despesa (Tipo, Competência, Credor, Pagadores, Status) e ícones de ação condicionais com tooltip | frontend | plan.md §1 | antes do merge | Pendente |
| TASK-175 | `ExpenseManager.tsx` — modal de confirmação de exclusão de despesa variável | frontend | plan.md §2 | antes do merge | Pendente |
| TASK-176 | `ExpenseManager.tsx` — botões "Fechar mês" / "Reabrir mês" | frontend | plan.md §4, §5 | antes do merge | Pendente |
| TASK-177 | `ExpenseManager.tsx` — ação marcar como paga / desfazer pagamento, visível só para o credor | frontend | plan.md §6 | antes do merge | Pendente |
| TASK-178 | Testes de componente novos para `ExpenseManager.tsx` (grid, ações condicionais, modal de exclusão, fechar/reabrir, pagar/despagar) | frontend | plan.md §1–§6 | nenhum | Pendente |

## Critérios de aceite

- **TASK-149**: migration roda limpo local (`php artisan migrate`); `Quota::create([...])` aceita `paid_at`/`paid_by`; `paid_by` é FK nullable para `ex_users`.
- **TASK-150**: `POST /expenses` com `quotas[0].paid: true` no corpo da requisição cria a(s) `Quota`(s) com `paid = false` no banco, independente do valor enviado.
- **TASK-151**: teste automatizado cobrindo o caso acima para `IN_CASH`, `IN_INSTALLMENTS` (múltiplas quotas) e `FIXED`; suíte completa (`php artisan test`) continua verde.
- **TASK-152**: método unitário testável que, para uma despesa `FIXED` e um mês sem `Quota`, cria uma `Quota` com `value_quota = total_value` vigente e `date_expected` no dia projetado; chamado de novo no mesmo mês não duplica (retorna a mesma linha).
- **TASK-153**: despesa `FIXED` com `Quota` materializada num mês retorna `value`/`paid` dessa `Quota` em `collectCycleEntries`; mês sem `Quota` continua projetando ao vivo a partir de `total_value` (comportamento atual preservado).
- **TASK-154**: teste que materializa a `Quota` de um mês, depois altera `total_value` da `FIXED`, e confirma que o resumo daquele mês (`GET .../summary` com `cycles_ago` apontando pra ele) continua com o valor antigo; o mês corrente (sem `Quota` ainda) reflete o valor novo.
- **TASK-155**: migration roda limpo local; `GroupCycleSnapshot::create([...])` aceita `closed_manually_at`/`reopened_at` (nullable, default null).
- **TASK-156**: helper único usado por `update`/`destroy`/`store`/`pay`/`unpay` retorna "fechado" tanto quando `BillingCycle::statusFor` é `closed` quanto quando existe snapshot com `closed_manually_at` preenchido e `reopened_at` nulo para a competência da despesa; retorna "aberto" nos demais casos.
- **TASK-157**: `POST /groups/{groupId}/expenses/close` cria/atualiza (upsert) o `GroupCycleSnapshot` da competência vigente com `closed_manually_at = now()`, `reopened_at = null`, `totals`/`expenses`/`balances` recalculados, e materializa `Quota` para toda `FIXED` daquele mês que ainda não tinha; chamar de novo (re-fechar) sobrescreve o mesmo registro sem criar duplicata (`unique(group_id, cycle_start)` preservado). Não há um caminho de 422 "fora da competência vigente" — a rota sempre opera sobre a competência que contém "agora", que por construção do `BillingCycle::cycleFor(cyclesAgo=0)` está sempre aberta em relação a "agora" (achado registrado durante a implementação, corrigindo a suposição original do `plan.md` §4).
- **TASK-158**: `GET /groups/{groupId}/expenses/summary` da competência vigente após `close` devolve `cycle.status = 'closed_manually'` (não `'open'` nem `'closed'`); competência realmente fechada por `BillingCycle` continua devolvendo `'closed'`.
- **TASK-159**: `POST /expenses` com `date_payment` dentro de uma competência com `BillingCycle` `closed`, ou com fechamento manual ativo, retorna 422 e não cria a despesa; criação em competência aberta continua funcionando.
- **TASK-160**: suíte nova verde cobrindo os 3 cenários dos critérios de TASK-157/159 mais o de re-fechamento (upsert).
- **TASK-161**: `POST /groups/{groupId}/expenses/reopen` com snapshot manual ativo (`closed_manually_at` preenchido, `reopened_at` nulo) e competência ainda `open` por `BillingCycle` seta `reopened_at = now()` e volta a computar ao vivo (`GET .../summary` reflete edições novas). Chamada após a competência já ter virado `closed` automático (mesmo com snapshot manual existente) retorna 422 e não altera o registro.
- **TASK-162**: teste que fecha manualmente, avança o relógio (ou usa `Carbon::setTestNow`) para depois da virada do mês, e confirma que `reopen` retorna 422 nesse cenário.
- **TASK-163**: `POST /expenses/{expenseId}/pay` chamado pelo `user_payer_id` da despesa, com competência aberta, marca a `Quota` da competência com `paid=true`, `paid_at=now()`, `paid_by=auth()->id()` (materializando a `Quota` primeiro se for `FIXED` sem uma ainda); chamado por qualquer outro usuário (inclusive `user_creator_id` diferente do credor) retorna 403; chamado com competência fechada retorna 422.
- **TASK-164**: `POST /expenses/{expenseId}/unpay` nas mesmas condições de autorização/competência de TASK-163 volta `paid=false`, `paid_at=null`, `paid_by=null`.
- **TASK-165**: `PUT /expenses/{id}` alterando `total_value` (ou `value_quota` via payers/quotas, se aplicável) de uma despesa cuja `Quota` da competência tem `paid=true` retorna 422 e não altera o valor; despesa pendente continua editável normalmente.
- **TASK-166**: suíte nova verde cobrindo os 4 cenários de autorização/competência de TASK-163/164 e o bloqueio de TASK-165.
- **TASK-167**: `DELETE /expenses/{id}` de uma despesa com `Quota` da competência `paid=true` retorna 422 e não marca `deleted=true`; despesa pendente na competência aberta continua sendo excluída normalmente (comportamento atual preservado).
- **TASK-168**: suíte nova verde cobrindo os 2 cenários acima, mais o caso já existente de competência fechada (não regressão de `rejectIfCycleClosed`).
- **TASK-169**: teste que materializa `Quota` de uma `FIXED` num mês, roda `computeCycleSummary`, e confirma que `balances` é idêntico ao calculado antes da materialização (mesmo grupo/despesas/participantes) — a origem do dado muda, o resultado não.
- **TASK-170**: `useGroupCycle(groupId)` extraído, usado por `GroupSummary.tsx` no lugar da lógica inline atual, com `npx vitest run src/pages/GroupSummary.test.tsx` continuando 100% verde (sem mudança de comportamento).
- **TASK-171**: `<BalanceCards balances={...} />` extraído, usado por `GroupSummary.tsx` no lugar da lista inline atual, com a mesma suíte de teste continuando verde.
- **TASK-172**: `ExpenseManager.tsx` usa `useGroupCycle`/consome `GET .../expenses/summary` — navegação por `cyclesAgo` (não mais `currentDate`/mês calendário); verificação manual no navegador (`npm run dev`) navegando entre ciclos aberto/fechado/futuro.
- **TASK-173**: `ExpenseManager.tsx` renderiza `<BalanceCards />` ao lado da listagem em telas `md`+ (grid de 2 colunas), empilhado em telas menores — verificação visual via preview do dev server em desktop e mobile (`resize_window`).
- **TASK-174**: cada card de despesa exibe Tipo, Competência (implícita no cabeçalho), Credor, lista de Pagadores e Status (`Pendente`/`Paga`); ícones de ação (editar/excluir/marcar como paga) só aparecem quando a API permitiria a ação (competência aberta + regras de dono/credor/status) — verificação manual cobrindo pelo menos 1 caso de cada ação escondida e 1 de cada visível.
- **TASK-175**: clicar em excluir despesa variável abre `Dialog` mostrando a descrição da despesa; confirmar chama `DELETE /expenses/{id}`; cancelar não chama nada — teste de componente ou verificação manual cobrindo os 2 caminhos.
- **TASK-176**: "Fechar mês" chama `POST .../close` e atualiza o chip de status para "Fechado (revisável)"; "Reabrir mês" só aparece nesse estado e chama `POST .../reopen`, voltando o chip para "Ciclo em andamento"; nenhum dos dois botões aparece em competência `closed` automática.
- **TASK-177**: botão de marcar/desfazer pagamento só aparece para o usuário logado quando ele é o credor (`user_payer_id`) da despesa e a competência está aberta; clicar chama `POST .../pay` ou `.../unpay` conforme o estado atual e atualiza o status exibido.
- **TASK-178**: `npx vitest run src/pages/ExpenseManager.test.tsx` verde, cobrindo pelo menos: grid renderizado, 1 ação escondida por regra de negócio, modal de exclusão (confirmar/cancelar), fechar/reabrir mês, marcar/desfazer pagamento.
