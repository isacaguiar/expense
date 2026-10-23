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
| 5 | Numeração de TASK trocada **depois** de executada: 433 → 470 e 449 a 452 → 471 a 474 (decisões N1 a N3, §31 e §35) | Colisão com a TASK-433 já publicada na `dev` e com as 449 a 464 de uma feature local que ainda não está na `dev` | Nada. Os commits e os nomes de branch já criados citam o número antigo; vale o mapa do `tasks.md` |

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
| TASK-428 | Concluída (branch `backend/20261010-painel-financeiro-pessoal-TASK-428`) | 2026-10-21 | Claude (IA), com aprovação do usuário (D9 reconfirmada depois de discutir alternativas, §10) | Ver §10 | Teto de 50 grupos com `truncated`, cortado antes de calcular: 60 grupos custam o mesmo que 50 (302 consultas). 3 testes de contrato da raiz atualizados para o campo novo |
| TASK-429 | Concluída (branch `backend/20261010-painel-financeiro-pessoal-TASK-429`) | 2026-10-21 | Claude (IA), com aprovação do usuário | Ver §11 | Um teste derrubou a premissa do critério: o Laravel executa o throttle **antes** do `jwt.auth`. O limiter passou a ignorar chamadas sem usuário válido. 4 mutações |
| TASK-430 | Concluída (branch `backend/20261010-painel-financeiro-pessoal-TASK-430`) | 2026-10-21 | Claude (IA), com aprovação do usuário | Ver §12 | Nome ambíguo em snapshot antigo não é atribuído a ninguém; o teste do nome único passou sem edição. 3 mutações |
| TASK-431 | Concluída (branch `backend/20261010-painel-financeiro-pessoal-TASK-431`) | 2026-10-21 | Claude (IA), com aprovação do usuário | Ver §13 | `abort_unless($user, 401)` no controller; sem ele, `TypeError` (500). 2 mutações |
| TASK-432 | Concluída (branch `backend/20261010-painel-financeiro-pessoal-TASK-432`) | 2026-10-21 | Claude (IA), com aprovação do usuário | Ver §14 | Só testes: 3 novos e 1 editado. Antes deles, um escopo "membro **ou** participante" passava nos 38 testes do overview |
| TASK-415 | Concluída (branch `frontend/20261010-painel-financeiro-pessoal-TASK-415`) | 2026-10-21 | Claude (IA), com aprovação do usuário | Ver §15 | `Payments.test.tsx` verde sem edição; o teste de reabertura pegou um caso real (reabrir durante a animação de saída reaproveitava o arquivo anterior). 3 mutações. Critério de grep corrigido |
| TASK-416 | Concluída (branch `frontend/20261010-painel-financeiro-pessoal-TASK-416`) | 2026-10-21 | Claude (IA), com aprovação do usuário | Ver §16 | `cycles_ago` virou argumento obrigatório de `confirm`; omiti-lo derruba 2 testes do hook e 2 de `Payments`. 3 mutações |
| TASK-417 | Concluída (branch `frontend/20261010-painel-financeiro-pessoal-TASK-417`) | 2026-10-21 | Claude (IA), com aprovação do usuário | Ver §17 | Tipos do contrato e fixtures tipadas sem cast (renomear um campo no tipo quebra o `tsc` na fixture). `reload` não volta ao spinner. 3 mutações |
| TASK-418 | Concluída (branch `frontend/20261010-painel-financeiro-pessoal-TASK-418`) | 2026-10-21 | Claude (IA), com aprovação do usuário | Ver §18 | Lista só de acompanhamento, sem botão. 3 mutações |
| TASK-419 | Concluída (branch `frontend/20261010-painel-financeiro-pessoal-TASK-419`) | 2026-10-21 | Claude (IA), com aprovação do usuário | Ver §19 | Botões só pelas flags do servidor; recalcular a permissão no cliente derruba o teste de regressão. 3 mutações |
| TASK-420 | Concluída (branch `frontend/20261010-painel-financeiro-pessoal-TASK-420`) | 2026-10-22 | Claude (IA), com aprovação do usuário | Ver §20 | O `cycles_ago` do payload vai nas 3 ações; fixar `0` derruba os 3 testes. `usePaymentActions` e `useConfirmSettlement` sem alteração. Snackbars no cartão (desvio do plano). 4 mutações |
| TASK-421 | Concluída (branch `frontend/20261010-painel-financeiro-pessoal-TASK-421`) | 2026-10-22 | Claude (IA), com aprovação do usuário | Ver §21 | Página `MyPanel` com estados, ordem do servidor, aviso de corte em 50 e recarga depois de cada ação. Acrescentei "Tentar novamente" e o atalho "Ir para Meus Grupos". 5 mutações |
| TASK-422 | Concluída (branch `frontend/20261010-painel-financeiro-pessoal-TASK-422`) | 2026-10-22 | Claude (IA), com aprovação do usuário | Ver §22 | Rota `/painel` e item "Painel" como primeiro dos dois menus. Fim do frontend. Os testes existentes dos layouts passaram sem edição de asserção. 4 mutações |
| TASK-423 | **Concluída** (roteiro executado; sem código novo) | 2026-10-22 e 2026-10-23 | Claude (IA), com aprovação do usuário para iniciar e **confirmação por escrito do usuário em 2026-10-23** | Ver §23, §33 e §34 | 12 itens conferidos no navegador e por HTTP, todos conforme o esperado; 5 achados que o usuário decidiu (o principal: "Já confirmado" não mostrava o que o credor já recebeu, virou a TASK-470). Refeito na branch final (§33). Stack encerrada (§34) |
| TASK-470 (antes TASK-433) | Concluída (branch `frontend/20261010-painel-financeiro-pessoal-TASK-433`) | 2026-10-22 | Claude (IA), com aprovação do usuário ("siga", opção B recomendada no achado 1 da TASK-423) | Ver §24 | Quarto total "Já recebido" (`receive.confirmed`), sem mudança de backend; conferido no navegador em desktop e em 375 px. 2 mutações |
| TASK-471 (antes TASK-449) | Concluída (branch `backend/20261010-painel-financeiro-pessoal-TASK-449`) | 2026-10-22 | Claude (IA), com aprovação do usuário (D12) | Ver §26 | Merge da `dev` (54 commits) com 3 conflitos resolvidos; `paymentNote` e `confirmedNote` portados para o `CycleSummaryService`; backend 562 e frontend 489 testes verdes. Um erro meu corrigido: a `dev` usa os IDs de backlog 082 e 084, e os meus viraram 085 e 086. 5 mutações |
| TASK-472 (antes TASK-450) | Concluída (branch `frontend/20261010-painel-financeiro-pessoal-TASK-450`) | 2026-10-22 | Claude (IA), com aprovação do usuário (D13) | Ver §27 | O Painel usa o `PaymentProofDialog` da `dev` (foto ou descrição); o `ProofUploadDialog` foi removido; o `useConfirmSettlement` manda `descricao`. `MyPanel.test.tsx` passou sem edição. 5 mutações |
| TASK-474 (antes TASK-452) | Concluída (branch `backend/20261010-painel-financeiro-pessoal-TASK-452`; só testes) | 2026-10-22 | Claude (IA), com aprovação do usuário (R3) | Ver §30 | 2 testes que travam a D14: as notas de pagamento não saem no `/me/overview`, ao vivo e pelo snapshot. **A primeira versão era vazia** (a mutação não a derrubou) e foi refeita. 3 mutações |
| TASK-425 | Concluída (branch `docs/20261010-painel-financeiro-pessoal-TASK-425`; só docs) | 2026-10-22 | Claude (IA), com aprovação do usuário ("Siga") | Ver §31 | 9 itens novos no backlog (087 a 095), notas aditivas nos itens 074 e 075 e índice atualizado. Cada afirmação conferida no código; 2 reproduzidas com sonda descartável (403 × 404 do `gerarPix`, `cycles_ago` extremo). 4 correções minhas antes de fechar. **Achado novo: colisão de numeração de TASK com a `dev` (433) e com uma feature local (449 a 452); decisão N1 a N3 do usuário** |
| TASK-473 (antes TASK-451) | Concluída (branch `frontend/20261010-painel-financeiro-pessoal-TASK-451`; sem código novo) | 2026-10-22 | Claude (IA), com aprovação do usuário | Ver §28 | Os 4 fluxos novos conferidos no navegador, com a descrição chegando ao banco (`note` e `payment_note`) e saindo no `/summary`; reconciliação 6 de 6. Um falso alarme meu no script de conferência (`??` não distingue campo nulo de ausente) |
| TASK-426 | **Plano pronto, aplicação pendente do usuário** (branch `docs/20261010-painel-financeiro-pessoal-TASK-426`; só docs) | 2026-10-22 | Claude (IA), com aprovação do usuário ("siga") | Ver §32 | O plan de Relatórios não é versionado (só existe no checkout principal) e o worktree não escreve fora de si. Plan v1.1 preparado no scratchpad, com SHA e a lista de mudanças; falta o usuário copiá-lo. 3 decisões de desenho novas (D-N1 a D-N3) para o gate do plan de Relatórios |
| TASK-475 | Concluída (branch `docs/20261010-painel-financeiro-pessoal-TASK-475`; docs e 5 comentários de teste) | 2026-10-23 | Claude (IA), com aprovação do usuário (decisões N1 a N3) | Ver §35 | Renumeração das minhas tasks que colidem: 433 → 470, 449 a 452 → 471 a 474. Só documentos e comentários; histórico do git e nomes de branch intactos. Um erro meu: a primeira execução do script não protegeu a seção da colisão (acentos lidos como ANSI), pego pela conferência e refeito |

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
| X1/X2 | **Backlog, tratar depois do Painel** | Sem Triagem agora e sem task nesta feature. Registrados em 2026-10-21 como `docs/backlog/` 085 (X1) e 086 (X2), ambos ALTA (nasceram como 082 e 083; renumerados na TASK-471, §26). **O dado segue exposto em produção até esse tratamento**, ciente o usuário |

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

Por grupo vazio sobram 6 consultas (a primeira data, o snapshot do ciclo vigente, as três de entradas e a das confirmações) mais 2 da raiz (grupos e membros). O custo por grupo não depende mais da idade do grupo, só do que ele tem: o piso é o `view()` do ciclo escolhido. ~~Com 50 grupos o pior caso fica em ~300 consultas e ~1 s, o que a TASK-428 passa a limitar por construção.~~ **(Correção na TASK-424, §29: isso vale só para grupos vazios. Com 50 grupos que têm despesa antiga já quitada e não selada, o custo medido é de 2.352 consultas e 7,4 s. O teto da TASK-428 limita o número de grupos, mas não o custo de cada um.)**

**Mutações** (cada uma revertida em seguida; `git diff` final só tem o código e os testes da task):

| # | Mutação | Resultado |
|---|---|---|
| M1 | Remover o curto-circuito (`$firstEntryDate = Carbon::parse('2000-01-01')`) | Derrubou 3: o "não varre" e os dois orçamentos (1 e 20 grupos vazios) |
| M2 | `<` por `<=` na comparação de datas | Derrubou a equivalência: `Failed asserting that 0 is identical to 2` (despesa no último dia do ciclo) |
| M3 | Ignorar as quotas na primeira data | Derrubou a equivalência: `0 is identical to 4` (parcela retroativa) |
| M4 | Contar despesa `deleted` na primeira data | Derrubou o "não varre": 25 consultas em vez de no máximo 2 |
| M5 | Voltar a uma consulta de snapshot por ciclo | Derrubou o teste de consulta única |

**Efeito colateral a registrar.** O caso comum (grupo com pendência recente) ganhou 1 consulta (18 → 19), porque a primeira data precisa de uma consulta nova. É o preço de tornar barato o caso caro; o teto de 23 protege contra regressão dele.

## 10. Evidências da TASK-428 (teto de grupos, decisão D9)

Branch `backend/20261010-painel-financeiro-pessoal-TASK-428`, a partir de `5e63e34fb5`. Suíte antes: 510 testes.

**A decisão, reconfirmada.** Antes de começar, o usuário pediu uma explicação mais simples do motivo do teto e perguntou se não seria melhor carregar os dados por grupo. Comparamos três formas: (A) uma chamada com tudo, (B) lista primeiro e detalhe ao abrir o cartão, (C) uma chamada por grupo em paralelo. O custo está em descobrir se há algo em aberto em cada grupo, o que o painel precisa de todos os grupos para mostrar o alerta e a ordem; os detalhes saem do mesmo cálculo sem consulta extra. A B esconderia o que está em aberto, e a C faria o mesmo trabalho em N chamadas, estourando o throttle de 30/min (D10) com mais de 30 grupos e pesando na rede do celular. O usuário manteve a A com o teto. Se um dia houver necessidade, "carregar mais" pode usar o `truncated` sem mudar o contrato.

**O que mudou.** `PersonalOverviewService::MAX_GROUPS = 50`. A consulta de grupos ganhou `orderBy('id')` e `limit(51)`; só os 50 primeiros são calculados, e a raiz ganhou `truncated` (`true` quando a 51ª linha existe). A ordenação por pendência e nome continua, dentro dos 50. `specify.md` §2.1 e `plan.md` §1.1 atualizados; a TASK-421 ganhou o critério do aviso na tela.

**Testes existentes editados (contrato da raiz, campo aditivo):** `PersonalOverviewServiceTest::test_the_root_identifies_the_user_and_each_group_has_the_documented_shape` (chaves da raiz), `OverviewControllerTest::test_returns_the_documented_structure` (estrutura e `truncated` falso) e `OverviewControllerTest::test_a_user_without_groups_gets_an_empty_list` (`assertExactJson`). Os três documentam o formato exato e por isso mudam com qualquer campo novo.

| Verificação | Como | Resultado |
|---|---|---|
| Vermelho antes | `php artisan test --filter="PersonalOverviewServiceTest\|OverviewControllerTest"` sem a implementação | 7 falhas: os 4 testes novos e os 3 de contrato |
| 50 grupos | `test_fifty_groups_all_come_back_and_are_not_truncated` | 50 grupos, `truncated` falso |
| 51 grupos | `test_the_fifty_first_group_is_cut_and_the_result_is_truncated` | 50 grupos, `truncated` verdadeiro, o de maior id fora e os 50 mais antigos dentro |
| Corte antes do cálculo | `test_the_cut_happens_before_computing_so_extra_groups_cost_nothing` | 50 grupos e 60 grupos custam o mesmo número de consultas (302) |
| Ordem dentro dos 50 | `test_groups_with_something_open_still_come_first_within_the_fifty` | O 50º grupo, com pendência, vem primeiro; o 51º não aparece |
| Estilo | `php vendor/bin/pint --test` | `PASS ... 171 files` |
| **Suíte inteira** | `php artisan test --no-ansi` | `Tests: 514 passed (3248 assertions)`, 75,67 s (510 + 4 novos) |

**Mutações** (cada uma revertida; o `git diff` final do serviço só tem o teto):

| # | Mutação | Resultado |
|---|---|---|
| M1 | `MAX_GROUPS = 51` | Derrubou 3: 51 grupos (`actual size 51 matches expected size 50`), corte antes do cálculo (308 ≠ 302) e ordem dentro dos 50 (`truncated` falso) |
| M2 | Calcular todos e cortar depois (`map(...)->take(50)` sem `limit`) | Derrubou só o de custo: 362 consultas com 60 grupos contra 302 com 50. Os demais passam, e é por isso que o teste de custo existe |
| M3 | `orderByDesc('id')` (ficar com os mais novos) | Derrubou 2: o de 51 grupos (o mais novo apareceu) e o da ordem ("Z cortado" apareceu) |

## 11. Evidências da TASK-429 (throttle por usuário, decisão D10)

Branch `backend/20261010-painel-financeiro-pessoal-TASK-429`, a partir de `55bc72023c`. Suíte antes: 514 testes.

