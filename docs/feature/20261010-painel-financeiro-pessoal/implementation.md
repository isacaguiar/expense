# Implementation — Painel Financeiro Pessoal

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261010

---

## 1. Desvios do fluxo padrão

| # | Desvio | Por quê | O que fazer ao encerrar |
|---|---|---|---|
| 1 | O trabalho roda num **git worktree** em `.claude/worktrees/painel` (já ignorado por `.git/info/exclude`), na branch `feature/20261010-painel-financeiro-pessoal` criada de `origin/dev` (`5dc5ac9c80`) | O checkout principal estava na branch `frontend/20261010-filtros-…-TASK-407` com `ExpenseManager.tsx` e o teste dele **modificados e sem commit**. Criar a branch da feature ali levaria essas alterações para ela | `git worktree remove` depois do merge do PR. A pasta `docs/feature/20261010-painel-financeiro-pessoal/` do **checkout principal** é uma cópia anterior e fica desatualizada: a fonte é a do worktree |
| 2 | Banco de teste: container **`mysql-painel-test`**, `mysql:8.0.34`, porta **3308** (a receita usa `mysql-expense-test`, 8.0.17, porta 3307) | Já existia um `mysql-expense-test` parado, que não é desta feature (criado em 08/10, provavelmente da validação do rateio ou reservado à validação dos filtros). Não foi apagado nem reaproveitado. A imagem 8.0.17 não está em cache e a 8.0.34 está | `docker rm -f mysql-painel-test` no fim |
| 3 | `backend/.env.testing` criado **dentro do worktree**, com senha, `APP_KEY` e `JWT_SECRET` descartáveis | Necessário para `php artisan test` (a `phpunit.xml` usa `APP_ENV=testing` e não há `.env`) | O arquivo **não é ignorado pelo git**: nunca entra em commit (`git add` por caminho) e é apagado no fim |
| 4 | `backend/vendor` copiado do checkout principal com `robocopy ... /XD .git` (530 MB, 20.704 arquivos, 58 s) | `composer` não está no PATH. O `vendor` original tem 3,9 GB porque os pacotes trazem `.git` embutido | Nada: `vendor/` é ignorado pelo git |

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-409 | Concluída (commit `5e9c517c24` na branch da feature) | 2026-10-10 | Claude (IA), com aprovação do usuário para iniciar e para commitar | Ver §3 | Mutação derruba só os testes novos: o caso "pendente" do teste existente é mascarado pelo acerto sem comprovante (§3) |
| TASK-410 | Concluída (commit `d6411f26c2`, merge `9542dbeef4` na branch da feature) | 2026-10-10 | Claude (IA), com aprovação do usuário | Ver §4 | A fronteira de 12 ciclos do `focus()` não tinha nenhum teste; só os novos a pegam (§4) |
| TASK-411 | Concluída (commit `8ac9908b89`, merge `682f5ad95c` na branch da feature) | 2026-10-10 | Claude (IA), com aprovação do usuário | Ver §5 | `ExpenseController` não foi tocado; a ausência de deriva em relação a `summary()` é provada por teste de reconciliação (§5) |
| TASK-412 | Concluída (commit `fe37b7150f`, merge `a1af961668` na branch da feature) | 2026-10-10 | Claude (IA), com aprovação do usuário | Ver §6 | Os 16 testes passaram de primeira; a prova de que testam algo são 4 mutações, cada uma derrubando testes (§6) |
| TASK-413 | Concluída (commit `23128fdaba`, merge `bd948dd167` na branch da feature) | 2026-10-10 | Claude (IA), com aprovação do usuário | Ver §7 | Primeira superfície HTTP da feature; segurança provada por 2 mutações (escopo de membro e posição da rota fora do `jwt.auth`) |
| TASK-414 | Concluída (branch `backend/20261010-painel-financeiro-pessoal-TASK-414`) | 2026-10-14 | Claude (IA), com aprovação do usuário; revisão pelo agent `security-reviewer` | Ver §8 | **Aprovado com ressalvas.** Sem IDOR nem vazamento no diff; gerou as TASK-427 a 432 e um achado crítico **fora do diff** (X1, reproduzido) que exige decisão do usuário |
| TASK-427 | Concluída (branch `backend/20261010-painel-financeiro-pessoal-TASK-427`) | 2026-10-21 | Claude (IA), com aprovação do usuário | Ver §9 | Grupo vazio caiu de 56 para 8 consultas e 20 grupos vazios de 1.082 para 122 (2,8 s → ~0,4 s); 5 mutações, cada uma derrubando o teste esperado |

## 3. Evidências da TASK-409

Todos os comandos rodaram em `.claude/worktrees/painel/backend`, contra o MySQL descartável (`php artisan tinker --env=testing` confirmou `3308 127.0.0.1 ex-db` antes de qualquer `migrate:fresh`).

