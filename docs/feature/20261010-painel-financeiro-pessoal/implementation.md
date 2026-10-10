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
| TASK-410 | Concluída (branch `backend/20261010-painel-financeiro-pessoal-TASK-410`) | 2026-10-10 | Claude (IA), com aprovação do usuário | Ver §4 | A fronteira de 12 ciclos do `focus()` não tinha nenhum teste; só os novos a pegam (§4) |

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