**O que mudou.** `RouteServiceProvider` ganhou o limiter nomeado `overview` (30/min por usuário), e a rota `GET /me/overview` ganhou `->middleware('throttle:overview')`. O S2 da revisão dizia que o limiter global `api` "provavelmente" chaveia por IP: **confirmado**. O guard padrão é `web` (`config/auth.php`) e o grupo `api` não tem sessão, então `$request->user()` é nulo e a chave cai no IP.

**A premissa que o teste derrubou.** O critério pedia o throttle "depois do `jwt.auth`". Declarei assim na rota, e o teste de chamadas sem token, que passava antes da implementação, **passou a falhar**: a 31ª chamada sem token recebia 429 em vez de 401. A ordem efetiva, lida com `app('router')->gatherRouteMiddleware($route)`, é `ThrottleRequests:api` → `ThrottleRequests:overview` → `SubstituteBindings` → `Tymon\JWTAuth\Http\Middleware\Authenticate`. O `middlewarePriority` do Kernel põe `ThrottleRequests` à frente de `SubstituteBindings`, e o middleware do Tymon não está na lista (não implementa `AuthenticatesRequests`), então o throttle sobe para antes da autenticação. Pôr o Tymon na lista mudaria a ordem de **todas** as rotas protegidas, o que está fora do escopo. A correção ficou no próprio limiter: ele resolve o usuário do token (`$request->user('api')`) e devolve `Limit::none()` quando não há usuário válido. Assim, chamada sem token ou com token inválido não gasta o limite de ninguém e recebe o 401 do `jwt.auth`. O critério da TASK-429 em `tasks.md` foi corrigido para o comportamento, e o achado geral (throttle e bindings antes da autenticação em toda rota protegida) foi para a lista da TASK-425 (BAIXA).

**Custo.** Sonda descartável (apagada) com `DB::enableQueryLog()` numa chamada HTTP: 3 consultas com o throttle e 3 sem ele (2 buscas de usuário nos dois casos). O limiter não acrescenta consulta, porque o guard `api` reaproveita o usuário que resolveu.

| Verificação | Como | Resultado |
|---|---|---|
| Vermelho antes | `php artisan test --filter=OverviewControllerTest` sem a implementação | 2 falhas: a rota declara o throttle e a 31ª chamada devolve 429 |
| Rota declara o throttle | `test_the_route_declares_the_per_user_throttle` | `throttle:overview` em `gatherMiddleware()`; `route:list -v` mostra `ThrottleRequests:overview` na rota |
| 31ª chamada | `test_the_31st_call_of_the_same_user_within_a_minute_gets_429` | 30 chamadas 200, a 31ª 429 |
| Outro usuário, mesmo IP | `test_another_user_on_the_same_ip_is_not_affected` | 200 depois de o primeiro estourar |
| Sem token | `test_unauthenticated_calls_do_not_consume_the_overview_limit` | 31 chamadas, todas 401 |
| Token inválido | `test_calls_with_an_invalid_token_do_not_consume_the_overview_limit` | 31 chamadas, todas 401. Separado do anterior: juntos passariam de 60 chamadas do mesmo IP e bateriam no limiter global (foi o que aconteceu na primeira versão, com os dois laços no mesmo teste) |
| Outras rotas | `test_other_routes_are_not_bound_by_the_overview_limit` | 31 chamadas a `GET /api/me`, todas 200 |
| Estilo | `php vendor/bin/pint --test` | `PASS ... 171 files` |
| **Suíte inteira** | `php artisan test --no-ansi` | `Tests: 520 passed (3374 assertions)`, 106,60 s (514 + 6 novos) |

**Mutações** (cada uma revertida; o diff final só tem o limiter, a rota e os testes):

| # | Mutação | Resultado |
|---|---|---|
| M1 | Tirar `->middleware('throttle:overview')` da rota | Derrubou 2: a declaração na rota e a 31ª chamada (200 em vez de 429) |
| M2 | Chavear por IP mesmo com usuário | Derrubou o do outro usuário no mesmo IP (429 em vez de 200) |
| M3 | Sem usuário, contar por IP em vez de `Limit::none()` (o desenho original) | Derrubou os dois de chamadas sem token e com token inválido (429 em vez de 401) |
| M4 | Limite de 31 em vez de 30 (aplicada junto com a M3; as falhas se separam pela causa) | Derrubou a 31ª chamada (200 em vez de 429) |

## 12. Evidências da TASK-430 (nome ambíguo em snapshot antigo, achado S3)

Branch `backend/20261010-painel-financeiro-pessoal-TASK-430`, a partir de `aa6b7db37e`. Suíte antes: 520 testes.

**O que mudou.** Só `PersonalOverviewService::itemsOf()`. Snapshot antigo não tem `participantDetails`, então os participantes são resolvidos pelo nome entre os membros do grupo. Antes, `pluck('id', 'name')` dava o nome repetido ao **último** membro com aquele nome: um homônimo podia receber o item (e a "parte dele") de outra pessoa. Agora o mapa só tem nomes únicos (`groupBy('name')` → só grupos de tamanho 1): um nome repetido não resolve ninguém, e o item não vai para nenhum dos homônimos. O credor não muda, porque é identificado pelo `userPayerId`, não pelo nome.

| Verificação | Como | Resultado |
|---|---|---|
| Vermelho antes | `php artisan test --filter="old_snapshot\|ambiguous_name"` antes da correção | O teste novo falhou (o homônimo recebia o item); o do nome único passou |
| Nome ambíguo | `test_an_ambiguous_name_in_an_old_snapshot_is_attributed_to_nobody` (credor, devedor e um homônimo do devedor no grupo) | Devedor e homônimo sem item; o credor segue vendo o item como `creditor` |
| Nome único, sem edição | `test_items_of_an_old_snapshot_without_participant_details_resolve_the_user_by_name` | Passou sem edição |
| Estilo | `php vendor/bin/pint --test` | `PASS ... 171 files` |
| **Suíte inteira** | `php artisan test --no-ansi` | `Tests: 521 passed (3381 assertions)`, 179,44 s (520 + 1 novo) |

**Mutações** (cada uma revertida):

| # | Mutação | Resultado |
|---|---|---|
| M1 | Voltar a `pluck('id', 'name')` ("o último vence", o critério da task) | Derrubou o teste novo: o homônimo, último a entrar no grupo, recebeu o item |
| M2 | Agrupar sem filtrar ("o primeiro vence") | Derrubou o teste novo: o devedor recebeu o item |
| M3 | Filtro invertido (só nomes repetidos) | Derrubou os dois: o do nome único (`actual size 0 matches expected size 1`) e o novo |

A suíte desta vez levou 179 s, contra 106 s na anterior, sem teste novo pesado. A diferença é do ambiente (MySQL em Docker na mesma máquina), não do código.

## 13. Evidências da TASK-431 (401 sem usuário no controller, achado S4)

Branch `backend/20261010-painel-financeiro-pessoal-TASK-431`, a partir de `81be0c1069`. Suíte antes: 521 testes.

**O que mudou.** `OverviewController::show()` resolve o usuário e faz `abort_unless($user, 401)` antes de chamar o serviço. O `jwt.auth` já barra quem não tem token, mas o controller resolve o usuário de novo pelo guard `api`: se a conta deixa de existir entre o middleware e o controller, `forUser(null)` dava `TypeError` e a resposta seria 500.

| Verificação | Como | Resultado |
|---|---|---|
| Vermelho antes (é também a mutação "remover o `abort_unless`", o critério da task) | `php artisan test --filter=test_the_controller_answers_401` antes da correção | Falhou com `TypeError` |
| 401 sem usuário | `test_the_controller_answers_401_when_there_is_no_user`: chama o controller direto, sem usuário no guard | `HttpException` com status 401 |
| Estilo | `php vendor/bin/pint --test` | `PASS ... 171 files` |
| **Suíte inteira** | `php artisan test --no-ansi` | `Tests: 522 passed (3382 assertions)`, 122,44 s (521 + 1 novo) |

**Mutações** (revertidas):

| # | Mutação | Resultado |
|---|---|---|
| M1 | Sem o `abort_unless` | `TypeError` (a execução "vermelho antes" acima) |
| M2 | `abort_unless($user, 403)` | `Failed asserting that 403 is identical to 401` |

O caso pelo HTTP (token de uma conta removida, do começo ao fim da requisição) é a lacuna (d) da TASK-432.

## 14. Evidências da TASK-432 (lacunas de teste da revisão, achado S7)

Branch `backend/20261010-painel-financeiro-pessoal-TASK-432`, a partir de `edf3755ac7`. Suíte antes: 522 testes. **Nenhum código de produção mudou**: só testes.

**A lacuna, provada antes de escrever os testes.** Troquei o escopo de `forUser` por "membro **ou** participante de alguma despesa do grupo" (`orWhereHas('expenses.payers', …)`) e rodei `PersonalOverviewServiceTest` e `OverviewControllerTest`: **38 de 38 passaram**, inclusive `test_never_shows_the_group_of_someone_else`. Ou seja, nada travava a diferença entre ser membro e só aparecer numa despesa.

| Lacuna | Teste | O que prova |
|---|---|---|
| (a) ex-membro | `PersonalOverviewServiceTest::test_an_ex_member_who_still_appears_in_an_expense_does_not_see_the_group` (novo) | Quem saiu do grupo, mas segue em `payers` de uma despesa, não vê o grupo |
| (b) credor que saiu | `PersonalOverviewServiceTest::test_a_creditor_who_left_the_group_shows_as_unknown_and_without_contact` (novo) | O acerto continua (R$ 50,00), mas `to_user` vem `{id, "Desconhecido", email null, pix null, avatar null}` |
| (c) e-mail alheio | `OverviewControllerTest::test_never_shows_the_group_of_someone_else` (**editado**) | O usuário passou a constar em `payers` da despesa do grupo alheio sem ser membro; o teste agora falha se o escopo aceitar participação |
| (d) conta removida | `OverviewControllerTest::test_a_token_of_a_removed_account_gets_401` (novo) | Token válido e no prazo de uma conta apagada recebe 401 pelo HTTP |
| Estilo | `php vendor/bin/pint --test` | `PASS ... 171 files` |
| **Suíte inteira** | `php artisan test --no-ansi` | `Tests: 525 passed (3387 assertions)`, 79,22 s (522 + 3 novos) |

**Mutações** (uma por proteção, cada uma revertida; `git diff` final só tem os dois arquivos de teste):

| # | Mutação | Resultado |
|---|---|---|
| Ma/Mc | Escopo "membro **ou** participante" em `forUser` | Derrubou (a) (`actual size 2 matches expected size 1`) e (c) (`response count matched the expected 1`). (b) e (d) passaram: protegem outra coisa |
| Mb | `person()` buscar o credor fora dos membros (`?? User::find($userId)`) | Derrubou só (b): nome e contato do credor que saiu apareceram |
| Md1 | Tirar só o `jwt.auth` da rota (`withoutMiddleware`) | (d) **continuou 401**: a checagem da TASK-431 no controller cobre |
| Md2 | Tirar o `jwt.auth` **e** a checagem do controller | Derrubou (d): `500` em vez de `401` |
| Md3 | Manter o `jwt.auth` e tirar só a checagem do controller | (d) continuou 401: o `jwt.auth` cobre sozinho |

As duas camadas da (d) se cobrem: só remover as duas gera o 500. É a defesa em profundidade que a TASK-431 acrescentou; o teste da TASK-431 (controller chamado sem usuário) é o que trava a camada do controller isoladamente.

**Fim do lote de backend da revisão.** TASK-427 a 432 concluídas. As mudanças que tocaram rota, middleware e controller depois da revisão da TASK-414 foram a TASK-429 (`RouteServiceProvider` e `routes/api.php`) e a TASK-431 (`OverviewController`). Elas cabem na revisão final da TASK-424.

## 15. Evidências da TASK-415 (`ProofUploadDialog`)

Branch `frontend/20261010-painel-financeiro-pessoal-TASK-415`, a partir de `2699be6384`. Primeira task de frontend.

**Ambiente.** O worktree não tinha `frontend/node_modules`. Os `package.json` e `package-lock.json` do worktree e do checkout principal têm o mesmo SHA-1 (`889034e9…` e `4035723c…`), então copiei o `node_modules` do checkout principal com `robocopy /E /MT:16` (139 MB, 287 s, código de saída 1 = arquivos copiados sem erro), como foi feito com o `vendor` do backend (§1, desvio 4). `node_modules/` é ignorado pelo git. Node v25.2.0.

**O que mudou.** `src/components/ProofUploadDialog.tsx` (novo): título, texto, rótulo do botão de seleção e ícone por props; `busy` trava "Cancelar" e "Confirmar"; `error` aparece num `Alert`; "Confirmar" só habilita com arquivo e chama `onConfirm(file)`. `Payments.tsx` usa o componente nos dois diálogos (marcar despesa como paga e enviar comprovante do acerto) e perdeu os dois `useState` de arquivo, o `useRef` e os imports de `Dialog*`. O POST do acerto continua em `Payments.tsx` (a extração dele é a TASK-416).

**Um caso que o teste pegou.** A primeira versão confiava em o `Dialog` desmontar o conteúdo ao fechar para zerar o arquivo. O teste de reabertura falhou: reabrir durante a animação de saída reaproveita o conteúdo ainda montado, e o arquivo anterior volta. O componente passou a contar as aberturas e usar a contagem como `key` do formulário (o padrão do React de ajustar estado a partir da renderização anterior, sem efeito). Em `Payments.tsx` antigo o reset era explícito no `open…Dialog`, então o comportamento ficou igual.

**Comportamento preservado de propósito.** Cheguei a fazer o fundo do diálogo não fechar com `busy`, mas o `Payments.tsx` antigo fechava, e esta task é só refatoração: desfeito.

| Verificação | Como | Resultado |
|---|---|---|
| Linha de base | `npx vitest run src/pages/Payments.test.tsx`; `npx tsc --noEmit` | 8 passed; `tsc` sem saída |
| Vermelho antes | `npx vitest run src/components/ProofUploadDialog.test.tsx` sem o componente | Falhou (módulo inexistente) |
| Componente | `ProofUploadDialog.test.tsx` (8 testes): props visíveis; `accept="image/*"` e `capture="environment"`; "Confirmar" desabilitado até escolher e `onConfirm` com o `File`; "Cancelar" chama `onClose`; `Alert` com e sem erro; `busy` trava os dois botões; reabrir começa vazio | 8 passed |
| `Payments` sem edição | `npx vitest run src/pages/Payments.test.tsx src/components/ProofUploadDialog.test.tsx` | 16 passed (8 + 8), `Payments.test.tsx` intocado |
| Critério de grep (corrigido) | `grep -nE '<Dialog\|type="file"' src/pages/Payments.tsx` | Vazio. O grep literal da task (`Selecionar foto\|Selecionar comprovante`) acha as duas strings nas linhas 317 e 338, como props; contradizia o critério "rótulos vêm por props" e foi corrigido em `tasks.md` |
| Tipos | `npx tsc --noEmit` | Sem erro, sem `any` |
| **Suíte inteira** | `npx vitest run --maxWorkers=2` | `Test Files 46 passed`, `Tests 346 passed` (338 + 8 novos), 230,64 s |

**Mutações** (revertidas):

| # | Mutação | Resultado |
|---|---|---|
| M1 | "Confirmar" habilitado sem arquivo (`disabled={busy}`) | Derrubou 4: 2 do componente e 2 de `Payments` (os que conferem o botão desabilitado antes da foto) |
| M2 | Formulário sem a `key` de reabertura | Derrubou "starts empty again every time it is reopened" |
| M3 | "Cancelar" sem `disabled={busy}` (aplicada junto com a M2; falhas separáveis) | Derrubou "disables both buttons while busy" |