| Verificação | Comando | Resultado |
|---|---|---|
| Banco de teste do zero | `php artisan migrate:fresh --env=testing --force` | Todas as migrations `DONE` |
| **Suíte de base** (antes de mexer em código, excluindo só o arquivo novo) | `php artisan test --no-ansi --filter='^(?!.*CycleSummaryServiceTest)'` | `Tests: 456 passed (2905 assertions)`, 175,31 s |
| **RED** dos testes novos | `php artisan test --no-ansi --filter=CycleSummaryServiceTest` | `Tests: 10 failed (0 assertions)`, todos por `Target class [App\Services\CycleSummaryService] does not exist` (falta a feature, não erro de digitação) |
| Movimentação do código | script descartável (fora do repositório) que extrai cada método pelo marcador, não por número de linha | `service: 383 linhas`; chamadas trocadas: `compute` 6, `entries` 3, `withConfirmations` 3; blocos movidos: compute 163 linhas, withConfirmations 31, entries 114, isFullySettled 37 |
| **GREEN** dos testes novos | `php artisan test --no-ansi --filter=CycleSummaryServiceTest` | `Tests: 10 passed (25 assertions)` |
| Estilo | `php vendor/bin/pint --test` | `PASS ... 167 files` |
| **Suíte inteira** depois da extração | `php artisan test --no-ansi` | `Tests: 466 passed (2930 assertions)`, 103,14 s: exatamente 456 + 10 testes e 2905 + 25 asserções |
| Nenhum teste existente editado | `git status --short` | Só `M app/Http/Controllers/ExpenseController.php`; em `tests/` apenas o arquivo novo `?? tests/Feature/CycleSummaryServiceTest.php` |
| Sentinelas por reflexão e do foco | `php artisan test --no-ansi --filter='CycleIsFullySettledTest\|MaterializeFixedOccurrenceQuotaTest\|FocusCycleTest'` | `Tests: 16 passed (25 assertions)` |
| Controller sem definições movidas e sem construtor | `Grep` por `function (collectCycleEntries\|computeCycleSummary\|attachSettlementConfirmations\|__construct)` em `ExpenseController.php` | Nenhuma ocorrência. Existem `cycleIsFullySettled` (wrapper de 3 linhas, `:1269`) e `cycles()` (`app(CycleSummaryService::class)`, `:1279`) |
| **Fidelidade do movimento** | script descartável compara, linha a linha (contagem, sem indentação), o controller original com controller novo + serviço | Das 1.478 linhas não vazias do original, **24** não reaparecem idênticas, e todas são as renomeações deliberadas: 3 assinaturas, 18 chamadas e 7 comentários que citavam os nomes antigos. Nada mais mudou |
| Diffstat | `git diff --stat` | `ExpenseController.php`: 33 inserções, 355 remoções |
| **Mutação** | trocar `> 0` por `< 0` em `summaryIsFullySettled` e rodar `--filter='CycleIsFullySettledTest\|CycleSummaryServiceTest'` | `2 failed, 13 passed`: caem `summary is not settled while something is pending` e `summary is not settled for a cent still pending`. Reverti a linha exatamente: `15 passed (30 assertions)` e o `git diff --stat` voltou ao mesmo |

**Achado da mutação (cobertura do teste existente).** O critério original previa que a mutação derrubaria também o `ExpenseControllerCycleIsFullySettledTest`. Não derruba: o caso "falso quando há despesa pendente" desse teste usa `paidExpenseBetween` e depois `quotas()->update(['paid' => false])`, então há um acerto sem comprovante e a função devolve `false` por esse segundo motivo, mascarando a pendência. Os testes novos do serviço isolam a pendência (`settlements` vazio) e por isso pegam a mutação. O critério da TASK-409 em `tasks.md` foi corrigido para refletir isso. O teste existente não foi alterado, para cumprir "suíte intacta"; endurecê-lo ficaria como melhoria à parte.

## 4. Evidências da TASK-410

Branch `backend/20261010-painel-financeiro-pessoal-TASK-410`, criada da branch da feature (`a3056c673f`). Mesmo ambiente da TASK-409 (MySQL descartável na 3308).

| Verificação | Comando | Resultado |
|---|---|---|
| Ponto de partida | `php artisan test` na TASK-409 | 466 verdes (2930 asserções) |
| **RED** | `php artisan test --no-ansi --filter=test_focus` | `Tests: 7 failed (0 assertions)`, todos `Call to undefined method App\Services\CycleSummaryService::focus()` |
| **GREEN** + `FocusCycleTest` **sem edição** | `php artisan test --no-ansi --filter='test_focus\|FocusCycleTest'` | `Tests: 15 passed (22 assertions)`: os 7 novos e os 8 do `FocusCycleTest` |
| `FOCUS_LOOKBACK` fora do controller | `Grep FOCUS_LOOKBACK app/` | Só em `app/Services/CycleSummaryService.php` (constante `:32`, docblock e laço). Nenhuma ocorrência em `ExpenseController.php` |
| Controller só delega | leitura de `focusCycle()` | `findOrFail`, `authorizeGroupMembership` e `return response()->json(['cycles_ago' => $this->cycles()->focus($group)]);` |
| **Mutação** | trocar `<=` por `<` no laço de `focus()` e rodar `--filter='test_focus\|FocusCycleTest'` | `Tests: 1 failed, 14 passed`: cai só `focus reaches exactly twelve cycles back`. Linha revertida e conferida |
| **Fidelidade do movimento** | mesmo script de comparação da TASK-409, contra o controller da TASK-409 | Das 1.199 linhas não vazias, 12 não reaparecem idênticas, todas deliberadas: a constante e seu docblock, o comentário do `focusCycle` reflowed, `cycleIsFullySettled($group, $groupId, …)` → `isFullySettled($group, …)`, `return response()->json(['cycles_ago' => $ago])` → `return $ago;`, o `return … 0` final e `where('group_id', $groupId)` → `$group->id` |
| Estilo | `php vendor/bin/pint --test` | `PASS ... 167 files` |
| **Suíte inteira** | `php artisan test --no-ansi` | `Tests: 473 passed (2937 assertions)`: 466 + 7 testes e 2930 + 7 asserções |
| Nenhum teste existente editado | `git status --short` | `M` apenas em `ExpenseController.php`, `CycleSummaryService.php` e `CycleSummaryServiceTest.php` (arquivo criado na TASK-409); `FocusCycleTest.php` intacto |

