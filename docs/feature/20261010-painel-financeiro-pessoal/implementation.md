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
| TASK-412 | Concluída (branch `backend/20261010-painel-financeiro-pessoal-TASK-412`) | 2026-10-10 | Claude (IA), com aprovação do usuário | Ver §6 | Os 16 testes passaram de primeira; a prova de que testam algo são 4 mutações, cada uma derrubando testes (§6) |

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