**Verificação visual:** não feita nesta task. O diálogo só aparece em `Payments`, que exige login, backend e banco; a marcação é a mesma de antes (mesmos componentes MUI, textos e ícones). A conferência no navegador fica para a validação local da TASK-423, na stack isolada.

## 16. Evidências da TASK-416 (`useConfirmSettlement`)

Branch `frontend/20261010-painel-financeiro-pessoal-TASK-416`, a partir de `4d47205533`. Suíte antes: 346 testes.

**O que mudou.** `src/hooks/useConfirmSettlement.ts` (novo): `useConfirmSettlement(groupId, onSuccess)` devolve `{ submitting, error, clearError, confirm(toUserId, proof, cyclesAgo) }` e faz o `POST /api/groups/{id}/settlements/confirm` multipart com `to_user_id`, `comprovante` e `cycles_ago`, com `Authorization: Bearer`. O `cyclesAgo` é argumento de `confirm`, não do hook: quem chama tem de dizer, a cada envio, qual competência acerta (a classe de bug de 2026-10-06). `Payments.tsx` perdeu o POST inline e dois `useState`, e passa o `cyclesAgo` da tela; a ordem dos efeitos no sucesso (mensagem, fechar o diálogo, recarregar e só então `submitting` falso) ficou a mesma.

| Verificação | Como | Resultado |
|---|---|---|
| Vermelho antes | `npx vitest run src/hooks/useConfirmSettlement.test.tsx` sem o hook | Falhou (módulo inexistente) |
| Hook | `useConfirmSettlement.test.tsx` (7 testes): `FormData` com `to_user_id`, `comprovante` e `cycles_ago` 1 e 2 (`it.each`) e cabeçalho `Bearer`; `submitting` verdadeiro durante a chamada e `onSuccess` no sucesso; mensagem da API no erro; "Falha ao enviar o comprovante." sem ela; `clearError` e limpeza antes de nova tentativa; sem grupo, nada é enviado | 7 passed |
| `Payments` sem edição | `npx vitest run src/pages/Payments.test.tsx src/hooks/useConfirmSettlement.test.tsx` | 15 passed (8 + 7); inclui o teste que fixa `cycles_ago = 1` |
| Sem POST inline | `grep -n "settlements/confirm\|FormData" src/pages/Payments.tsx` | Vazio |
| Tipos | `npx tsc --noEmit` | Sem erro, sem `any` |
| **Suíte inteira** | `npx vitest run --maxWorkers=2` | `Test Files 47 passed`, `Tests 353 passed` (346 + 7 novos), 175,38 s |

**Mutações** (revertidas):

| # | Mutação | Resultado |
|---|---|---|
| M1 | Omitir `cycles_ago` do `FormData` (o critério da task) | Derrubou 4: os 2 do `it.each` do hook e os 2 de `Payments` que conferem `cycles_ago` (`'0'` e `'1'`) |
| M2 | `Payments` passar `0` fixo em vez do `cyclesAgo` da tela | Derrubou o teste de regressão de `Payments` ("sends the cycles_ago of the cycle on screen…") |
| M3 | O hook não limpar o erro ao tentar de novo (aplicada junto com a M2; falhas separáveis) | Derrubou "clears the error on demand and before a new attempt" |

**Verificação visual:** não se aplica (só lógica de envio; a marcação da tela não mudou). O fluxo completo no navegador fica para a TASK-423.

## 17. Evidências da TASK-417 (`types/overview.ts` e `useMyOverview`)

Branch `frontend/20261010-painel-financeiro-pessoal-TASK-417`, a partir de `75d1b75039`. Suíte antes: 353 testes.

**O que mudou.**
- `src/types/overview.ts` (novo): `MyOverview`, `OverviewGroup`, `OverviewCycle`, `OverviewTotals`, `OverviewPayable`, `OverviewReceivable`, `OverviewCreditor`, `OverviewDebtor`, `OverviewItem`, em snake_case, como o `plan.md` §1.1 e o `PersonalOverviewService`. Os nulos seguem o backend: `email`/`pix` do credor nulos quando ele saiu do grupo (TASK-432 b); `creditor.id`/`name` do item nulos só em snapshot antigo; `installment` nulo fora de Parcelada. `status` reaproveita `CycleStatus` de `useGroupCycle`. Inclui o `truncated` da TASK-428.
- `src/types/overview.fixtures.ts` (novo): `overviewGroupFixture()` e `myOverviewFixture()`, tipadas **sem cast**, para os testes das TASK-418 a 421 (o mesmo exemplo do `plan.md` §1.1). Não tem `.test.` no nome, então o Vitest não o roda, e nenhum código de produção o importa.
- `src/hooks/useMyOverview.ts` (novo): `{ data, loading, error, reload }`. `GET /api/me/overview` com `Authorization: Bearer`; 401 → `navigate('/', { replace: true })`, como `useGroupCycleHistory`; outro erro → "Falha ao carregar o painel.". **Escolha de desenho:** `loading` vale só para a primeira carga; um `reload` (depois de cada ação no painel) mantém os cartões na tela até os dados novos chegarem, em vez de piscar o spinner. Resposta de um `reload` mais velho é descartada.

| Verificação | Como | Resultado |
|---|---|---|
| Vermelho antes | `npx vitest run src/hooks/useMyOverview.test.tsx` sem o hook | Falhou (módulo inexistente) |
| Hook | `useMyOverview.test.tsx` (4 testes): URL e cabeçalho `Bearer`, `loading` e `data`; 401 navega para `/` com `replace`; outro erro dá a mensagem; `reload` chama de novo, sem voltar a `loading`, e troca os dados | 4 passed |
| Fixture tipada | `npx tsc --noEmit` | Sem erro, sem cast e sem `any` |
| **Suíte inteira** | `npx vitest run --maxWorkers=2` | `Test Files 48 passed`, `Tests 357 passed` (353 + 4 novos), 167,08 s |

**Mutações** (revertidas):

| # | Mutação | Resultado |
|---|---|---|
| M1 | Renomear `in_grace` para `inGrace` no tipo | `tsc` falhou na fixture: `TS2561 … 'in_grace' does not exist in type 'OverviewCycle'. Did you mean to write 'inGrace'?` |
| M2 | Tirar o desvio do 401 | Derrubou "sends the user back to the login on 401" |
| M3 | `setLoading(true)` a cada carga (aplicada junto com a M2; falhas separáveis) | Derrubou "fetches again on reload and keeps the screen while it does" |

**Verificação visual:** não se aplica (sem tela; a página é a TASK-421).

## 18. Evidências da TASK-418 (`ReceivableList`, "Você recebe")

Branch `frontend/20261010-painel-financeiro-pessoal-TASK-418`, a partir de `4b55224cb6`. Suíte antes: 357 testes.

**O que mudou.** `src/components/ReceivableList.tsx` (novo): uma linha por devedor (`Card` em `Stack`, sem `Table`, como `PayableSettlementList`), com avatar, nome e valor em pt-BR em verde; embaixo, o chip "Comprovante enviado" (verde, link para `confirmed_proof_url` em nova aba, mesmo visual do `PayableSettlementList`) quando há `confirmed_at`, ou "Aguardando pagamento" (laranja) quando não há. Lista vazia: "Ninguém te deve neste ciclo.". Sem botão: quem age é o devedor. Mantém a ordem do servidor. O formatador de dinheiro é local, como em todos os componentes (`src/utils` não tem um compartilhado).

| Verificação | Como | Resultado |
|---|---|---|
| Vermelho antes | `npx vitest run src/components/ReceivableList.test.tsx` sem o componente | Falhou (módulo inexistente) |
| Componente | `ReceivableList.test.tsx` (5 testes): devedor, "R$ 120,00" e link do comprovante com `target="_blank"` e `rel="noreferrer"`; "Aguardando pagamento" e "R$ 1.234,50" sem link; ordem do servidor; lista vazia; nenhum botão | 5 passed |
| Tipos | `npx tsc --noEmit` | Sem erro, sem `any` |
| **Suíte inteira** | `npx vitest run --maxWorkers=2` | `Test Files 49 passed`, `Tests 362 passed` (357 + 5 novos), 191,28 s |

**Mutações** (cada uma revertida; `grep` confirmou `pt-BR`, `_blank` e `!== null` de volta):

| # | Mutação | Resultado |
|---|---|---|
| M1 | Status invertido (`confirmed_at === null`) | Derrubou os 2 testes de status |
| M2 | Link do comprovante sem `target="_blank"` | Derrubou o teste do comprovante (`Expected the element to have attribute`) |
| M3 | Valor em `en-US` | Derrubou os 2 testes de valor ("R$ 120,00" e "R$ 1.234,50" não encontrados) |

**Verificação visual:** ainda não há rota que mostre o componente (a página é a TASK-421 e a rota a TASK-422). A conferência no navegador, inclusive em largura de celular, fica para a TASK-423.

## 19. Evidências da TASK-419 (`MyItemsList`, "Seus itens")

Branch `frontend/20261010-painel-financeiro-pessoal-TASK-419`, a partir de `a1917232a8`. Suíte antes: 362 testes.

**O que mudou.** `src/components/MyItemsList.tsx` (novo): uma linha por despesa do ciclo em foco (`Card` em `Stack`), com descrição, data no formato de `Payments` ("10 de set."), "Parcela n/N" só em Parcelada (num `span` próprio), chip "Paga"/"Pendente" com as cores de `Payments`. O devedor vê "Sua parte R$ …", "Total da despesa R$ …" e "Credor: …"; o credor vê "Você pagou R$ …". "Marcar como paga" (ícone de câmera: a foto é pedida pelo `ProofUploadDialog` do cartão, TASK-420) e "Desfazer" aparecem **só** por `can_pay`/`can_unpay`; o componente só avisa `onPay(expenseId)`/`onUnpay(expenseId)`, e `busyExpenseId` trava os botões do item em andamento. Lista vazia: "Nenhum item seu neste ciclo.".

**Um ajuste que o teste pegou.** Na primeira versão "Parcela 2/6" saía no mesmo nó de texto da data ("10 de set. · Parcela 2/6"), e o teste não a achava como texto próprio. Ficou num `span`.

| Verificação | Como | Resultado |
|---|---|---|
| Vermelho antes | `npx vitest run src/components/MyItemsList.test.tsx` sem o componente | Falhou (módulo inexistente) |
| Componente | `MyItemsList.test.tsx` (9 testes): visão do devedor (parte, total, credor, data); visão do credor ("Você pagou", sem parte nem credor); "Parcela 2/6" só com `installment`; Paga/Pendente; "Marcar como paga" só com `can_pay` → `onPay(51)`; "Desfazer" só com `can_unpay` → `onUnpay(51)`; **regressão:** `role: 'creditor'`, `paid: false`, `can_pay: false` → nenhum botão; `busyExpenseId` trava só o item dele; lista vazia | 9 passed |
| Tipos | `npx tsc --noEmit` | Sem erro, sem `any` |
| **Suíte inteira** | `npx vitest run --maxWorkers=2` | `Test Files 50 passed`, `Tests 371 passed` (362 + 9 novos), 168,52 s |

**Mutações** (revertidas; `grep` confirmou `onPay`/`onUnpay`, `busy` e `can_pay` de volta):

| # | Mutação | Resultado |
|---|---|---|
| M1 | O cliente deduzir a permissão (`can_pay \|\| (role === 'creditor' && !paid)`), o critério da task | Derrubou só o teste de regressão da regra no servidor |
| M2 | "Marcar como paga" chamar `onUnpay` | Derrubou "offers Marcar como paga only when the server says can_pay…" |
| M3 | `busy` sempre falso (aplicada junto com a M2; falhas separáveis) | Derrubou "disables the buttons of the item being saved" |

**Verificação visual:** ainda sem rota (TASK-421/422); fica para a TASK-423.

## 20. Evidências da TASK-420 (`GroupPositionCard`)

Branch `frontend/20261010-painel-financeiro-pessoal-TASK-420`, a partir de `f4f48070ea`. Suíte antes: 371 testes.

**O que mudou.** `src/components/GroupPositionCard.tsx` (novo). Props: `overview: OverviewGroup`, `currentUser: {id, name}`, `onChanged`.
- **Resumo (sempre visível):** nome do grupo (`h2`), datas do ciclo em foco ("01/09 – 30/09"), chip do ciclo (`cycleStatusChip`; em carência, `Fecha em dd/mm` com `closes_at`), os três totais ("A pagar" = `owe.open`, "A receber" = `receive.open`, "Já confirmado" = `owe.confirmed`), o selo "Em dia" quando `has_open` é falso, o alerta quando é verdadeiro (aviso de pagamento em aberto se há `owe.open`, informativo se só há a receber) e os botões "Ver ciclos" (link para `/groups/{id}/payments`) e "Ver detalhes"/"Ocultar detalhes" (`aria-expanded`).
- **Detalhe (`Collapse`, fechado por padrão):** "Você paga" reaproveita `PayableSettlementList` por um adaptador (`to_pay` → `SummarySettlement[]` e `SummaryBalance[]`, com o usuário logado como devedor), `canConfirm = to_pay.some(can_confirm)`; "Você recebe" (`ReceivableList`); "Seus itens" (`MyItemsList`).
- **Ações:** enviar comprovante do acerto (`ProofUploadDialog` + `useConfirmSettlement(group.id, …)`), pagar com Pix (`PixPaymentDialog`), marcar como paga (`ProofUploadDialog` + `usePaymentActions(null, null, cycle.cycles_ago, onChanged).handlePay`) e desfazer (`handleUnpay`). **Em todas, o `cycles_ago` é o do payload.** Depois do sucesso, `onChanged()`.
- Os hooks `usePaymentActions` e `useConfirmSettlement` **não foram alterados** (`git diff --stat` dos dois arquivos vazio).

**Três decisões de desenho que o plano deixava em aberto ou que mudei:**
1. **Snackbars no cartão, não na página.** O plano punha os Snackbars em `MyPanel`, mas o estado de sucesso e erro (`paySuccess`, `unpaySuccess`, `payError`, sucesso do comprovante) vive nos hooks instanciados no cartão. Levá-lo à página exigiria subir cada estado por callbacks. O cartão não é desmontado por um `reload` (as chaves são o id do grupo, e a ordem muda por movimento), então as mensagens sobrevivem. `plan.md` §2 e o critério da TASK-421 foram ajustados; o teste da página as acha do mesmo jeito.
2. **Pix do credor que saiu do grupo.** Sem e-mail (`to_user.email` nulo, "Desconhecido") não há como gerar o Pix, e a mensagem "ainda não cadastrou uma chave Pix" seria falsa. Esse caso mostra "Não foi possível identificar esse credor." (a mesma de `Payments`); a mensagem da chave só vale quando há e-mail e falta `pix`. Teste próprio.
3. **"Você paga" vazio.** O texto de `PayableSettlementList` vazio ("Ninguém deve nada a ninguém neste ciclo.") não serve a uma seção pessoal: o cartão mostra "Você não deve nada neste ciclo." e só usa a lista quando há acertos.

**Um ajuste de teste.** A fixture tem dois links "Comprovante enviado" (um do acerto que o usuário paga, outro do que ele recebe). A primeira asserção usava `getByRole` e falhou por ambiguidade; passou a conferir o `href` esperado entre todos os links.

| Verificação | Como | Resultado |
|---|---|---|
| Vermelho antes | `npx vitest run src/components/GroupPositionCard.test.tsx` sem o componente | Falhou (módulo inexistente) |
| Cartão | `GroupPositionCard.test.tsx` (19 testes), sempre com `cycles_ago: 2`: resumo, datas, 3 totais, chip de carência e normal, "Em dia", alertas (pagar e só receber), link "Ver ciclos", detalhe fechado/aberto, "não deve nada"; comprovante (POST com `to_user_id`, `comprovante`, `cycles_ago` `'2'`, `Bearer`, `onChanged`, snackbar), reenvio, botões escondidos sem `can_confirm`; Pix (diálogo com e-mail, nome, valor e `GET /pix/generate`; credor sem chave; credor sem e-mail); marcar como paga (foto obrigatória, `FormData` com `cycles_ago` `'2'`), desfazer (`{cycles_ago: 2}`), erro sem recarregar; "Você recebe" | 19 passed |
| `usePaymentActions` | `git diff --stat -- src/hooks/usePaymentActions.ts src/hooks/useConfirmSettlement.ts` | Vazio |
| Tipos | `npx tsc --noEmit` | Sem erro, sem `any` |
| **Suíte inteira** | `npx vitest run --maxWorkers=2` | `Test Files 51 passed`, `Tests 390 passed` (371 + 19 novos), 311,64 s (a máquina estava mais lenta que nas tasks anteriores: 167 a 230 s) |