**Achado (cobertura).** Nenhum teste anterior fixava o limite de varredura: `FocusCycleTest` não tem caso de 12 ou 13 ciclos atrás, então trocar `<=` por `<` no laço passaria despercebido. Os dois testes novos (`focus reaches exactly twelve cycles back` e `focus does not look past twelve cycles back`) fixam a fronteira. As datas foram conferidas contra `BillingCycle::cycleFor` (com `closing_day` nulo e agora em 15/10/2026, 12 ciclos atrás é outubro/2025 e 13 é setembro/2025).

## 5. Evidências da TASK-411

Branch `backend/20261010-painel-financeiro-pessoal-TASK-411`, criada da branch da feature (`9542dbeef4`). Mesmo ambiente (MySQL descartável na 3308).

`CycleSummaryService::view(Group, int $cyclesAgo)` devolve `{cycle{start,end,closes_at,status,settled,in_grace}, summary{totals,expenses,balances,settlements}, sealed}`. É **código novo**, não movimento: espelha a escolha de fonte de `ExpenseController::summary()` (`:673-709`) menos a selagem preguiçosa. `summary()` **não foi refatorado** (plan.md §1.2); a ausência de deriva é provada por teste.

| Verificação | Comando | Resultado |
|---|---|---|
| Ponto de partida | `php artisan test` na TASK-410 | 473 verdes (2937 asserções) |
| **RED** | `php artisan test --no-ansi --filter=test_view` | `Tests: 8 failed`, todos `Call to undefined method App\Services\CycleSummaryService::view()` |
| **GREEN** | mesmo comando | `Tests: 8 passed (49 assertions)` |
| Ramos de fonte e de status | testes `test_view_*` | selado → snapshot (valores `777.0` que o cálculo ao vivo nunca produziria), `closed`, `settled` verdadeiro; fechado por data e não selado → ao vivo, `closed`, `settled` falso; fechamento manual ativo → `closed_manually` (e ao vivo: a despesa de outubro aparece, não o snapshot vazio); fechamento manual reaberto → `open`; vigente → `open`, `in_grace` falso, `closes_at` `2026-11-05`; fronteira passada e ainda `open` (em 02/02, janeiro) → `in_grace` verdadeiro, `closes_at` `2026-02-05` |
| Confirmações nos acertos | idem | `confirmedAt` e `confirmedProofUrl` presentes no ramo do snapshot; `null` no ao vivo sem confirmação |
| **Não grava** | `test_view_does_not_seal_a_settled_cycle_but_the_summary_endpoint_does` | Num ciclo de setembro todo pago e com o acerto confirmado, sem snapshot: depois de `view()` o `GroupCycleSnapshot::count()` e o `Notification::count()` ficam iguais e `settled` é falso. **Controle:** `GET /summary?cycles_ago=1` no mesmo cenário devolve `cycle.settled = true` e o `count()` de snapshots sobe 1: prova a diferença deliberada |
| **Reconciliação** com `summary()` | `test_view_matches_the_summary_endpoint_for_a_closed_pending_cycle` | `cycle.start/end/closes_at/status/settled` iguais (`assertSame`), `totals` iguais e os 2 acertos (de/para/valor/`confirmedAt`) iguais, com um confirmado e outro não |
| **Mutação 1** (critério) | `$status = 'closed_manually'` → `'closed'` em `view()` | `Tests: 1 failed, 7 passed`: cai só `view marks an active manual closing of the current cycle`. Revertida |
| **Mutação 2** (propriedade central) | injetar um `GroupCycleSnapshot::updateOrCreate(...)` no fim de `view()` | `Tests: 1 failed, 7 passed`: cai só `view does not seal a settled cycle but the summary endpoint does`. Linha removida e conferida por `Grep` (nenhum `updateOrCreate` no serviço) |
| Depois de reverter | `--filter='test_view\|test_focus\|CycleIsFullySettledTest'` | `Tests: 20 passed (61 assertions)` |
| Estilo | `php vendor/bin/pint --test` | `PASS ... 167 files` |
| **Suíte inteira** | `php artisan test --no-ansi` | `Tests: 481 passed (2986 assertions)`: 473 + 8 testes e 2937 + 49 asserções |
| Arquivos alterados | `git status --short` | `M app/Services/CycleSummaryService.php` e `M tests/Feature/CycleSummaryServiceTest.php` (teste criado na TASK-409). `ExpenseController.php` e os demais testes intactos |

**Para a próxima task.** `view()` já devolve os acertos com as confirmações e o `in_grace`; a `PersonalOverviewService` (TASK-412) parte de `focus()` + `view()` e acrescenta `cycles_ago`, que o `view()` não carrega de propósito. Os helpers `expenseBetween(…, $date)` e `confirmFor(…)` do teste foram generalizados com parâmetro opcional, sem alterar nenhum teste anterior.

## 6. Evidências da TASK-412

