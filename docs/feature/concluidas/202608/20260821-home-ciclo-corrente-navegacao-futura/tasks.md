# Tasks — Home: ciclo corrente, navegação futura, status do ciclo e fechamento congelado

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md`. IDs seguem a numeração global do projeto (maior existente antes desta feature: TASK-133).

Versão: 1.0 · Criado em: 20260821

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-134 | `BillingCycle::cycleFor` — trocar eixo de referência (último fechado → ciclo que contém a referência) e expor `status` | backend | plan.md §1 | antes do merge | Pendente |
| TASK-135 | Reescrever `BillingCycleTest` para a nova semântica (ciclo aberto, fechado, futuro, borda do fechamento) | backend | plan.md §1 | antes do merge | Pendente |
| TASK-136 | `BillingCycle::statusFor` — método auxiliar que devolve o status do ciclo contendo uma data qualquer | backend | plan.md §4 | antes do merge | Pendente |
| TASK-137 | `collectCycleEntries` — corrigir projeção de parcelas `IN_INSTALLMENTS` por `date_expected` da `Quota` | backend | plan.md §2 | antes do merge | Pendente |
| TASK-138 | Migration `ex_group_cycle_snapshots` + model `GroupCycleSnapshot` | backend | plan.md §3 | antes do merge | Pendente |
| TASK-139 | Extrair cálculo de `totals`/`expenses`/`balances` de `summary()` para método privado reaproveitável | backend | plan.md §3 | antes do merge | Pendente |
| TASK-140 | `ExpenseController::summary` — usar `status` do ciclo; buscar/criar a foto para ciclo fechado, manter ao vivo para aberto/futuro | backend | plan.md §3 | antes do merge | Pendente |
| TASK-141 | Atualizar `ExpenseControllerSummaryTest` (default = mês corrente, ciclo futuro, parcela em outro ciclo, navegação anterior) | backend | plan.md §1, §2, §3 | nenhum | Pendente |
| TASK-142 | Testes novos de foto de ciclo fechado (leitura dupla com edição no meio, despesa apagada depois de fotografado, ciclo aberto/futuro ao vivo) | backend | plan.md §3 | nenhum | Pendente |
| TASK-143 | `ExpenseController::update`/`destroy` — bloquear `IN_CASH`/`IN_INSTALLMENTS` de ciclo fechado | backend | plan.md §4 | antes do merge | Pendente |
| TASK-144 | `ExpenseController::stopRecurrence` — bloquear cutoff em ciclo fechado | backend | plan.md §4 | antes do merge | Pendente |
| TASK-145 | Testes de bloqueio de edição/exclusão em ciclo fechado | backend | plan.md §4 | nenhum | Pendente |
| TASK-146 | `GroupSummary.tsx` — destravar navegação para ciclos futuros | frontend | plan.md §5 | antes do merge | Pendente |
| TASK-147 | `GroupSummary.tsx` — exibir `Chip` de status do ciclo no cabeçalho | frontend | plan.md §5 | antes do merge | Pendente |
| TASK-148 | `GroupSummary.test.tsx` — testes novos (navegação futura, chip de status) | frontend | plan.md §5 | nenhum | Pendente |

## Critérios de aceite

- **TASK-134**: `BillingCycle::cycleFor(null, Carbon::parse('2026-08-21'), 0)` devolve `{start: 2026-08-01, end: 2026-08-31, status: 'open'}` (não mais julho). `cyclesAgo=1` devolve julho com `status: 'closed'`. `cyclesAgo=-1` devolve setembro com `status: 'future'`.
- **TASK-135**: `php artisan test --filter=BillingCycleTest` verde, cobrindo ciclo aberto, fechado, futuro e a borda do dia de fechamento.
- **TASK-136**: `BillingCycle::statusFor($closingDay, $data, $referencia)` devolve `'closed'`/`'open'`/`'future'` para uma data qualquer, sem exigir `cyclesAgo`; testado para os 3 casos.
- **TASK-137**: teste que cria despesa `IN_INSTALLMENTS` com 2ª parcela em outro ciclo (fechado ou futuro) e confirma que ela aparece no resumo daquele ciclo com `value = quota.value_quota`.
- **TASK-138**: migration roda limpo local (`php artisan migrate`); `GroupCycleSnapshot::create([...])` persiste e recupera `totals`/`expenses`/`balances` como array (cast json); `unique(group_id, cycle_start)` rejeita duplicata.
- **TASK-139**: `php artisan test --filter=ExpenseControllerSummary` continua 100% verde após a extração (sem mudança de comportamento).
- **TASK-140**: ciclo fechado consultado 2x seguidas com uma despesa editada no meio devolve o mesmo resultado nas duas leituras; ciclo aberto/futuro continua refletindo edições ao vivo; resposta da API mantém o mesmo formato de hoje (`cycle`, `totals`, `expenses`, `balances`), com `cycle.status` novo.
- **TASK-141**: suíte de `ExpenseControllerSummaryTest` verde com os casos novos e ajustados.
- **TASK-142**: suíte nova verde cobrindo os 3 cenários descritos no título.
- **TASK-143**: `PUT`/`DELETE /expenses/{id}` de despesa `IN_CASH`/`IN_INSTALLMENTS` de ciclo fechado retorna `422` e não altera o registro; ciclo aberto/futuro continua permitido.
- **TASK-144**: `POST /expenses/{id}/stop-recurrence` com cutoff em ciclo fechado retorna `422` e não altera `fixed_recurrence_ends_at`; cutoff em ciclo aberto/futuro continua funcionando.
- **TASK-145**: suíte nova verde cobrindo os bloqueios das TASK-143/144, incluindo o caso de despesa `FIXED` continuar editável/apagável mesmo com ciclo fechado já fotografado.
- **TASK-146**: clicar no botão "próximo" em `GroupSummary` chama a API com `cycles_ago` decrescente sem limite (teste ou verificação manual com `cycles_ago: -1`).
- **TASK-147**: `Chip` de status renderizado no cabeçalho, com texto/cor mapeados a partir de `summary.cycle.status` (`'closed'|'open'|'future'`).
- **TASK-148**: `npm test -- GroupSummary` verde, cobrindo os 2 casos novos (navegação futura, chip de status).