**Mutações** (cada uma revertida; `grep` confirmou `cycle.cycles_ago`, `canConfirm =`, `has_open &&` e a mensagem do Pix de volta):

| # | Mutação | Resultado |
|---|---|---|
| M1 | `cycles_ago` fixo em `0` no comprovante e no `usePaymentActions` (o critério da task) | Derrubou 3: comprovante, marcar como paga e desfazer |
| M2 | `canConfirm = true` (ignorar a flag do servidor) | Derrubou o teste dos botões escondidos sem `can_confirm` |
| M3 | Sem a checagem de chave Pix | Derrubou "does not open the dialog and warns when the creditor has no Pix key" |
| M4 | Selo "Em dia" invertido (aplicada junto com a M3; falhas separáveis) | Derrubou "Em dia" e "warns when there is something to pay…" |

**Verificação visual:** ainda sem rota (TASK-421/422). O cartão em largura de celular e de desktop, conferido contra `assets/images/screen/movel.png` e `desktop.png`, fica para a TASK-423.

## 21. Evidências da TASK-421 (página `MyPanel`)

Branch `frontend/20261010-painel-financeiro-pessoal-TASK-421`, a partir de `3e65273dd2`. Suíte antes: 390 testes.

**O que mudou.** `src/pages/MyPanel.tsx` (nova), dentro de `DespesasThemeScope`, usando `useMyOverview` e uma `GroupPositionCard` por grupo:
- **Carregando:** spinner na primeira carga e na nova tentativa depois de um erro (`data === null && error === null`). Um `reload` com dados na tela **não** volta ao spinner: os cartões ficam, e é isso que mantém vivas as mensagens de sucesso, que moram no cartão (§20).
- **Erro:** `Alert` com a mensagem do hook e o botão "Tentar novamente" (`reload`). Se um `reload` falhar com dados na tela, o erro aparece acima dos cartões e eles ficam.
- **Vazio:** "Você ainda não participa de nenhum grupo." e o botão "Ir para Meus Grupos" (`/meus-grupos`).
- **Lista:** um cartão por grupo, **na ordem que o servidor mandou**, com `currentUser={data.user}` e `onChanged={reload}`.
- **Corte em 50 (TASK-428):** com `truncated: true`, um `Alert` informativo acima dos cartões.

**Dois acréscimos que não estavam nos critérios**, pequenos e de baixo risco: o botão "Tentar novamente" (um erro de rede é passageiro, e sem o botão a pessoa teria de recarregar a página) e o atalho "Ir para Meus Grupos" no estado vazio (é onde se cria ou aceita um grupo). Cada um tem teste.

**Um ajuste de teste.** O teste da recarga olhava o cartão por `getByRole` logo depois da mensagem de sucesso, mas o diálogo de comprovante ainda terminava a animação de saída, e enquanto ele existe o MUI esconde o resto da página das consultas por papel. Passou a `findByRole`, que espera.

| Verificação | Como | Resultado |
|---|---|---|
| Vermelho antes | `npx vitest run src/pages/MyPanel.test.tsx` sem a página | Falhou (módulo inexistente) |
| Página | `MyPanel.test.tsx` (10 testes): spinner na primeira carga; erro e nova tentativa que carrega; vazio com o link; cartões na ordem do servidor e dentro do tema (`DespesasThemeScope` simulado por um marcador); `data.user` como devedor em "Você paga"; aviso com `truncated: true` e sem ele; depois de enviar comprovante, `GET` de novo, cartão na tela, sem spinner e "Comprovante enviado."; depois de marcar como paga, `GET` de novo e "Pagamento confirmado."; ação com erro, sem recarregar | 10 passed |
| Tipos | `npx tsc --noEmit` | Sem erro, sem `any` |
| **Suíte inteira** | `npx vitest run --maxWorkers=2` | `Test Files 52 passed`, `Tests 400 passed` (390 + 10 novos), 255,16 s |

**Mutações** (cada uma revertida; `grep` confirmou `DespesasThemeScope`, `onChanged={reload}`, `data.truncated` e a lista sem `sort`):

| # | Mutação | Resultado |
|---|---|---|
| M1 | Ordenar os grupos por nome no cliente | Derrubou "shows one card per group, in the order the server sent…" |
| M2 | `onChanged` vazio (os cartões não recarregam) | Derrubou os dois testes de recarga depois de uma ação |
| M3 | Página sem `DespesasThemeScope` | Derrubou o teste do tema (marcador não encontrado) |
| M4 | Aviso de 50 grupos sempre renderizado | Derrubou "shows no such warning when the list was not cut" |
| M5 | Esconder os cartões durante a recarga (spinner a cada `reload`) | Derrubou os dois testes de recarga: sem o cartão montado as mensagens de sucesso somem, o que prova que a decisão de mantê-las no cartão (§20) depende de ele não desmontar |

**Verificação visual:** ainda sem rota (TASK-422). Fica para a TASK-423.

## 22. Evidências da TASK-422 (rota `/painel` e item "Painel" nos menus)

Branch `frontend/20261010-painel-financeiro-pessoal-TASK-422`, a partir de `051ae2f68c`. Suíte antes: 400 testes. **Última task de frontend.**

**O que mudou** (diff de produção de 3 arquivos, +7 linhas):
- `App.tsx`: `<Route path="/painel" element={<MyPanel />} />` dentro do bloco do `SimpleShellLayout`, antes de `/meus-grupos`.
- `layouts/simpleNavItems.ts`: `{ label: 'Painel', icon: DashboardOutlinedIcon, to: '/painel' }` como **primeiro** item (o menu de fora de um grupo e o do celular, que reaproveita os mesmos itens).
- `layouts/group/GroupSidebar.tsx` (`groupNavItems`): o mesmo item, também primeiro, para o Painel ser alcançável de dentro de um grupo.
- O título do cabeçalho sai do item ativo (`flatItems.find(item.to === pathname)`), então `/painel` mostra "Painel" sem código extra.

**Conferência dos testes existentes** (o critério pedia verificar `Sidebar.test.tsx`, `MobileNavDrawer.test.tsx` e `App.test.tsx`): `Sidebar.test.tsx` e `MobileNavDrawer.test.tsx` montam o componente com itens próprios, então o item novo não os alcança, e passaram sem edição. As asserções que já existiam em `SimpleShellLayout.test.tsx`, `GroupShellLayout.test.tsx` e `App.test.tsx` passaram **sem edição**: elas procuram os links por nome (`/Home/`, `/Pagamentos/`…), e "Painel" não casa com nenhum. A única mudança num teste existente foi no `beforeEach` de `App.test.tsx`: um ramo para `GET /api/me/overview` **antes** de `/api/me`, porque `url.includes('/api/me')` também casa com `/api/me/overview` e devolveria o usuário no lugar do painel.

| Verificação | Como | Resultado |
|---|---|---|
| Vermelho antes | `npx vitest run src/App.test.tsx src/layouts` com os 5 testes novos e sem a implementação | 5 falharam; os 34 existentes passaram |
| Testes novos | `SimpleShellLayout.test.tsx` (3): "Painel" é o primeiro link e vai a `/painel`; o título do cabeçalho em `/painel` é "Painel"; o menu do celular também oferece o link e navega. `GroupShellLayout.test.tsx` (1): "Painel" é o primeiro link da sidebar do grupo. `App.test.tsx` (1): `/painel` mostra "Você ainda não participa de nenhum grupo.", o título "Painel" e o link no menu | 39 passed (34 + 5) |
| Tipos | `npx tsc --noEmit` | Sem erro |
| **Suíte inteira** | `npx vitest run --maxWorkers=2` | `Test Files 52 passed`, `Tests 405 passed` (400 + 5 novos), 162,99 s |

**Mutações** (cada uma revertida):

| # | Mutação | Resultado |
|---|---|---|
| M1 | Sem a rota `/painel` | Derrubou só o teste de `App` (rota) |
| M2 | "Painel" em segundo lugar no menu simples | Derrubou só "shows Painel as the first item" |
| M3 | Sem "Painel" no menu simples | Derrubou 4: primeiro item, título, menu do celular e `App` |
| M4 | Sem "Painel" no menu do grupo | Derrubou só o teste da sidebar do grupo |

**Um deslize meu, pego pela suíte.** Ao restaurar a M4, a edição de restauração falhou ("String to replace not found"): a mutação tinha tirado só a linha do item e deixado o comentário acima dela, e eu procurei um trecho sem o comentário. Segui direto para a suíte inteira, que acusou o teste do menu do grupo vermelho. Corrigi e conferi o `git diff` dos três arquivos de produção contra o esperado antes de seguir. Lição para as próximas mutações: reler o trecho antes de restaurar.

**Verificação visual:** a rota e o menu agora existem, mas a conferência no navegador (tela, menu, largura de celular) é a TASK-423, na stack isolada, com a confirmação do usuário.

## 23. Validação local da TASK-423 (stack isolada, no navegador)

Feita em 2026-10-22 sobre a branch da feature já integrada (`f26ce6ce71`), com backend e frontend reais, banco real e o navegador do painel do app. **Estado: validado pelo Claude; aguardando a confirmação do usuário.** Sem ela não há push nem PR (TASK-423, último critério).

### Ambiente

| Peça | Como |
|---|---|
| Banco | Banco **`painel_validacao`** criado dentro do container `mysql-painel-test` (3308, o mesmo dos testes PHPUnit, `ex-db`). Desvio da receita (container novo na 3307): reaproveitei o container já existente da feature (§1, desvio 2) e separei por banco, para os dados de validação nunca irem parar no `ex-db` dos testes. O alvo foi conferido antes de migrar (`painel_validacao @ porta 3308`) e todos os scripts recusam rodar em outro banco |
| Backend | `php artisan serve --env=testing --port=8001`, com `DB_DATABASE=painel_validacao` na variável de ambiente (o `.env.testing` não foi alterado). Confirmado pelos dados: o login dos usuários semeados só existe nesse banco |
| Frontend | `VITE_API_BASE_URL=http://localhost:8001 npx vite --port 3001 --strictPort` (`/app/painel`). O CORS por `FRONTEND_URL` já era `http://localhost:3001` |
| Dados | Script descartável fora do repositório. 5 usuários (`isac`, `nadia`, `norma`, `otavio`, `semgrupo`, todos `@painel.test`, senha padrão do `UserFactory`) e os grupos abaixo. "Hoje" = 22/10/2026, sem `Carbon::setTestNow`: setembro está fechado e outubro é o ciclo vigente |

### Cenários semeados (a Isac é membro de todos)

| Grupo | Cenário do critério | Dados |
|---|---|---|
| Residencial | (a) ciclo fechado pendente, devedora | Aluguel 900 (Nádia), Reforma parcelada 6× (parcela 2/6 em setembro, 150) e Internet 120 (a própria Isac pagou) → deve R$ 315,00 à Nádia (300 + 75 − 60) |
| Consórcio | (b) ciclo fechado, credora | Condomínio 800 e Seguro 100 pagos pela Isac → recebe R$ 650,00; a Norma já enviou comprovante de R$ 250,00 |
| Viagem | (c) só o ciclo vigente | Hospedagem em outubro, deve R$ 300,00 (ciclo aberto) |
| Sítio | (d) em dia | Despesa de setembro paga e acerto confirmado |
| Ateliê | (e) carência | `closing_day` 18: o ciclo 19/09–18/10 já passou da fronteira e só fecha em 23/10; deve R$ 100,00 |
| Oficina (acrescentado na validação) | credor sem chave Pix | A Isac deve R$ 40,00 ao Otávio, que não tem Pix |

### Resultados, item a item

| # | Item do critério | Como foi conferido | Resultado |
|---|---|---|---|
| 1 | Totais de cada cartão contra o `GET /summary` do mesmo ciclo | Script HTTP: login, `/me/overview` e, para cada grupo, `/groups/{id}/expenses/summary?cycles_ago=N` com o `cycles_ago` do cartão, somando os acertos da Isac | **6 de 6 iguais** (ciclo, status, "a pagar" e "a receber"), na carga inicial e de novo no estado final. Ordem do servidor respeitada (com algo em aberto primeiro, "Em dia" por último) |
| 2 | Devedor envia comprovante e o status do acerto muda | Residencial no navegador: "Enviar comprovante", imagem anexada, "Confirmar" | Aviso "Comprovante enviado."; o cartão recarregou **sem fechar o detalhe**: "A pagar" R$ 315,00 → R$ 0,00, "Já confirmado" R$ 0,00 → R$ 315,00, selo "Em dia", chip "Comprovante enviado" e botão "Reenviar comprovante". No banco: confirmação do **ciclo 2026-09-01..30** (e não de outubro: o `cycles_ago` do payload chegou), arquivo gravado, 1 notificação `settlement_confirmed` para a credora |
| 3 | Credor marca e desfaz a despesa | Consórcio: "Marcar como paga" no Condomínio, com foto, e depois "Desfazer" | "Pagamento confirmado." e o item virou "Paga" com "Desfazer"; depois do desfazer voltou a "Pendente" (banco: `paga = não`). **Os totais "a receber" não mudaram nas duas ações** (ver o achado 2 abaixo) |
| 4 | Pix abre o diálogo | Residencial: "Pagar com Pix" | Diálogo "Pagar Nádia Lima", "R$ 315,00 via Pix", QR Code e código copia-e-cola com a chave dela. Credor sem chave (Oficina): nenhum diálogo e o aviso "Otávio Braga ainda não cadastrou uma chave Pix." |
| 5 | Ciclo ainda não fechável | Ateliê (carência) e Viagem (vigente) | Chip "Fecha em 23/10" no Ateliê; nos dois, sem botões de Pix e de comprovante e com "Disponível após o fechamento do ciclo." |
| 6 | Largura de 375 px sem scroll horizontal | Viewport 375×812, os 6 cartões com o detalhe aberto | `scrollWidth` = `clientWidth` = 375 (sem overflow). Os botões "Pagar com Pix" e "Enviar comprovante" quebram em duas linhas dentro do cartão |
| 7 | Usuário sem grupos | Saída pela tela e entrada como `semgrupo` | "Você ainda não participa de nenhum grupo." e o atalho "Ir para Meus Grupos" |
| 8 | Sessão expirada volta ao login | Token do `localStorage` trocado por um inválido e recarga de `/painel` | Voltou ao login (`/app`) |
| 9 | Não grava nem notifica pela leitura | Banco depois de todas as leituras | `ex_group_cycle_snapshots`: **0 linhas**. As 5 notificações (`settlement_confirmed` ×2, `expense_paid` ×3) vieram só das ações |
| 10 | Regressão da refatoração das TASK-415 e 416 | Tela de Pagamentos (`/groups/6/payments`): "Enviar comprovante" | Funciona: chip "Comprovante enviado", "Reenviar comprovante", e a confirmação gravada no **ciclo de setembro** (`cycles_ago` do ciclo em foco) |
| 11 | Menu | Menu fora de grupo e dentro do grupo | "Painel" é o primeiro item nos dois e abre `/painel` com o título "Painel" |
| 12 | "Informar pagamento" de `GroupGrossDebtsPanel` inalterado | `git diff --stat 5dc5ac9c80 HEAD -- GroupGrossDebtsPanel.tsx GroupGrossDebtsPanel.test.tsx` | Vazio; o botão segue como era |