Branch `backend/20261010-painel-financeiro-pessoal-TASK-412`, criada da branch da feature (`682f5ad95c`). Mesmo ambiente (MySQL descartável na 3308).

`App\Services\PersonalOverviewService::forUser(User): array` devolve `{user{id,name}, groups[]}`. Cada grupo: `group`, `cycle` (de `view()` mais `cycles_ago`), `owe`, `receive`, `to_pay`, `to_receive`, `my_items`, `pending_items_count`, `has_open` (a ordem das chaves é a do `plan.md` §1.1 e é fixada por teste). Não grava nada: usa `focus()` e `view()`.

| Verificação | Comando | Resultado |
|---|---|---|
| Ponto de partida | `php artisan test` na TASK-411 | 481 verdes (2986 asserções) |
| **RED** | `php artisan test --no-ansi --filter=PersonalOverviewServiceTest` | `Tests: 16 failed (0 assertions)`, todos `Class "App\Services\PersonalOverviewService" does not exist` |
| **GREEN** | mesmo comando | `Tests: 16 passed (118 assertions)` |
| Devedor | `test_a_debtor_sees_…` e `test_a_confirmed_settlement_…` | Despesa de 100 dividida com o credor, em setembro (`cycles_ago` 1, `closed`): `to_pay` com 1 acerto de 50,00; `owe` `{50, 0, 50}`; `can_confirm` verdadeiro; credor com chaves exatas `id,name,email,pix,avatar_url`. Com a confirmação gravada: `confirmed 50`, `open 0`, `confirmed_at` e `confirmed_proof_url` preenchidos, `has_open` falso e `pending_items_count` 1 (o acerto está confirmado, a despesa segue pendente) |
| Credor | `test_a_creditor_sees_…` | `to_receive` e `receive` espelhados; o devedor sai com as chaves exatas `id,name,avatar_url`, **sem e-mail nem Pix** |
| Dois papéis | `test_owe_and_receive_use_the_net_settlement_…` | Ana pagou 100 e Beto pagou 40, ambas divididas ao meio: um único acerto líquido de 30,00 (`owe.total` 30 para Beto, `receive.total` 30 para Ana, o outro lado zerado) |
| `my_items` | `test_my_items_…`, `test_installment_…`, `test_born_paid_…`, `test_an_expense_the_user_does_not_take_part_in_…` | `role` e `my_share` corretos; devedor **nunca** tem `can_pay`/`can_unpay`; credor tem `can_pay` só na aberta e `can_unpay` só na paga; `installment` `{number 2, total 3}` na parcelada e `null` na À Vista; `bornPaid` fora da lista; despesa de que o usuário não participa fora da lista |
| Snapshot antigo | `test_items_of_an_old_snapshot_…` | Ciclo vigente selado, despesas só com nomes (sem `participantDetails` nem `valuePerPerson`): o id resolve pelos membros e `my_share` vem de valor ÷ participantes (50,00) |
| `can_confirm` por status | `test_can_confirm_…` (2 testes) | Falso no vigente aberto (o acerto existe ao vivo); verdadeiro em `closed_manually` ativo; falso em ciclo selado (`closed`, `settled`) |
| Ordenação e "em dia" | `test_groups_with_something_open_come_first_…` | Grupos Zeta, Beta (com algo em aberto) e Alfa (em dia): ordem `Beta, Zeta, Alfa`; Alfa com totais, listas e `pending_items_count` zerados e `cycles_ago` 0 |
| Escopo | `test_a_deleted_group_…`, `test_a_user_without_groups_…` | Grupo `deleted` e grupo de outra pessoa nunca aparecem; usuário sem grupos recebe `groups: []` |
| **Mutação 1** (critério) | somar `confirmed` sem checar `confirmed_at` | `3 failed, 13 passed` (devedor, credor e ordenação). Revertida |
| **Mutação 2** (critério) | inverter o `role` | `2 failed, 14 passed` (`my_items` e snapshot antigo). Revertida |
| **Mutação 3** (privacidade) | `from_user` passa a levar e-mail e Pix (`withContact: true`) | `1 failed, 15 passed`: cai o teste do credor. Revertida |
| **Mutação 4** | `can_confirm` sem o `! settled` | `1 failed, 15 passed`: cai o teste do ciclo selado. Revertida |
| Reversão conferida | `Grep` por `=> true)`, `withContact: true`, `'debtor' : 'creditor'` e `$cycle['settled'];` no serviço; depois `--filter=PersonalOverviewServiceTest` | Só restam as formas corretas (`withContact: true` apenas no credor, `:75`); `Tests: 16 passed (118 assertions)` |
| Estilo | `php vendor/bin/pint --test` | `PASS ... 169 files` |
| **Suíte inteira** | `php artisan test --no-ansi` | `Tests: 497 passed (3104 assertions)`: 481 + 16 testes e 2986 + 118 asserções |
| Arquivos | `git status --short` | Só os dois arquivos novos (`app/Services/PersonalOverviewService.php`, `tests/Feature/PersonalOverviewServiceTest.php`); nenhum arquivo existente alterado |