### Medição de `/me/overview` com 5 grupos

| Camada | Medida |
|---|---|
| Serviço (`PersonalOverviewService::forUser`, 5 grupos) | **85 consultas SQL** (17 por grupo em média), 281 ms na primeira execução e 247 a 270 ms nas seguintes. (As contagens "170, 255…" da saída vieram de um erro do meu script de medição, que registrava um `DB::listen` novo a cada execução; vale a primeira execução, e as seguintes deram o mesmo número por execução) |
| HTTP, `/api/me` (rota trivial, a base do ambiente) | 400 a 505 ms |
| HTTP, `/api/me/overview` (5 grupos) | 758 a 885 ms; primeira chamada, a frio, 1,27 s. Com 6 grupos: 795 a 897 ms |
| Leitura | O ambiente de desenvolvimento (`artisan serve` num processo só, no Windows, com MySQL em Docker) já gasta ~440 ms numa rota que não faz nada: o `/me/overview` custa cerca de **350 ms a mais** que essa base. Em produção, com PHP-FPM e banco local, a base é bem menor. ~~Com o teto de 50 grupos (D9) o pior caso fica em ~300 consultas~~ **(Correção na TASK-424, §29: ~300 consultas é o teto só para 50 grupos vazios; o pior caso real medido é de 2.352 consultas e 7,4 s)** |

### Achados da validação (para o usuário decidir; nenhum bloqueia)

| # | Achado | Opções | Recomendação |
|---|---|---|---|
| 1 | "Já confirmado" é só `owe.confirmed` (o que a pessoa **deve** e já confirmou). Para quem é credor, o cartão mostra "Já confirmado R$ 0,00" mesmo com R$ 250,00 já confirmados por um devedor (aparece só no detalhe, em "Você recebe"). É o que o `plan.md` §2 definiu | A) manter como está<br>B) acrescentar o total confirmado a receber (o payload já traz `receive.confirmed`), por exemplo um quarto valor "Já recebido"<br>C) somar os dois em "Já confirmado" | **B**: o dado já vem do servidor, a mudança é só de tela, e corrige uma leitura enganosa do credor. Entraria como TASK nova antes do PR |
| 2 | "Marcar como paga" e "Desfazer" **não alteram os totais do cartão**, só o selo Paga/Pendente do item. É a decisão D1 (valor = acerto líquido; "Paga" é informação). O critério da TASK-423 dizia "o total acompanha", o que não vale nessa regra | A) manter a D1<br>B) mostrar também uma contagem de itens pendentes (o payload traz `pending_items_count`) | **A**: é o que a D1 decidiu e é o mesmo comportamento de Pagamentos. Opcional: **B** num segundo momento |
| 3 | Depois de uma ação o cartão pode **trocar de posição** (o Residencial desceu abaixo da Viagem ao virar "Em dia"), porque o servidor reordena. O detalhe aberto acompanha o cartão | A) manter (a ordem do servidor é o contrato)<br>B) congelar a ordem até a próxima carga | **A**: simples e previsível; só vale revisar se incomodar no uso real |
| 4 | Em desenvolvimento o `/me/overview` é chamado duas vezes na carga: é o `StrictMode` do React (a primeira resposta é descartada pelo hook). Não acontece no build de produção | — | Nada a fazer |
| 5 | O "Em dia" e o selo "Ciclo fechado" convivem com itens ainda "Pendente" (o credor ainda não marcou como paga), porque o painel segue o acerto líquido. O ciclo continua em foco até o credor marcar tudo e o ciclo ser selado | — | Consequência da D1; ver o achado 2 |

### Encerramento da stack (a fazer só depois do OK do usuário)

A stack **continua no ar** para o usuário validar: frontend `http://localhost:3001/app/`, backend `http://localhost:8001`, banco `painel_validacao`. Ao receber o OK, ou se o usuário pedir, encerrar: parar os processos que escutam na **8001** (`php`) e na **3001** (`node`, o Vite) e nunca os da 3000/8000; apagar o banco `painel_validacao`; apagar só os arquivos de comprovante referenciados no banco descartável (`comprovantes/1/…png` e `comprovantes/6/…png`, mais o de marcar como paga do Condomínio); conferir as portas livres.

**Ajuste do critério de encerramento:** o `.env.testing` e o container `mysql-painel-test` **não** são removidos agora. A TASK-424 (revisão final) precisa rodar a suíte completa do backend e o `security-reviewer`, e ambos dependem deles. Saem no fim da feature (§1, desvios 2 e 3), junto do worktree.

## 24. Evidências da TASK-470 (antes TASK-433) ("Já recebido" no cartão)

Branch `frontend/20261010-painel-financeiro-pessoal-TASK-433`, a partir de `f65eb61124`. Suíte antes: 405 testes. Nasceu do achado 1 da validação local (§23): o usuário respondeu "siga" à pergunta "faço o 'Já recebido' como TASK nova agora, ou deixo para um backlog?", e tomei isso como aprovação da recomendação (opção B). **O "siga" não foi tomado como a confirmação por escrito de que ele validou localmente**, que continua pendente.

**O que mudou** (só `GroupPositionCard.tsx` e o teste; sem backend, sem contrato novo):
- Quarto total, **"Já recebido"**, com `receive.confirmed`, na ordem "A pagar", "A receber", "Já confirmado", "Já recebido".
- A faixa de totais passou de `flex` com quebra para uma grade (`display: grid`): **2 colunas até `xs`, 4 de `sm` para cima**. Com 4 itens, o `flex` deixaria o quarto sozinho numa segunda linha no celular.
- Anotei no código e no `plan.md` §2 que o valor conta **comprovantes** enviados, não dinheiro conferido (o sistema não tem confirmação de recebimento pelo credor). O rótulo "Já recebido" foi o proposto ao usuário; se preferir "Comprovantes recebidos", é uma troca de texto.

| Verificação | Como | Resultado |
|---|---|---|
| Vermelho antes | `npx vitest run src/components/GroupPositionCard.test.tsx` com os 2 testes novos | Os 2 falharam (`total-received` inexistente); os 19 existentes passaram |
| Testes novos | "Já recebido" mostra `receive.confirmed` (R$ 250,00) enquanto "A receber" mostra só o que falta (R$ 400,00); e fica R$ 0,00 sem comprovantes, separado do "Já confirmado" de quem deve (R$ 100,00) | 21 passed (19 + 2); **nenhuma asserção existente foi editada** |
| Tipos | `npx tsc --noEmit` | Sem erro |
| **Suíte inteira** | `npx vitest run --maxWorkers=2` | `Test Files 52 passed`, `Tests 407 passed` (405 + 2 novos), 175,79 s |
| Desktop, na stack da TASK-423 | `/painel` (Vite com recarga automática) | Consórcio: "A receber R$ 400,00", "Já recebido R$ 250,00" (a Norma já comprovou), quatro totais numa linha |
| 375 px | Viewport 375×812 | 2 colunas, sem overflow horizontal (`scrollWidth` = `clientWidth`); posições: A pagar e A receber em cima, Já confirmado e Já recebido embaixo, todos com 149 px |

**Mutações** (revertidas; reli o trecho antes de restaurar, a lição da TASK-422):

| # | Mutação | Resultado |
|---|---|---|
| M1 | "Já recebido" com `receive.open` | Derrubou os 2 testes novos |
| M2 | "Já recebido" repetindo `owe.confirmed` | Derrubou os 2 testes novos |

## 25. Bloqueio da TASK-424: a `dev` divergiu da base da feature

Achado ao começar a revisão final, antes de rodar qualquer suíte (que seria jogada fora). Nada foi alterado no código; só leitura, `git fetch origin dev` e uma simulação de merge que **não toca a árvore de trabalho**.

| Verificação | Comando | Resultado |
|---|---|---|
| Distância da base | `git fetch origin dev`; `git merge-base HEAD origin/dev`; `git log --oneline 5dc5ac9c80..origin/dev` | A base da feature continua sendo `5dc5ac9c80`; a `dev` tem **54 commits** a mais |
| Conflitos de um merge | `git merge-tree --write-tree --name-only HEAD origin/dev` | **3 arquivos em conflito:** `backend/app/Http/Controllers/ExpenseController.php`, `docs/backlog/README.md` e `frontend/src/pages/Payments.tsx` |
| O que mudou na `dev` | `git diff --stat 5dc5ac9c80 origin/dev -- :/backend :/frontend` | 25 arquivos, +2.642/−132: a feature **`20261021-comprovante-ao-marcar-paga`** (TASK-434 a 448 da `dev`, arquivada) |

**O que essa feature da `dev` fez**, e onde ela pisa na nossa:

| Peça da `dev` | Sobreposição com a feature do Painel |
|---|---|
| `pay()` e `confirmSettlement()` aceitam `descricao` (grava `payment_note` e `note`); `unpay()` limpa a nota; 2 migrações aditivas | Controller sem conflito de texto. **O banco da validação local precisa de `migrate`** depois do merge |
| `summary` passa a expor `paymentNote` e `confirmedNote` (em `collectCycleEntries`, `computeCycleSummary` e `attachSettlementConfirmations`) | **Conflito mecânico:** são os métodos que a TASK-409 moveu para o `CycleSummaryService`. As linhas da `dev` têm de ser portadas para `entries()`, `compute()` e `withConfirmations()`. Os testes que a `dev` escreveu (`ExpenseControllerSummaryTest`, `PaymentNotePersistenceTest`, `SettlementConfirmationControllerTest`) servem de prova, via HTTP |
| Novo `PaymentProofDialog` (foto **ou** descrição; foto obrigatória só no acerto do devedor) substitui os diálogos de comprovante em Pagamentos e em Despesas | **Conflito de decisão:** a TASK-415 trocou esses mesmos diálogos pelo `ProofUploadDialog` (só foto). Passam a existir dois componentes para a mesma coisa, e o Painel usa o nosso |
| `usePaymentActions.handlePay(expenseId, details: PaymentDetails)`, com `{ proof?, note? }` | **Quebra de compilação:** o `GroupPositionCard` chama `handlePay(id, file)` |
| (`useConfirmSettlement` é nosso e não existe na `dev`) | Não manda `descricao`; o acerto do devedor no Painel perderia a descrição que Pagamentos passa a oferecer |
| Regra "foto ou descrição" para o credor marcar como paga | A decisão **D7** do `specify.md` ("foto obrigatória, como em Pagamentos") precisa ser relida: Pagamentos mudou |
| `docs/backlog/README.md`: a `dev` tem o 081; eu tenho 082 e 083 | Conflito de linhas. ~~Conferi que a `dev` **não usa IDs ≥ 082**~~ **(afirmação errada: a `dev` usa 082 e 084; ver §26)** (`git grep` em `origin/dev`) |

**Numeração:** a `dev` consumiu as TASK-434 a 448. As tasks novas desta feature devem começar na **TASK-449** (~~a TASK-433 daqui não colide, mas já ficou para trás do contador global~~ **afirmação errada, corrigida no §31: a `dev` também tem uma TASK-433, da feature `comprovante-ao-marcar-paga`**). **Esses números foram trocados depois (§35):** 433 virou 470 e 449 a 452 viraram 471 a 474.

**Decisões pendentes do usuário** (D12 a D14, abaixo no `specify.md` §5 quando adotadas): ver a tabela proposta na conversa de 2026-10-22.

**Estado da validação local:** a stack (8001, 3001, banco `painel_validacao`) continua no ar sobre o código **anterior** ao merge. Depois de integrar a `dev`, o roteiro da §23 precisa ser repetido no que mudar (pelo menos o envio de comprovante, o marcar como paga e o `migrate`).

## 26. Evidências da TASK-471 (antes TASK-449) (merge da `dev`)

Branch `backend/20261010-painel-financeiro-pessoal-TASK-449`, a partir de `bc84387b3c`. Decisão D12 (merge, não rebase). Antes: backend 525 e frontend 407 testes.

**O merge.** `git fetch origin dev` de novo (continuavam 54 commits) e `git merge origin/dev`: os mesmos 3 conflitos previstos no §25, sem surpresa. Resolução:

| Arquivo | Resolução |
|---|---|
| `backend/app/Http/Controllers/ExpenseController.php` | Duas regiões em conflito, ambas só com métodos que a TASK-409 moveu (`computeCycleSummary` e `attachSettlementConfirmations` numa; `collectCycleEntries` na outra). Removi do marcador inicial ao final, de baixo para cima, por um script que valida os marcadores antes de apagar. O lado da feature estava vazio e a linha `/**` antes de cada marcador abre o docblock do método que **fica**. A diferença do controller contra a versão da feature ficou **só** o que a `dev` mudou em `pay()`, `unpay()` e `confirmSettlement()` (conferido linha a linha) |
| `backend/app/Services/CycleSummaryService.php` (portado) | `paymentNote` nas **três** entradas de `entries()` e no mapa de `compute()`; `confirmedNote` em `withConfirmations()`; `@return` de `entries()` atualizado. Mesmas linhas e comentários da `dev` |
| `frontend/src/pages/Payments.tsx` | A versão da `dev` inteira (`git checkout --theirs`), conforme a D13: ela usa o `PaymentProofDialog`, sem nenhuma referência aos meus `ProofUploadDialog` e `useConfirmSettlement`. O que a TASK-415 e a 416 tinham feito ali deixou de existir, e o `Payments.tsx` já não aparece na diferença contra a `dev` |
| `docs/backlog/README.md` e os 2 itens | Linhas dos dois lados mantidas. **Renumeração (ver abaixo): 082 → 085 e 083 → 086** |
| `frontend/src/components/GroupPositionCard.tsx` | Único ajuste de código fora dos conflitos: `handlePay(id, { proof: file })`, porque a assinatura da `dev` agora é `handlePay(id, details: PaymentDetails)` e o `tsc` acusou exatamente esse ponto. A troca de diálogo é a TASK-472 |

**Um erro meu, corrigido.** No §25 e na resposta ao usuário eu afirmei que "a `dev` não usa os IDs de backlog 082 e 083". Estava errado: a `dev` usa **081, 082 e 084**. A causa foi um pathspec relativo: com o diretório de trabalho em `frontend/`, `git grep … -- docs/backlog` procurava em `frontend/docs/backlog`, e a busca voltava vazia. (O mesmo vício já tinha produzido um `git diff --stat` vazio mais cedo, que eu notei na hora.) O conflito do índice mostrou a verdade. Com a raiz explícita (`-- :/docs/backlog`) a busca acha os 3. Renumerei os meus itens para **085** (X1, `GET /groups/{id}/members` sem checagem de membro) e **086** (X2, entrada sem consentimento e sem como sair), por ser o próximo ID depois do maior usado (084), e ajustei as referências cruzadas entre os dois e as menções no `tasks.md`, no `specify.md` e neste arquivo. O "1.082 consultas" das medições **não é ID** e ficou como estava.

**Migrações da `dev`** (2, aditivas: `payment_note` em `ex_quotas` e `note` em `ex_settlement_confirmations`, colunas `nullable`): conferido o alvo antes de migrar (`ex-db @ porta 3308`) e aplicadas; aplicadas também no `painel_validacao` (alvo conferido), para a stack da validação.

| Verificação | Como | Resultado |
|---|---|---|
| Sem conflito pendente | `git status` filtrando `UU/AA/DU/UD` e `grep` dos marcadores nos 3 arquivos | Vazio e 0 marcadores |
| Sintaxe | `php -l` no controller e no serviço | Sem erro |
| Testes novos | 3 em `CycleSummaryServiceTest`: `paymentNote` nas três formas de entrada (À Vista, parcela do ciclo e Fixa congelada, mais a Fixa só projetada que vem `null`), pagamento sem nota vem `null`, e `confirmedNote` vem da confirmação | Passaram na primeira rodada |
| Testes da `dev`, sem edição | `ExpenseControllerSummaryTest`, `PaymentNotePersistenceTest`, `SettlementConfirmationControllerTest`, `ExpenseControllerPayTest` | Verdes, sem nenhuma edição |
| Estilo | `php vendor/bin/pint --test` | `PASS ... 174 files` |
| **Suíte do backend** | `php artisan test --no-ansi` | `Tests: 562 passed (3511 assertions)`, 70,12 s (525 + os da `dev` + 3 novos) |
| Tipos | `npx tsc --noEmit` | Sem erro (depois do ajuste do `handlePay`) |
| **Suíte do frontend** | `npx vitest run --maxWorkers=2` | `Test Files 54 passed`, `Tests 489 passed`, 184,91 s |
| Só o trabalho da feature | `git diff origin/dev --stat -- :/backend :/frontend` | 31 arquivos, sem nenhuma exclusão; os existentes modificados são `ExpenseController` (métodos movidos), `RouteServiceProvider` e `routes/api.php` (throttle), `App.tsx`, os menus e os testes de layout. `Payments.tsx` igual à `dev` |

**Mutações** (cada uma revertida; releio o trecho antes de restaurar):

| # | Mutação | Resultado |
|---|---|---|
| M1 | Entrada À Vista sem `paymentNote` | Derrubou 3: o meu "every kind of entry" e dois da `dev` (um deles o do snapshot) |
| M2 | Entrada parcelada sem `paymentNote` | Derrubou 2: o meu e o da parcela, da `dev` |
| M3 | Entrada Fixa congelada sem `paymentNote` | Derrubou 2: o meu e o da Fixa materializada, da `dev` |
| M4 | O mapa de `compute()` sem `paymentNote` | Derrubou 5, as três formas de entrada e o do snapshot |
| M5 | `withConfirmations` sem `confirmedNote` | Derrubou 3: o meu, o do snapshot e o do `summary` (`SettlementConfirmationControllerTest`/`ExpenseControllerSummaryTest`) |

**Estado da validação local:** a stack (8001/3001/`painel_validacao`) segue no ar e já tem as migrações, então o backend dela serve a coluna nova; o Vite recarregou o frontend integrado. O que falta repetir no navegador é da TASK-473, depois da TASK-472.

## 27. Evidências da TASK-472 (antes TASK-450) (o Painel adota o `PaymentProofDialog`)

Branch `frontend/20261010-painel-financeiro-pessoal-TASK-450`, a partir de `eb9ed3c40f`. Decisão D13 (e D14: a descrição é só enviada, não exibida). Suíte antes: 489 testes.

**O que mudou**
- `GroupPositionCard.tsx`: os dois `ProofUploadDialog` viraram `PaymentProofDialog`, com os mesmos textos e regras do `Payments.tsx` da `dev`. **Acerto do devedor:** `requirePhoto` (o servidor exige `comprovante`; a descrição é complemento) e `confirmSettlement(id, file, cycles_ago, note)`. **Credor marcando como paga:** `requirePhoto={false}`, foto **ou** descrição, e `handlePay(id, file ? { proof: file, note } : { note })`. O `cycles_ago` do payload continua nas três ações. Os dois imports de ícone que ficaram sem uso saíram.
- `useConfirmSettlement.ts`: `confirm(toUserId, proof, cyclesAgo, note?)`; manda `descricao` só quando há texto (o mesmo cuidado de `Payments.tsx`).
- **Removidos:** `ProofUploadDialog.tsx` e o teste dele (8 testes). `grep` por `ProofUploadDialog` em `src/` voltou vazio.
- **Não alterados:** `usePaymentActions.ts` e `PaymentProofDialog.tsx` (são da `dev`), e `MyPanel.test.tsx`.

**Testes**
- `useConfirmSettlement.test.tsx` (+3): `descricao` vai com o texto; não vai quando o texto é `undefined` nem `''`.
- `GroupPositionCard.test.tsx` (+4 e 1 adaptado): acerto do devedor com só a descrição mantém "Confirmar" desabilitado; acerto com foto e descrição manda `descricao` (com `trim`); credor marca como paga **só com descrição** (corpo JSON `{ cycles_ago: 2, descricao }`, sem foto) e **com foto e descrição** (FormData com as duas); o teste "foto antes de marcar como paga" ganhou o texto do diálogo novo e passou a conferir que `descricao` **não** vai quando só há foto. **Única asserção antiga editada:** o texto "Anexe uma foto do comprovante para confirmar o pagamento." virou "Anexe uma foto do comprovante ou descreva o pagamento.", porque é o texto do diálogo novo.

| Verificação | Como | Resultado |
|---|---|---|
| Vermelho antes | Hook: `npx vitest run src/hooks/useConfirmSettlement.test.tsx` sem o parâmetro; Cartão: os 5 testes novos/adaptados com o diálogo antigo | Hook: 1 falha e o `tsc` acusou o 4º argumento. Cartão: 5 falhas (campo "Descrição" inexistente e texto antigo); os demais passaram |
| Hook | `useConfirmSettlement.test.tsx` | 10 passed |
| Cartão e página | `GroupPositionCard.test.tsx` e `MyPanel.test.tsx` | 35 passed, `MyPanel.test.tsx` sem edição |
| Tipos | `npx tsc --noEmit` | Sem erro, sem `any` |
| **Suíte inteira** | `npx vitest run --maxWorkers=2` | `Test Files 53 passed`, `Tests 488 passed` (489 − 8 do diálogo removido + 7 novos), 212,63 s |

**Mutações** (cada uma revertida; reli o trecho antes de restaurar):

| # | Mutação | Resultado |
|---|---|---|
| M1 | `cycles_ago` fixo em `0` no `usePaymentActions` e no comprovante (o critério da task) | Derrubou 6: comprovante, as duas variantes com descrição, marcar com foto, marcar com foto e descrição, e desfazer |
| M2 | O acerto sem passar a `note` | Derrubou só "sends the description with the photo of the settlement as descricao" |
| M3 | O credor perde a descrição quando manda foto (`{ proof: file }`) | Derrubou só "marks as paid with a photo and a description together" |
| M4 | `requirePhoto={false}` no acerto do devedor | Derrubou só "keeps Confirmar disabled with only a description, because the settlement needs the photo" |
| M5 | `requirePhoto` no credor | Derrubou só "marks as paid with only a description, no photo…" |

**Verificação visual:** a tela mudou (agora há o campo "Descrição" nos dois diálogos do Painel). Conferência no navegador e envio real da descrição ao banco (`note` e `payment_note`) são da TASK-473.

## 28. Validação local da TASK-473 (antes TASK-451) (depois do merge da `dev` e da TASK-472 (antes TASK-450))

Branch `frontend/20261010-painel-financeiro-pessoal-TASK-451` (**só documentação**), sobre a branch da feature em `0d267e3bc9`, na mesma stack da §23 (frontend 3001, backend 8001, banco `painel_validacao`, com as 2 migrações da `dev` aplicadas). Código integrado: o Vite recarregou o frontend e o backend serviu o `CycleSummaryService` portado.

| # | Item | Como foi conferido | Resultado |
|---|---|---|---|
| 1 | Acerto do devedor com descrição (Painel, Residencial, "Reenviar comprovante") | Navegador: só com a descrição, depois com foto e descrição | Só com o texto, **"Confirmar" fica desabilitado** (foto obrigatória; contador 33/500). Com foto e texto habilitou. Aviso "Comprovante enviado.". Banco: confirmação do **ciclo de setembro** com `note='Pix feito pelo app do banco às 9h'` e **arquivo trocado** (nome novo) |
| 2 | Credor marca como paga **só com descrição** (Painel, Consórcio, Condomínio) | Navegador, sem nenhum arquivo escolhido (`files.length` = 0) | O diálogo diz "Anexe uma foto do comprovante ou descreva o pagamento." e abre desabilitado; só com o texto **habilitou**. Banco: `paga=sim`, sem foto, `nota='Recebido em dinheiro, sem foto'` |
| 3 | Desfazer limpa a nota | "Desfazer" no mesmo item | Item voltou a "Pendente"; banco: `paga=não`, `nota=NULL` |
| 4 | Credor marca **só com foto** | Foto sem texto | `paga=sim`, arquivo gravado, `nota=NULL` |
| 5 | Credor marca com **foto e descrição** | Desfazer e marcar de novo com os dois | Banco: arquivo gravado e `nota='Pix do condomínio com foto'` |
| 6 | O contrato do `/summary` expõe as notas | Script HTTP na API real | Condomínio: `paymentNote='Pix do condomínio com foto'` e URL do comprovante; acerto 1→2 (Residencial): `confirmedNote='Pix feito pelo app do banco às 9h'`. Sem nota, o campo vem `null` e **presente** |
| 7 | O mesmo acerto pela tela de Pagamentos da `dev` | `/groups/6/payments` (aberta pelo "Ver ciclos" do Painel): "Reenviar comprovante" com foto e descrição | Banco: `note='Reenviado pela tela de Pagamentos'` e arquivo trocado, no ciclo de setembro |
| 8 | Reconciliação com o `/summary` | Script HTTP, `cycles_ago` do cartão | **6 de 6 iguais**, de novo, com o código integrado |

**Um falso alarme meu.** A primeira rodada do item 6 mostrou "AUSENTE" em dois campos (a nota do Seguro e a do acerto 3→2). Era o meu script: `$e['paymentNote'] ?? 'AUSENTE'` também dispara quando o valor é `null`, então não distingue campo nulo de campo ausente. Troquei por `array_key_exists`, e a segunda rodada mostrou os campos **presentes** com `NULL`. Antes de concluir qualquer coisa, refiz a conferência em vez de supor um defeito do produto ou aceitar o resultado.

**Sessão.** O JWT desta stack dura 60 minutos, e a pausa por limite de uso passou disso: a primeira recarga caiu no login com 401 (o mesmo redirecionamento que a §23 validou), e outra tentativa logo depois do login também voltou ao login. Entrei de novo e naveguei pelo menu, como na primeira validação, e daí em diante tudo funcionou. Os 47 erros de console do buffer são todos `401 (Unauthorized)` dessas duas situações e do teste deliberado de sessão expirada da §23; **nenhum veio dos fluxos acima**.

**Estado da stack:** continua no ar para o usuário. Dados deixados: Condomínio pago com foto e descrição; Residencial e Oficina com comprovante e descrição; Seguro pago; o resto como na §23.

**Pendências do §23/§25 que continuam abertas:** a confirmação por escrito do usuário de que validou localmente (sem ela não há push nem PR) e o encerramento da stack depois dela. **Arquivos de comprovante desta validação** a apagar no encerramento, em `backend/storage/app/comprovantes/`: os criados hoje nas pastas `1`, `2`, `4` e `6` (a pasta é compartilhada com a suíte PHPUnit, que usa ids altos; apagar por data de criação e só nessas pastas, nunca varrer a raiz).

## 29. Evidências da TASK-424 (revisão final)

Sobre a branch da feature integrada com a `dev` (HEAD `b0c3482db8`; `git fetch origin dev` imediatamente antes: **0 commits atrás, 53 à frente**). Dois agentes em paralelo (`pr-readiness-checker` e `security-reviewer`) e a suíte do frontend, que rodei eu. Os relatórios dos agentes são saída de modelo: o que vem deles está marcado como tal, e o que reproduzi eu mesmo está marcado como medido.

### Checklist do `04-implementation.md` §1 itens 3 e 5

| Item | Quem rodou | Resultado literal |
|---|---|---|
| `./vendor/bin/pint --test` | `pr-readiness-checker` | `PASS ... 174 files`, exit 0 |
| `php artisan test` | `pr-readiness-checker` | `Tests: 562 passed (3511 assertions)`, 134,20 s, exit 0. Igual ao §26 |
| `npx tsc --noEmit` | `pr-readiness-checker` e eu | Sem saída, exit 0 nas duas |
| `npx vitest run --maxWorkers=2` | eu | `Test Files 53 passed`, `Tests 488 passed`, 401,72 s |
| Migrações no diff contra `origin/dev` | `pr-readiness-checker` | Saída vazia (as 2 migrações de `payment_note`/`note` já estão na `dev`) |
| Segredos no diff | `pr-readiness-checker` | Nenhum. Só `token-de-teste`, `token-invalido` e e-mails `@example.test` nos testes; nenhum `.env*` em nenhum dos 53 commits |
| `backend/.env.testing` | `pr-readiness-checker` | Fora do diff e untracked, **mas não ignorado** pelo `.gitignore`: um `git add -A` o incluiria. Só se faz `git add <caminho>` |
| Escopo, `git diff origin/dev --stat -- :/backend :/frontend` | `pr-readiness-checker` | 29 arquivos, sem exclusões; 10 existentes modificados, todos do tema: `ExpenseController` (métodos movidos), `RouteServiceProvider` e `routes/api.php` (throttle), `App.tsx`, os dois menus e os 3 testes de layout. `Payments.tsx`, `usePaymentActions.ts` e `PaymentProofDialog.tsx` idênticos à `dev` |
| `backend/tests` | `pr-readiness-checker` | 3 arquivos, os 3 novos contra a `dev`; nenhum teste da `dev` editado |
| Log do `implementation.md` | `pr-readiness-checker` | Toda task de 409 a 451 (menos 424, 425 e 426) tem linha e seção com comando e resultado |
| Sem `any`, `@ts-ignore`, `console.log`, `dd(`, TODO nas linhas adicionadas | `pr-readiness-checker` | Nenhum |

Veredito do `pr-readiness-checker`: tecnicamente verde, **mas não pronto para push e PR**, por 3 pendências que não são de código: a confirmação por escrito do usuário (TASK-423), as TASK-425 e 426 ainda não feitas (a descrição do PR deve dizer isso) e o registro desta revisão.

### Revisão de segurança (`security-reviewer`, do que mudou depois da TASK-414)

**Veredito dele: aprovado com ressalvas. Nenhum achado alto ou médio.** Sem IDOR, vazamento entre grupos, escrita nem log sensível em `GET /api/me/overview`. Pontos que ele examinou e confirmou (por leitura, não reexecutados por mim, salvo onde indicado): o escopo é a própria consulta; o limiter `overview` é chaveado só pelo id do usuário do token, não é contornável nem esgota o limite de outro usuário, e `Limit::none()` sem token válido não abre canal novo (o limiter global por IP continua valendo); as notas (`paymentNote`, `confirmedNote`) **não** chegam ao overview nem a log ou `Notifier`; a porta de `paymentNote`/`confirmedNote` para o `CycleSummaryService` é idêntica ao código da `dev` (`diff -w`: só difere a chamada de `entries()`); a leitura não grava nada (a §23 já tinha medido 0 snapshots).

| ID | Sev. | Achado | Minha verificação / decisão |
|---|---|---|---|
| **R1** | baixa | O orçamento de consultas só cobria grupo vazio e grupo com pendência recente. O `focus()` só pula ciclos **anteriores à primeira entrada**; um grupo com despesa antiga já quitada e nunca selada tem até 12 ciclos fechados, quitados e não selados, e cada um passa pelo `compute()`. O "~300 consultas no pior caso" do §9 e do §23 valia **só para grupos vazios**. Estimativa dele: 1.800 a 7.000 consultas com 50 grupos, **sem medir** | **Confirmado por medida minha** (sonda descartável, apagada; banco de testes, "agora" = 15/10/2026): 1 grupo = **49 consultas, 473 ms**; 10 grupos = **472, 1.656 ms**; 50 grupos = **2.352 consultas, 7.390 ms**. Meu número estava errado e foi corrigido no §9, no §23 e no `plan.md` §8. **Decisão pendente do usuário** (abaixo) |
| R2 | baixa | O teto de 50 pelos de **menor id** pode empurrar para fora o grupo real de uma vítima que um atacante tenha adicionado a ≥50 grupos mais antigos (~17 contas verificadas, por causa do limite de 3 grupos criados por conta). O efeito é só o grupo sumir do Painel, com `truncated: true`; nenhum dado é exposto | Aceito: é a D9. O vetor se fecha tratando o item 086 (entrada sem consentimento) |
| R3 | informativo | **Nada trava a D14** (notas fora do overview): hoje é seguro porque os arrays têm chaves explícitas, mas nenhum teste falha se alguém passar `$settlement` ou `$expense` inteiros adiante. Um `grep` de `paymentNote`, `confirmedNote` e `payment_note` nos testes do overview dá vazio | **Decisão pendente do usuário**: um teste pequeno (semear `payment_note` e `note` com um texto marcador e afirmar que o corpo bruto do `/me/overview` não o contém) |
| R4 | informativo | `OverviewController` usa `auth('api')->user()` e o resto do app `auth()->id()`; mesma identidade, e dá o 401 limpo da TASK-431 | Nenhuma ação (desvio de convenção registrado) |
| X1, X2, X3 | (já registradas) | `GroupMemberController::index` sem checagem de membro; `store` sem consentimento e `destroy` inexistente; `Log::info` do usuário inteiro | **Inalterados**: itens 085, 086 e 060 do backlog. Sem novidade |