**Decisões de implementação a conhecer**
- `has_open` depende só dos **acertos** (`owe.open` ou `receive.open` > 0), nunca das despesas pendentes. Num ciclo vigente aberto, o acerto acumulado ao vivo já conta como "em aberto" (o chip de status do ciclo diz que é prévia); `can_confirm` é o que diferencia o que dá para agir.
- `my_share` é 0 para quem só pagou e não participa do rateio; o item aparece como `creditor`.
- Participante que não casa com nenhum membro do grupo (membro removido) vira `Desconhecido`, em vez de derrubar a visão.
- A ordenação por nome usa `Str::ascii` + `strcasecmp` (sem acento, sem caixa), para "Álvaro" não ir depois de "Bruno".
- O custo é o de `focus()` + `view()` por grupo (típico 1 a 3 cálculos de ciclo); a medição fica para a TASK-423.

## 7. Evidências da TASK-413

Branch `backend/20261010-painel-financeiro-pessoal-TASK-413`, criada da branch da feature (`a1af961668`). Mesmo ambiente (MySQL descartável na 3308).

Entrega: `App\Http\Controllers\OverviewController` (fino: injeta `PersonalOverviewService` e devolve `forUser(auth('api')->user())`) e a rota `GET /api/me/overview` dentro do grupo `jwt.auth`, logo abaixo de `/me` em `routes/api.php`. O diff da rota é só o `use` e uma linha.

| Verificação | Comando | Resultado |
|---|---|---|
| Ponto de partida | `php artisan test` na TASK-412 | 497 verdes (3104 asserções) |
| **RED** | `php artisan test --no-ansi --filter=OverviewControllerTest` | `Tests: 7 failed (7 assertions)`: `Expected response status code [200] but received 404` (5), `[401] but received 404` (1) e a rota ausente do registro (1) |
| **GREEN** | mesmo comando | `Tests: 7 passed (96 assertions)` |
| Autenticação | `test_requires_authentication` | Sem token: 401 |
| Estrutura | `test_returns_the_documented_structure` | 200 com `user{id,name}` e, por grupo, `group`, `cycle` (com `cycles_ago`), `owe`, `receive`, `to_pay[].to_user{id,name,email,pix,avatar_url}`, `my_items[]` (`creditor`, `installment`, `can_pay`, `can_unpay` …), `pending_items_count`, `has_open` (`assertJsonStructure`) |
| Sem grupos | `test_a_user_without_groups_gets_an_empty_list` | 200 e `assertExactJson({user:{…}, groups:[]})` |
| Escopo | `test_never_shows_the_group_of_someone_else` | Com um grupo alheio (outro dono, outros membros, despesa de 500): só o meu grupo vem, o `id` do alheio não aparece em `group.id` e **nada dele vaza no corpo**: nem o nome do grupo, nem o e-mail de quem está nele |
| Sem efeito colateral + controle | `test_reading_the_overview_writes_nothing_even_where_the_summary_endpoint_would_seal` | Setembro todo pago, acerto confirmado, sem snapshot. Depois de `GET /me/overview` como devedor e como credor, `GroupCycleSnapshot::count()` e `Notification::count()` ficam iguais. **Controle:** `GET /summary?cycles_ago=1` no mesmo cenário devolve `cycle.settled = true` e o `count()` sobe 1 (o teste não passa por vazio) |
| Reconciliação com `summary` | `test_matches_the_summary_endpoint_for_the_cycle_it_chose` | Para o `cycles_ago` que o overview escolheu: `cycle.start/end/closes_at/status/settled` iguais (`assertSame`); o acerto do devedor (valor e `confirmedAt`) igual; como credor, os 2 acertos a receber (devedor, valor, confirmado ou não) iguais |
| Middleware real da rota | `php artisan route:list --path=api/me/overview -v --no-ansi` | `GET\|HEAD api/me/overview … OverviewController@show` com `api` e `Tymon\JWTAuth\Http\Middleware\Authenticate` (o alias `jwt.auth` resolvido) |
| Middleware por teste | `test_the_route_sits_behind_the_jwt_auth_middleware` | A rota está registrada e `gatherMiddleware()` contém `jwt.auth` |
| **Mutação 1** (critério) | tirar o `whereHas('members', …)` da consulta de grupos do `PersonalOverviewService` | `2 failed, 21 passed`: caem `never shows the group of someone else` (HTTP) e `a deleted group and a group of someone else never appear` (serviço). Revertida; `git diff --stat` voltou a mostrar só `routes/api.php` |
| **Mutação 2** (segurança da rota) | mover a rota para **fora** do `Route::middleware('jwt.auth')->group` | `2 failed, 5 passed`: caem `requires authentication` e `the route sits behind the jwt auth middleware`. Revertida; `git diff -U1 routes/api.php` mostra só o `use` e a linha dentro do grupo |
| Estilo | `php vendor/bin/pint --test` | `PASS ... 171 files` |
| **Suíte inteira** | `php artisan test --no-ansi` | `Tests: 504 passed (3200 assertions)`: 497 + 7 testes e 3104 + 96 asserções |
| Arquivos | `git status --short` | `M routes/api.php` e dois arquivos novos (`OverviewController.php`, `OverviewControllerTest.php`) |

**Para a TASK-414.** A revisão do agent `security-reviewer` deve cobrir o `OverviewController`, o `PersonalOverviewService` e a rota. Pontos que já sei que ele vai olhar e que estão cobertos por teste: o escopo por membro na consulta (mutação 1), o 401 e o `jwt.auth` (mutação 2), o corpo sem dados de grupo alheio, e o e-mail e o Pix só do credor. Pontos que dependem de decisão e vale ele opinar: (a) `to_user.email` e `to_user.pix` já são expostos a membros pelo `gross-debts`; (b) o endpoint não tem `throttle` próprio (o limiter global de 60/min vale, como nas demais rotas autenticadas); (c) o custo cresce com o número de grupos e a medição é a da TASK-423.