**Observação do `security-reviewer`, fora do diff e sem ID:** `GroupController::destroy` exige só ser **membro** para apagar fisicamente o grupo (sem ciclo fechado), em cascata, e a Constitution §6.0 diz "nunca DELETE físico sem o gate humano". Achou a decisão da exclusão física numa feature antiga, mas nenhuma decisão sobre "qualquer membro". É também a rota de escape de quem foi adicionado sem consentimento (086). Não é desta feature; vai para a lista da TASK-425.

### O que continua em aberto

1. **Confirmação por escrito do usuário** de que validou localmente (último critério da TASK-423). Sem ela, não há push nem PR.
2. ~~**R1:** aceitar, corrigir ou reduzir. **R3:** fazer o teste agora ou deixar.~~ **Decidido pelo usuário em 2026-10-22:** **R1 aceito**, com o número real documentado e a correção de verdade registrada no backlog (lista da TASK-425); **R3 feito** como TASK-474 (§30).
3. **TASK-425** (backlog, com os itens novos acima) e **TASK-426** (plano de Relatórios).
4. **Encerramento da stack de validação** depois da confirmação do item 1.

## 30. Evidências da TASK-474 (antes TASK-452) (trava da D14: notas fora do overview)

Branch `backend/20261010-painel-financeiro-pessoal-TASK-452`, a partir de `dc29ca3f7f`. Achado R3 do `security-reviewer` (§29), aprovado pelo usuário. **Só testes: nenhuma linha de produção mudou** (`git diff --stat -- app` vazio).

**O que mudou.** Em `OverviewControllerTest`: `assertNotesStayOutOfTheOverview()` (para um usuário, o corpo bruto de `GET /api/me/overview` não contém os textos marcadores `MARCADOR-NOTA-DO-PAGAMENTO` e `MARCADOR-NOTA-DO-ACERTO`) e dois testes:
- `test_payment_notes_never_reach_the_overview_from_a_live_cycle`: duas despesas (uma paga, com `payment_note`; outra pendente, para o ciclo seguir em foco) e o acerto líquido de R$ 80,00 já confirmado, com `note`. Pré-condições: o painel mostra o ciclo 1, as 2 despesas e o comprovante do acerto. **Controle:** o `summary` do mesmo ciclo **contém** os dois marcadores. Confere o devedor **e** o credor.
- `test_payment_notes_never_reach_the_overview_from_a_sealed_snapshot`: o ciclo vigente selado, lido do snapshot (que guarda `paymentNote` nas despesas); a nota do acerto vem da confirmação ao vivo, que `view()` aplica sobre os acertos do snapshot. Mesmas pré-condições e controle (`summary` com `cycles_ago=0`). Confere os dois usuários.

**Um erro meu, pego pela mutação.** A primeira versão (um teste só, semeando uma despesa **toda paga** e um acerto confirmado) passou de primeira, mas ao vazar o `paymentNote` em `my_items` a suíte **continuou verde** (42 passaram). Motivo: um ciclo todo quitado sai do painel (cai em outubro, vazio), então nenhuma nota chegava a ser testada: o teste era vazio. Refiz com a segunda despesa pendente, as pré-condições que provam que o painel exibe as despesas e o acerto, e o caso do snapshot. Foi a mutação que revelou, e não a leitura do teste, que parecia correto.

| Verificação | Como | Resultado |
|---|---|---|
| Testes novos | `php artisan test --filter=test_payment_notes_never_reach_the_overview` | 2 passed (28 asserções) |
| Produção intacta | `git diff --stat -- app`; `grep -c "confirmedNote\|paymentNote" app/Services/PersonalOverviewService.php` | Vazio; **0** referências às notas no serviço do overview (coerente com a D14) |
| Estilo | `php vendor/bin/pint --test` | `PASS ... 174 files` |
| **Suíte inteira** | `php artisan test --no-ansi` | `Tests: 564 passed (3539 assertions)`, 82,02 s (562 + 2) |

**Mutações** (cada uma revertida; reli o trecho antes de restaurar). Todas derrubam **os dois** testes, com a mensagem certa:

| # | Mutação | Resultado |
|---|---|---|
| M1 | `'note' => $expense['paymentNote']` num item de `my_items` | "a nota do pagamento vazou no overview (ciclo ao vivo, Beto Devedor)" e a versão do snapshot |
| M2 | `'confirmed_note'` numa linha de `to_receive` | "a nota do acerto vazou no overview (…, **Ana Credora**)": só o credor via esse vazamento, por isso o teste olha os dois |
| M3 | `'confirmed_note'` numa linha de `to_pay` | "a nota do acerto vazou no overview (…, **Beto Devedor**)" |

## 31. Evidências da TASK-425 (backlog dos achados tangenciais)

Branch `docs/20261010-painel-financeiro-pessoal-TASK-425`, a partir de `b0ce30ee1a`. **Só documentação**: nenhum arquivo de `backend/` ou `frontend/` mudou. O próximo ID livre foi conferido contra a `dev` (`git ls-tree` e `git grep "^ID:"` em `origin/dev`): a `dev` não usa nada acima de 084, então os novos vão de **087 a 095**.

| Achado | Onde ficou | Prioridade |
|---|---|---|
| `grossDebts` usa o pagador sem checar nulo | **087** `gross-debts-pagador-nulo-sem-checagem.md` | BAIXA |
| Destino do botão "Informar pagamento" | **088** `informar-pagamento-botao-so-visual-destino.md` | BAIXA |
| `GET /api/dashboard` stub, `Navbar.tsx` e `InternalLayout.tsx` mortos | **089** `api-dashboard-stub-e-codigo-morto.md` | BAIXA |
| S5: `cycles_ago` sem teto e relativo a "agora" | **090** `acoes-de-pagamento-identificam-o-ciclo-por-cycles-ago.md` | BAIXA |
| S6: contato do credor e lookup do Pix por e-mail | **091** `contato-do-credor-no-overview-e-pix-por-email.md` | BAIXA |
| `jwt.auth` fora do `middlewarePriority` | **092** `jwt-auth-fora-do-middleware-priority.md` | BAIXA |
| R1: custo do `focus()` (2.352 consultas, 7,4 s) | **093** `custo-do-focus-com-ciclos-quitados-e-nao-selados.md` | MEDIA |
| D14: exibir a descrição do pagamento | **094** `exibir-descricao-do-pagamento-no-painel.md` | BAIXA |
| `GroupController::destroy` por qualquer membro | **095** `group-destroy-exclusao-fisica-por-qualquer-membro.md` | MEDIA |
| `/me/overview` no escopo v1 do app | nota em **074** (não é item novo) | — |
| Status individual por parte em `my_items[]` | nota em **075** (não é item novo) | — |
| X1, X2, X3 | **já existiam**: 085, 086 e 060 (conferidos, não recriados) | — |

Cada item segue o formato do `README.md` (ID, Origem, Criado em, Prioridade, Status, Descrição, Por que importa, Tipo) e, onde há decisão, `## Regras adotadas` e `## Decisões em aberto` em tabela, com opções, recomendação e motivo. O 087 só tem regras (não há decisão).

**Cada afirmação foi conferida no código antes de escrever**, e duas foram **reproduzidas** com sonda descartável (apagada, nada versionado):

| Verificação | Como | Resultado |
|---|---|---|
| `GET /pix/generate`: e-mail existente sem grupo em comum × e-mail inexistente | Teste temporário `ZzProbePixOracleTest` com o token de um usuário | **403 × 404**: qualquer conta autenticada testa se um e-mail está cadastrado. Mesma classe da enumeração já aceita em `POST /register` (item 045), por isso o 091 diz que **não eleva o risco** |
| `BillingCycle::cycleFor` com `cycles_ago` extremo | `php -r` com o autoload | `12` → 2025-10-11; `1000` → 1943-06-11; `100000` → ano **-6307**, sem exceção. O que `pay`/`confirm` fazem com isso **não testei**, e o 090 diz assim |
| O botão "Próximo ciclo" do `GroupGrossDebtsPanel` envia valor negativo | Leitura: só o ícone de Pix tem `disabled`; `goToNextCycle` faz `prev - 1` | Sim, chega a `-1`. Por isso o 090 não recomenda `min:0` nas leituras |
| `destroy` tem teste? | `grep` em `GroupControllerTest` | **Tem**, e trava "qualquer membro" até com cota **não paga** (`test_member_can_delete_group_without_closed_cycle_physically`) |

**Quatro erros meus, pegos ao conferir antes de fechar:** (1) escrevi "`GroupShell`"; o nome é `GroupShellLayout`. (2) No 090 afirmei que o web usa `cycles_ago` negativo e que valores extremos caíam em `422`, sem ter visto nem testado; troquei pelo que li e medi. (3) Na opção C do 093 eu descartava ciclos sem cota pendente, mas um ciclo com todas as cotas pagas ainda pode ter acerto sem confirmação, e isso só o `compute()` sabe: reescrevi a opção e separei as duas origens de ciclo quitado e não selado (`bornPaid` nunca sela por desenho; o outro caso depende de medir). (4) No 095 escrevi "lacuna de teste de `destroy`"; os testes existem e travam o comportamento. Corrigi o texto.

| Verificação | Como | Resultado |
|---|---|---|
| Links do índice | laço sobre os `](arquivo.md)` do `README.md` | Nenhum quebrado |
| IDs únicos | `grep -oE "^\| [0-9]{3} "` + `uniq -d` | Vazio |
| Arquivo sem linha no índice | laço sobre `docs/backlog/*.md` | Nenhum |
| Escopo | `git status --short` | Só `docs/backlog/**` (9 novos, 3 alterados) e os dois docs da feature; `backend/.env.testing` segue untracked |

### Achado novo: colisão de numeração de TASK (decisão do usuário)

Ao conferir o próximo ID livre apareceram duas colisões de **número de TASK**, que quebram o "toda mudança rastreável até uma task":

| Colisão | Fato | Quem causou |
|---|---|---|
| **TASK-433** | A feature `20261021-comprovante-ao-marcar-paga` (já na `dev`, `docs/feature/concluidas/202610/`) tem uma linha `TASK-433` e declara "IDs a partir de TASK-433: o maior em qualquer branch é TASK-432 (feature `20261010-painel-financeiro-pessoal`)". Depois disso, numerei "Já recebido" como **TASK-433** nesta feature | **Eu.** Devia ter conferido a `dev` antes de numerar |
| **TASK-449 a 452** | A feature `20261022-editar-comprovante-depois-de-paga` (branch local `frontend/20261022-editar-comprovante-depois-de-paga-TASK-454`, em outro worktree, **não está no `origin`**) numera suas tasks de **449 a 464** (as 449 a 453 já concluídas, a 454 em andamento). Meus 449 (merge da `dev`), 450, 451 e 452 usam os mesmos números | Concorrência: as duas sessões escolheram a partir de 449 |

As TASK-427 a 432 e 409 a 422 não colidem. **Não alterei nada em nenhuma das duas frentes.** Os commits e os merges já feitos citam o número antigo, e o histórico do git não se reescreve.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| N1 | Como resolver | A) renumerar, **só nos docs**, as minhas tasks que colidem (433, 449, 450, 451, 452) para um bloco livre acima de tudo o que existe, com um mapa antigo → novo no §1 deste arquivo<br>B) deixar e qualificar sempre pela feature ("TASK-433 do painel")<br>C) a feature que chegar por último na `dev` renumera | **A, em task própria e com a sua aprovação** | O 433 já colide com o que está **publicado** na `dev`, então é meu consertar. B deixa ambiguidade em `git log` e em `grep`. C só resolve os 449 a 452 e depende de quem mergear primeiro. |
| N2 | Bloco novo | A) 465 a 469 (logo acima do maior número conhecido, 464)<br>B) um bloco bem acima, por exemplo 470 em diante | **B** | A outra feature ainda tem tasks pendentes e pode passar de 464. |
| N3 | IDs de backlog | A) combinar com a outra sessão quem usa 087 em diante<br>B) quem mergear depois renumera | **B** | Ela ainda vai criar itens (TASK-459 e 464 dela registram backlog) a partir do 085, que existe só na minha branch. O 085 a 095 aqui só viram `dev` no meu PR; vale conferir o índice no momento do merge. |

**Decidido pelo usuário em 2026-10-23:** **N1 = A** (renumerar só nos docs), **N2 = B** (bloco de 470 em diante), **N3 = B** (quem mergear depois renumera os IDs de backlog). Executado na TASK-475 (§35). Esta seção mantém de propósito os números **antigos**, porque descreve a colisão.

## 32. Evidências da TASK-426 (atualização do plan de Relatórios)

Branch `docs/20261010-painel-financeiro-pessoal-TASK-426`, a partir de `1961dc8e73`. **Só documentação muda neste repositório: este log, o `tasks.md` e o item 093 do backlog** (ganhou os três caminhos que o plan de Relatórios levantou para o ciclo quitado e não selado).

**O achado que mudou o caminho.** `docs/feature/20261006-relatorios-acompanhamento-fechamento/` **não está no git**: `git log --all -- <pasta>` é vazio, `git ls-tree origin/dev` não a tem, e a pasta não existe neste worktree. Só existe, como arquivos não rastreados de 06/10, no checkout principal (`specify.md`, `plan.md`, `tasks.md` em branco, `implementation.md`). Perguntei ao usuário onde aplicar; a escolha foi **editar no checkout principal, sem commit**.

**O que travou.** O harness recusa a ferramenta de edição fora do worktree ("edite a cópia do worktree"), e este worktree não tem cópia. **Não contornei por outra ferramenta.** Preparei o plan **já atualizado** no scratchpad e deixei a cópia final para o usuário:

| Arquivo | SHA-256 (12 primeiros) | Observação |
|---|---|---|
| `plan.md` original, no checkout principal (conferido em 2026-10-23) | `281513B58511` | Não foi tocado. Cópia de segurança no scratchpad: `relatorios-original\plan.md.orig` |
| `plan.md` atualizado (scratchpad: `relatorios-atualizado\plan.md`) | `CFBA61CA86FB` | 157 → 182 linhas; versão 1.0 → 1.1 |

**O que muda no plan** (nenhuma decisão do `specify.md` muda):