## 8. Evidências da TASK-414 (revisão de segurança)

Branch `backend/20261010-painel-financeiro-pessoal-TASK-414`, criada da branch da feature (`bd948dd167`). Sem mudança de código: a task é a revisão, a suíte e o registro dos achados.

**Execução da revisão.** Agent `security-reviewer`, sobre `OverviewController`, `PersonalOverviewService`, `CycleSummaryService` (o que o endpoint usa), a rota e os dois testes, com sete perguntas explícitas. A primeira tentativa caiu por limite de uso da sessão (HTTP 429, sem relatório); a segunda concluiu (58 chamadas de ferramenta, ~9 min). **O agent não conseguiu rodar os testes** (o container MySQL de teste estava parado: `PDOException 2002`, 48 falhas com 0 asserções), então a suíte desta task foi rodada por mim, depois de reiniciar o `mysql-painel-test`. As mutações das TASK-412 e 413 também não foram reproduzidas por ele; o julgamento dele sobre os testes vem da leitura das asserções.

**Resultado: aprovado com ressalvas.** Nenhum IDOR, vazamento entre grupos, escrita nem log sensível em `GET /api/me/overview`. O `route:list` dele confirma os middlewares `api` e `Tymon\JWTAuth\Http\Middleware\Authenticate`.

### Achados e disposição

| ID | Severidade | Achado | Disposição |
|---|---|---|---|
| S1 | média | Custo por chamada sem teto, linear em grupos × ciclos; com `store` adicionando usuário sem consentimento e sem `destroy`, um atacante pode forçar a vítima a muitos grupos vazios | **Medido por mim (abaixo) e confirmado.** TASK-427 (otimização), TASK-428 (teto, decisão D9) |
| S2 | baixa | O throttle global (60/min) roda antes do `jwt.auth` e provavelmente chaveia por IP; é compartilhado com o polling do sino | TASK-429 (decisão D10) |
| S3 | baixa | Snapshot legado resolve participante por nome (`pluck('id','name')`, o último vence): nomes iguais podem atribuir o item à pessoa errada (só exibição; valores e flags vêm de ids) | TASK-430 |
| S4 | informativo | `auth('api')->user()` nulo vira 500 numa corrida entre middleware e controller (o `jwt.auth` já dá 401 para usuário removido) | TASK-431 |
| S5 | informativo | `cycles_ago` sem teto nas ações `pay`, `unpay` e `settlements/confirm`; um painel aberto na virada do ciclo guarda um `cycles_ago` que passa a apontar outro ciclo | Fora do diff. Backlog (TASK-425): aceitar `cycle_start` absoluto, que o payload já devolve |
| S6 | informativo | E-mail e Pix do credor saem mesmo quando o acerto já está confirmado ou não pode ser confirmado | Decisão D11 (recomendação: manter). Minimização no backlog (TASK-425) |
| S7 | informativo | Lacunas de teste: as asserções de e-mail alheio em `OverviewControllerTest:185-187` não podem falhar (o usuário não entra em nenhum acerto do grupo alheio); faltam ex-membro, credor que saiu, token de usuário removido | TASK-432 (e testes de nome duplicado na TASK-430). Consistente com a minha mutação 1 da TASK-413, que caiu por `assertJsonCount`, não pelas asserções de e-mail |
| **X1** | **crítica (fora do diff)** | `GET /api/groups/{groupId}/members` (`GroupMemberController::index`) **não checa se quem chama é membro** | **Reproduzido por mim (abaixo).** Não corrigido aqui: `CLAUDE.md` e `06-context-backend.md` mandam não corrigir de passagem. Registrado como item de backlog ALTA (TASK-425) e levado ao usuário para decisão imediata |
| X2 | média (fora do diff) | `GroupMemberController::destroy` não existe, embora a rota `DELETE /groups/{groupId}/members/{userId}` exista (já listado na Constitution §2.4); `store` adiciona usuário existente sem consentimento e ele não consegue sair | Backlog ALTA, junto do X1 (TASK-425) |
| X3 | baixa (fora do diff) | `Log::info('User authenticated:', ['user' => auth()->user()])` em `GroupController::store` grava e-mail, Pix e WhatsApp no log | Backlog BAIXA (TASK-425) |

### Respostas aos sete pontos