| Onde | Mudança |
|---|---|
| Topo | Bloco "Atualização de 2026-10-22": vale depois do merge do Painel na `dev`; tabela "estava → passa a ser"; método por nome, linha só como dica |
| §0.1 item 2 | Linhas de `sealCycleIfSettled` (`:696`, `:789`, `:1053`, `:1216`), `rejectIfCycleClosed` (`:390`, `:160`, `:365`, `:392`) e `Notifier::cycleSettled` (`:1321`); `focusCycle` → `CycleSummaryService::focus()`; ligação com o item 093 e com o caso `bornPaid`-só, que **não** vira `awaiting_seal` |
| §0.2 | Rota em `routes/api.php:72` (era `:70`); `computeCycleSummary` → `compute` |
| §0.3 | **Nada a extrair**: `CycleSummaryService::summaryIsFullySettled` já existe com a assinatura proposta. Texto original mantido como histórico |
| §1 | `FOCUS_LOOKBACK` com o prefixo da classe do serviço (no controller não existe mais); `compute`/`withConfirmations`; atalho com `view()`; custo medido (≈49 consultas, ≈0,5 s por grupo) |
| §2.1 | Fonte "snapshot ou ao vivo" via `viewAt` (D-N3); `withConfirmations` |
| §3, §4 | `useGroupCycle.ts:91` (era `:85`), `GroupHeader.tsx:46` (era `:45`); `SummarySidePanel.tsx:54`, `CycleDetailPanel.tsx:46-90` e `:92-135`, `Sidebar.tsx:17` e `BillingCycle.php:49` conferidos **sem mudança** |
| §6, §7, §8 | Sentinelas + `CycleSummaryServiceTest`; passo 1 de §7 removido; ligação do §8 com o item 093 |
| 3 decisões novas, propostas | **D-N1** mostrar `paymentNote`/`confirmedNote` no Fechamento (rec. A); **D-N2** varredura do Acompanhamento no `CycleSummaryService` (rec. A); **D-N3** `viewAt` no serviço, com `view()` delegando (rec. A). Vão ao gate do plan, antes do `tasks.md` de Relatórios |

| Verificação | Como | Resultado |
|---|---|---|
| Cada linha citada | `grep -n` no worktree antes de escrever, e `Read` das faixas `:666-673`, `:698-703` e `CycleDetailPanel.tsx:86-141` ao final | Todas conferem. **Um erro meu pego aqui:** escrevi `:668-702` para o bloco de escolha da fonte em `summary()`; o certo é `:671-702` |
| Referências antigas que sobraram | Busca no plan atualizado por `:566`, `:686`, `:936`, `:1091`, `:284`, `:286`, `:629`, `:1404`, `:605`, `:1323`, `:1378`, `:541-572`, `:668-702`, `useGroupCycle.ts:85`, `GroupHeader.tsx:45`, `api.php:70` | Nenhuma |
| `specify.md` de Relatórios | Releitura | **Não editado.** Tem as mesmas linhas defasadas e dois `computeCycleSummary`, mas nenhuma decisão contradita; o critério da task é editar só se algo ficar contraditório. Vale o plan |
| Efeito no repositório | `git status --short` | Só `docs/` da feature do Painel |

**O que falta (é seu).** Copiar o plan atualizado por cima do original no checkout principal. Se o `plan.md` de lá mudar antes (o SHA do original é `281513B58511`), a cópia perde essa mudança: me avise.

## 33. Revalidação da stack na branch final (2026-10-23)

A stack da TASK-423 caiu quando a sessão anterior terminou (portas 8001 e 3001 livres; o contêiner `mysql-painel-test` seguiu no ar). A pedido do usuário ("suba a stack e valide"), subi de novo, no commit `3510895b36`, **sem nenhuma mudança de código de produção desde a TASK-473** (só testes e docs), e repeti um roteiro curto. **Isto não é a confirmação por escrito do usuário** de que ele validou: é a minha conferência, para ele validar em cima de uma stack viva.

Stack: backend `php artisan serve --env=testing --port=8001` com `DB_DATABASE=painel_validacao` (32 migrações aplicadas, 0 pendentes), Vite na 3001 apontando para a 8001. Portas 3000 e 8000 **não** foram tocadas (livres).

| # | Verificação | Como | Resultado |
|---|---|---|---|
| 1 | Reconciliação por HTTP | `check_overview.php` (cada cartão do `/me/overview` contra o `/summary` do mesmo ciclo) | **6 de 6 iguais** (Ateliê, Consórcio, Viagem, Oficina, Residencial, Sítio) |
| 2 | `/painel` com valores | Login pela tela, link "Painel" do menu | Os quatro totais por cartão batem com a API: Consórcio 400 em aberto + 250 já recebido = 650 do acerto; Residencial 315 e Oficina 40 só em "Já confirmado" |
| 3 | Visão de devedor | Detalhes do Ateliê | "Isac Aguiar deve pagar R$ 100,00 a Otávio Braga", com "Pagar com Pix" e "Enviar comprovante"; item Material pendente (sua parte 100 de 200) |
| 4 | Diálogo do comprovante do acerto | "Enviar comprovante" | Foto **obrigatória** (Confirmar desabilitado), descrição opcional, contador 0/500. Cancelado sem enviar |
| 5 | Visão de credor | Detalhes do Consórcio | Três devedores com status distintos (Aguardando ×2, "Comprovante enviado" ×1); itens pagos com "Desfazer" |
| 6 | Escrita de ponta a ponta (reversível) | No **Seguro** (pago, sem foto e sem nota): Desfazer, depois "Marcar como paga" só com descrição | `POST unpay` 200, `GET overview` 200, `POST pay` 200, `GET overview` 200. Depois do Desfazer o item vai a "Pendente" e **os totais do cartão não mudam** (D1). O diálogo diz "foto **ou** descrição" e só habilita Confirmar com uma delas. No banco: `payment_note` = a descrição com acentos intactos; o Condomínio (com foto e nota) **intocado**; `ex_group_cycle_snapshots` = **0** (a leitura não sela) |
| 7 | 375 px | `resize_window` 375×812 | Sem scroll horizontal (`scrollWidth` = `clientWidth` = 375), totais em 2 colunas (148,5 px cada) |
| 8 | Sem sessão | Acesso direto a `/app/painel` depois de Sair | Volta ao login |
| 9 | Usuário sem grupos | `semgrupo@painel.test` | "Você ainda não participa de nenhum grupo." e "Ir para Meus Grupos" |
| 10 | Console e rede | `read_console_messages` e `read_network_requests` | Sem erro de console; todas as chamadas 200 ou 204 (preflight). O overview é chamado duas vezes ao entrar na tela: é o `React.StrictMode` do modo dev (`main.tsx`), não ocorre em produção |
| 11 | Tempo | `check_overview.php`, máquina ociosa | Login 0,78 s; `/me/overview` com 6 grupos ≈ 1,0 s. As primeiras chamadas, com Vite e backend ainda subindo, levaram 5 a 13 s: ruído de carga, não do código |

**Estado deixado no banco da validação:** o Seguro (grupo 2) agora está pago **com** a nota "Revalidação local da branch final: Seguro pago em dinheiro" (antes, sem nota). Todo o resto como estava. Uma notificação `expense_paid` nova (a #15, para o usuário 3, de 14 para 15 linhas); o `unpay` não gerou nenhuma. Eu tinha escrito "três" de memória, sem contar, e o banco corrigiu.

**Um tropeço meu, sem efeito:** o `check_db.php` falhou na primeira vez (`Access denied for user 'forge'`) porque eu o rodei sem `APP_ENV=testing`, então ele leu o `.env` em vez do `.env.testing`. Rodando com `APP_ENV=testing` funciona.

**Pendências (inalteradas):** a confirmação por escrito do usuário (último critério da TASK-423); o encerramento da stack (processos 8001 e 3001, banco `painel_validacao`, arquivos de comprovante de hoje nas pastas 1, 2, 4 e 6 de `storage/app/comprovantes/`) só depois dela.

**Conferência visual (mesmo dia, depois do pedido "consegue fazer a validação?").** O item 3 acima e a coluna "Resultado" da tabela foram conferidos pelo DOM porque o painel do navegador estava **oculto** e os screenshots saíam cortados. Abri o painel (`show_pane preview`) e olhei a tela de fato, em largura de celular (o painel tem ~454 px):

| Estado | O que vi |
|---|---|
| Devedor, ciclo fechado (Ateliê) | Valor "A pagar" em vermelho, "A receber", "Já confirmado" e "Já recebido" em grade 2×2, chip "Ciclo fechado", aviso âmbar "Você tem pagamentos em aberto neste ciclo.", links "Ver ciclos" e "Ver detalhes" |
| Em dia (Oficina, Residencial) | Chip verde "Em dia" ao lado de "Ciclo fechado", sem aviso, "Já confirmado R$ 40,00" |
| Em andamento (Viagem) | Chip azul "Ciclo em andamento", "A pagar R$ 300,00" em vermelho e o aviso |
| Credor expandido (Consórcio) | "Você paga: você não deve nada"; "Você recebe": um cartão por devedor com valor em verde e chip (laranja "Aguardando pagamento" ×2, verde "Comprovante enviado"); "Seus itens" com chip "Paga" e botão "Desfazer" |
| Desktop (1280 px, pelo CSS calculado) | 6 grades de totais com **4 colunas iguais** (216,75 px), sem scroll horizontal, menu lateral visível |

**Um falso alarme meu, sem defeito:** logo depois de clicar em "Ver detalhes" no Consórcio, o screenshot mostrou o cartão cortado depois do primeiro devedor. Era a animação de abrir (Collapse) ainda em curso: um segundo screenshot mostrou o cartão inteiro.

**O que uma conferência minha não substitui:** o juízo do dono do produto sobre a tela (texto, hierarquia, o que falta), e a sensação em aparelho real. Por isso o último critério da TASK-423 continua sendo a confirmação por escrito do usuário.

## 34. Confirmação do usuário e encerramento da stack (2026-10-23)

**Confirmação por escrito, recebida em chat:** "validei, pode seguir para push e PR". É o último critério da TASK-423, que passa a **Concluída**, e libera a TASK-424. O push e o PR ficam para depois das decisões N1 a N3 do §31 (a numeração de TASK entra no conteúdo do PR).

**Encerramento da stack**, feito na ordem abaixo. Nada fora do escopo foi tocado.

| Passo | Como | Resultado |
|---|---|---|
| Processos | `TaskStop` nos dois shells em segundo plano (backend na 8001, Vite na 3001) e `Get-NetTCPConnection` em seguida | Portas **8001 e 3001 livres**; **3000 e 8000** (projeto rota e outros) também livres, nunca tocadas |
| Comprovantes de teste | Listei `backend/storage/app/comprovantes/` com data e tamanho **antes** de apagar; apaguei só os 3 arquivos referenciados no banco da validação, por caminho completo, depois de conferir que estavam sob a raiz: `1\dfa74aca-….png`, `2\1c9d9d2a-….png`, `6\3cc6f43a-….png` (70 bytes, 22/10); removi as 3 pastas que ficaram vazias | 3 arquivos e 3 pastas. **A pasta `4` não existia**: os `comprovantes/4/semente.jpg` e `comprovantes/2/semente.jpg` do `seed_painel.php` eram só referências no banco, sem arquivo |
| Sobras do PHPUnit | Mesma listagem | Ficaram **17 pastas** de ids altos (`10014`, `13407`, …) com um `.jpg` de 695 bytes cada, de 10/10 a 22/10. Pelo padrão (ids de grupo altos, mesmo `.jpg` de 695 bytes) parecem execuções da suíte, que grava no disco real; **não confirmei a origem**, mas **não são da validação** (nenhuma está referenciada no banco que eu semeei). Não toquei |
| Banco | `SHOW DATABASES` no contêiner `mysql-painel-test`, `DROP DATABASE painel_validacao`, `SHOW DATABASES` de novo (a senha lida do `.env.testing` e passada por variável de ambiente, sem imprimir) | Antes: `ex-db` e `painel_validacao`. Depois: só **`ex-db`** (o banco do PHPUnit) |

**Mantidos até o fim da feature**, como combinado: o contêiner `mysql-painel-test` (o PHPUnit usa o `ex-db` dele) e o `backend/.env.testing` (untracked, **nunca** entra em `git add -A`). Ao fim: `docker rm -f mysql-painel-test`, apagar o `.env.testing` e remover o worktree.

## 35. Evidências da TASK-475 (renumeração das tasks que colidem)

Branch `docs/20261010-painel-financeiro-pessoal-TASK-475`, a partir de `a9b06ef5b6` (a `dev` já integrada, 0 atrás). Decisões do usuário em 2026-10-23: **N1 = A, N2 = B, N3 = B** (§31). Só **documentos da feature** e **5 comentários de teste** mudam; nenhuma linha executável.

| Antes | Agora |
|---|---|
| TASK-433 | **TASK-470** |
| TASK-449 | **TASK-471** |
| TASK-450 | **TASK-472** |
| TASK-451 | **TASK-473** |
| TASK-452 | **TASK-474** |

**Regras aplicadas** (por script em PowerShell, depois conferidas por `git grep`):

| Regra | Por quê |
|---|---|
| Troca o ID em `tasks.md`, `plan.md` e `implementation.md`, e nos 5 comentários de teste | É o que a decisão N1 manda |
| **Mantém** os nomes de branch já criados (`backend/20261010-painel-financeiro-pessoal-TASK-449` etc.) | São referências literais a branches que existiram |
| **Mantém** a seção "Achado novo: colisão de numeração" (§31, até o §32) com os números antigos | Ela descreve a colisão: trocá-los apagaria o motivo |
| Linha de tabela, título de seção e critério ganham "(antes TASK-xxx)" | Quem chega por um commit antigo acha a task nova |
| `specify.md` e `docs/backlog/` **não mudam** | Nenhuma ocorrência dos cinco números |
| Os docs de **outras** features (por exemplo, `comprovante-ao-marcar-paga`, que tem a TASK-433 dela) **não são tocados** | O `git grep` excluiu esse caminho de propósito |

| Verificação | Como | Resultado |
|---|---|---|
| Alcance | `git grep -nE "TASK-(433\|449\|450\|451\|452)\b"` por arquivo, antes | `implementation.md` 26, `tasks.md` 15, `plan.md` 3, e 5 comentários de teste; nada em `specify.md`, `docs/backlog/`, `docs/sdd/`, `.claude/` ou `CLAUDE.md` |
| Diff | `git diff --stat` depois do script | 34 linhas trocadas por 34 em `implementation.md`+`plan.md`+`tasks.md` (o arquivo não foi reescrito; CRLF preservado) |
| O que sobrou com número antigo, fora de nome de branch e de "(antes …)" | `git grep` filtrado | **Só** a seção protegida do §31 (a linha da TASK-433 da `dev` e a das TASK-449 a 452 da outra feature, mais a opção B da N1) e a frase do §25 |
| Backend | `php artisan test --filter="CycleSummaryServiceTest\|OverviewControllerTest"` | **48 passed (375 asserções)** |
| Estilo | `php vendor/bin/pint --test` | `PASS ... 174 files` |
| Frontend | `npx vitest run` em `GroupPositionCard.test.tsx` e `useConfirmSettlement.test.tsx` | **2 arquivos, 35 testes passaram** |
| Tipos | `npx tsc --noEmit` | Sem erro (exit 0) |

**Dois erros meus, pegos pela conferência:**
1. **A primeira execução do script não protegeu a seção da colisão.** O PowerShell 5.1 lê `.ps1` sem BOM como ANSI, então os literais com acento ("colisão", "Numeração") nunca casaram e a seção e a frase do §25 foram convertidas. O `git grep` do que sobrou mostrou que as linhas protegidas **não apareciam**, o que denunciou o problema. Restaurei os três arquivos (nenhuma outra edição pendente) e refiz com padrões ASCII; depois a lista do que sobrou bateu com o esperado.
2. **A frase do §25 estava errada desde o começo**: dizia que "a TASK-433 daqui não colide". A `dev` tem uma TASK-433 (feature `comprovante-ao-marcar-paga`). Risquei a frase e apontei para o §31.

**Fica para depois (decisão N3 = B, sem ação):** os IDs de backlog 085 a 095 podem colidir com itens que a feature `editar-comprovante-depois-de-paga` crie a partir do 085. Quem mergear depois renumera; se ela integrar a `dev` depois do meu merge, já pega 096 em diante.