1. **E-mail e Pix do credor: aceitável.** O credor já é visível a todo membro por `gross-debts`, e `gerarPix` aceita qualquer usuário com grupo em comum. Só vale para grupos do próprio usuário e só o credor de acertos em que ele é o devedor; credor que não é mais membro sai como "Desconhecido" com `email` e `pix` nulos. Ressalvas: S6 e, principalmente, X1.
2. **Throttle: risco real, mas baixo; ação recomendada** (S2, TASK-429), junto da defesa de verdade (S1).
3. **Amplificação: risco real moderado** (S1). Não há N+1 clássico dentro de um grupo (`members`, `payer`, `payers` e `quotas` usam eager load); o custo é linear em grupos × ciclos e o `focus()` faz 1 consulta de snapshot por iteração.
4. **IDOR e escopo: nenhum caminho de vazamento.** O escopo é a própria consulta; `compute` filtra por `group_id`; `my_items` só entra se o usuário é credor ou participante. Ex-membro vaza só um `id` numérico sem importância; colisão de nomes é só de exibição (S3); `avatar_url` é URL assinada de 30 minutos, do mesmo tipo já presente em `summary` e `/members` (ADR-005), e `confirmed_proof_url` só sai para as duas partes do acerto.
5. **Ações: confirmado.** `pay`, `unpay` e `confirmSettlement` não leem `can_*` nem dado de exibição do payload: `findExpenseForMember` dá 404 a não membro, `pay`/`unpay` dão 403 se `auth()->id() !== user_payer_id`, `confirmSettlement` valida `to_user_id` e recalcula o acerto no servidor. `cycles_ago` é validado como inteiro `>= 0` sem teto (S5). A extração para `CycleSummaryService` não removeu checagem alguma.
6. **Log e erro: sem vazamento** nos três arquivos (nenhum `Log::`, nenhum `findOrFail`); erro vira 500 genérico com `APP_DEBUG=false`. Usuário nulo: S4.
7. **Outros riscos: nenhum além dos acima.** Não há entrada do cliente (nem query string) nem SQL cru; `person()` escolhe os campos de `User` explicitamente; o GET não tem efeito colateral.

### Verificações independentes (minhas, porque o agent não rodou código)

| Verificação | Como | Resultado |
|---|---|---|
| **X1 existe** | Leitura de `GroupMemberController.php:17-26` e sonda descartável (um usuário **de fora** chama `GET /api/groups/{id}/members` de um grupo alheio, em `jwt.auth`) | `status=200`. Chaves devolvidas: `id, invited_by, name, email, avatar_url, pix, whatsapp, notify_whatsapp, email_verified_at, role, created_at, updated_at, pivot`. O `email` e o `pix` do dono do grupo vieram no corpo. Basta enumerar ids numéricos. A sonda foi apagada (`git status` só mostra `.env.testing`) |
| X1 é desconhecido | `grep` em `docs/` | O spec de segurança arquivado (`20260817-seguranca-api`) corrigiu `GroupController@show/update/destroy` e o `store` de membros e registra que o `index` "expõe `email` e `pix`" (`20260825-pagamentos-grid-pix/specify.md` §2.3), mas **nunca** aponta a falta da checagem de membro no `index`. Não encontrei menção a ele em `00-constitution.md` (a seção §5.3, citada como lista de achados conhecidos, não tem conteúdo localizável nesta versão do arquivo). Os testes de `GroupMemberControllerTest` cobrem só o `store` (membro adiciona, não membro recebe 404, grupo excluído recebe 404); não há teste do `index` |
| X2 existe | Leitura de `GroupMemberController.php` e `routes/api.php:54-58` | O controller só tem `index`, `store` e `authorizeMembership`; a rota `DELETE /{userId}` aponta para `destroy` |
| **S1 medido** | Sonda descartável com `DB::enableQueryLog()` em `PersonalOverviewService::forUser` (MySQL em Docker local) | 1 grupo vazio: **56 consultas, 159 ms**. 5 grupos vazios: **272, 625 ms**. 20 grupos vazios: **1.082, 2.835 ms**. 1 grupo com pendência recente em setembro: **18 consultas, 129 ms** (o foco para cedo). A estimativa do agent (~54) se confirma. O caso caro é o grupo parado ou sem dados |
| Sonda removida | `git status --short` | Só `?? .env.testing` |
| Estilo | `php vendor/bin/pint --test` | `PASS ... 171 files` |
| **Suíte inteira** | `php artisan test --no-ansi` (depois de `docker start mysql-painel-test`) | `Tests: 504 passed (3200 assertions)`, 52,59 s: o mesmo da TASK-413 (nenhum código mudou) |

### Decisões do usuário depois da revisão (2026-10-21)

| Item | Decisão | Efeito |
|---|---|---|
| D9 | **B, com desenho corrigido** | TASK-428 mantida. Ao responder a dúvida do usuário sobre o que cresce, reli o critério original e achei um erro meu: ordenar por pendência *antes* do corte exigia calcular todos os grupos, então o teto não limitava o custo. O corte passou a ser na consulta, antes de calcular (50 grupos de menor `ex_groups.id`, 51ª linha só para sinalizar `truncated`). Teste de orçamento com 60 grupos prova a posição do corte |
| D10 | **B** (30/min por usuário na rota) | TASK-429 mantida |
| D11 | **A** (manter o contato do credor) | Minimização (S6) fica só no backlog da TASK-425 |
| X1/X2 | **Backlog, tratar depois do Painel** | Sem Triagem agora e sem task nesta feature. Registrados em 2026-10-21 como `docs/backlog/` 082 (X1) e 083 (X2), ambos ALTA. **O dado segue exposto em produção até esse tratamento**, ciente o usuário |

Ressalva à premissa do S1/D9, achada ao escrever o item 083: o reviewer e eu tratamos o abuso como "adicionar a vítima a quantos grupos quiser", mas `GroupController::MAX_GROUPS_CREATED_PER_USER = 3` limita os grupos que **cada conta cria**; levar uma vítima a N grupos exige ~N/3 contas com e-mail verificado. O vetor é mais caro que o descrito, o custo medido não muda, e o teto da D9 segue como defesa em profundidade. O X3 já existe como item 060 e não foi duplicado.

**Próximo passo.** Executar TASK-427 → 432 (backend) e depois o frontend, conforme a ordem de `tasks.md`.

## 9. Evidências da TASK-427 (custo do `GET /me/overview`)

Branch `backend/20261010-painel-financeiro-pessoal-TASK-427`, a partir de `357ffde918`. Suíte antes: 504 testes.

**O que mudou.** Só `CycleSummaryService::focus()` e um helper privado novo, `firstPossibleEntryDate()`: a menor entre o `date_payment` das despesas vivas e o `date_expected` das quotas delas. Ciclo que termina antes dessa data não tem entrada, logo é "quitado" e o laço antigo o pularia: agora nem é calculado. Grupo sem despesa viva devolve 0 sem varrer nada. Os snapshots dos ciclos restantes vêm de **uma** consulta (`whereIn`, chaveada por `cycle_start`; há índice único em `(group_id, cycle_start)`, então é equivalente ao `first()` de antes). Nenhum teste existente foi editado.

**Por que o limite inferior é seguro.** À Vista e Fixa entram pelo `date_payment` (a Fixa projetada só para a frente, nunca antes dele); Parcelada entra pelo vencimento da quota, que numa parcela retroativa pode ser **anterior** ao `date_payment` (por isso o `min` das quotas). Despesa `deleted` não gera entrada, então não conta.

| Verificação | Como | Resultado |
|---|---|---|
| Teste vermelho antes | `php artisan test --filter="test_focus_gives_the_same_answer\|test_focus_does_not_scan"` com o `focus()` antigo | A equivalência passou (a referência é o próprio laço antigo); o "não varre" falhou: **50 consultas** para um grupo vazio |
| Equivalência | `test_focus_gives_the_same_answer_as_the_full_scan_in_every_shape_of_group`: reescreve o laço antigo no teste (`focusByFullScan`) e compara, em 11 formas de grupo, a resposta esperada, a da referência e a do `focus()` | Passou: vazio; só setembro; parcelada que começa meses antes; parcela com vencimento anterior à despesa (retroativa); Fixa com ocorrências congeladas; ciclo selado no meio; despesa excluída; acerto sem confirmação num ciclo antigo; despesa no último e no primeiro dia do ciclo; `closing_day` 15 |
| Não varre | `test_focus_does_not_scan_cycles_for_a_group_with_nothing_to_scan` (grupo vazio e grupo só com despesa excluída) | No máximo 2 consultas (antes: 50) |
| Snapshots numa consulta | `test_focus_reads_the_snapshots_of_all_the_scanned_cycles_in_a_single_query` (grupo com despesa paga em janeiro: 10 ciclos varridos) | Exatamente 1 consulta a `ex_group_cycle_snapshots` |
| Orçamento de consultas | 3 testes em `PersonalOverviewServiceTest`, com `DB::getQueryLog()` | Ver a tabela de medidas abaixo; tetos = medido + 20% |
| Estilo | `php vendor/bin/pint --test` | `PASS ... 171 files` |
| **Suíte inteira** | `php artisan test --no-ansi` | `Tests: 510 passed (3234 assertions)`, 69,08 s (504 + 6 novos) |

**Medidas** (sonda descartável, apagada; MySQL em Docker local, "agora" = 15/10/2026):

| Cenário | Antes (TASK-414) | Depois | Teto no teste |
|---|---|---|---|
| 1 grupo vazio | 56 consultas, 159 ms | **8 consultas, 36 ms** | 10 |
| 5 grupos vazios | 272, 625 ms | 32, 104 ms | — |
| 20 grupos vazios | 1.082, 2.835 ms | **122 consultas, 411 ms** | 147 |
| 50 grupos vazios | (não medido) | 302, 1.021 ms | — |
| 1 grupo com pendência recente | 18, 129 ms | **19** (+1: a consulta da primeira data) | 23 |

Por grupo vazio sobram 6 consultas (a primeira data, o snapshot do ciclo vigente, as três de entradas e a das confirmações) mais 2 da raiz (grupos e membros). O custo por grupo não depende mais da idade do grupo, só do que ele tem: o piso é o `view()` do ciclo escolhido. Com 50 grupos o pior caso fica em ~300 consultas e ~1 s, o que a TASK-428 passa a limitar por construção.

**Mutações** (cada uma revertida em seguida; `git diff` final só tem o código e os testes da task):

| # | Mutação | Resultado |
|---|---|---|
| M1 | Remover o curto-circuito (`$firstEntryDate = Carbon::parse('2000-01-01')`) | Derrubou 3: o "não varre" e os dois orçamentos (1 e 20 grupos vazios) |
| M2 | `<` por `<=` na comparação de datas | Derrubou a equivalência: `Failed asserting that 0 is identical to 2` (despesa no último dia do ciclo) |
| M3 | Ignorar as quotas na primeira data | Derrubou a equivalência: `0 is identical to 4` (parcela retroativa) |
| M4 | Contar despesa `deleted` na primeira data | Derrubou o "não varre": 25 consultas em vez de no máximo 2 |
| M5 | Voltar a uma consulta de snapshot por ciclo | Derrubou o teste de consulta única |

**Efeito colateral a registrar.** O caso comum (grupo com pendência recente) ganhou 1 consulta (18 → 19), porque a primeira data precisa de uma consulta nova. É o preço de tornar barato o caso caro; o teto de 23 protege contra regressão dele.
