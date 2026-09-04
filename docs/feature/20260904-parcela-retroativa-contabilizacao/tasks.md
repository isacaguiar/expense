# Tasks — Contabilização da parcela retroativa

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260904

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-001 | Excluir parcela retroativa nascida quitada (`born_paid`) do acerto em `computeCycleSummary`, com marcador em `ex_quotas` e testes | backend | plan.md §0, §1, §2, §4 | antes do merge | Concluída |
| TASK-002 | Script SQL + runbook para ajustar os dados já afetados do grupo 3878 em produção | infra | plan.md §3 | antes do deploy/execução em produção | Pendente |

TASK-001 é um diff único e coeso: a coluna `born_paid`, quem a escreve (`store()`), quem a propaga (`collectCycleEntries`) e quem a consome (`computeCycleSummary`/`sealCycleIfSettled`) são o mesmo mecanismo — não são entregas separáveis. Os testes (Constitution §2.2) entram no mesmo diff. TASK-002 depende de a TASK-001 estar **em produção** (a coluna e o filtro precisam existir antes do `UPDATE`, senão o Bug 2 não se resolve e a desselagem de julho dispara notificação espúria).

## Critérios de aceite

- **TASK-001**:
  - Migration `2026_09_04_000000_add_born_paid_to_ex_quotas_table.php` cria `ex_quotas.born_paid` boolean `default false`, `after('paid_by')`; `down()` derruba a coluna. `php artisan migrate` e `migrate:rollback` limpos.
  - `App\Models\Quota`: `born_paid` em `$fillable` e cast `boolean` em `$casts`.
  - `POST /api/expenses` com `expense_type = IN_INSTALLMENTS` e parcelas em competência `closed` grava `ex_quotas.born_paid = 1` **exatamente** nas parcelas que já hoje nascem `paid = 1` (mesma condição `BillingCycle::statusFor(...) === 'closed'`); parcelas de ciclo aberto/carência/futuro ficam `born_paid = 0`. `IN_CASH`/`FIXED` e `update()`/`pay()` deixam `born_paid = 0`.
  - `GET /api/groups/{id}/expenses/summary?cycles_ago=N` para um `N` cuja competência está `closed` e **não selada** e contém só parcela(s) `born_paid`: `settlements == []`, todo `balances[*].balance == 0`, `totals.pending == 0`, e a parcela **continua** em `expenses[]` com `paid == true` (e `totals.paid` a inclui).
  - `GET /api/groups/{id}/expenses/focus-cycle` para um grupo cujo único item passado não-quitado seria a parcela retroativa devolve `cycles_ago = 0` (a Home não volta pro passado).
  - Regressão feature `20260902`: uma quota `paid = true` com `born_paid = false` (pagamento do credor via `pay()`) numa competência `closed` **continua** gerando `settlement` e `balances`, e `cycleIsFullySettled()` continua `false` enquanto não houver `SettlementConfirmation`. `SettlementConfirmationControllerTest` inteiro verde.
  - `sealCycleIfSettled()` não sela nem dispara `Notifier::cycleSettled` para competência passada cujo único conteúdo é parcela `born_paid` (verificar com `Notification::fake()` + ausência/`settled_at` nulo do `GroupCycleSnapshot`).
  - Competência **selada** antes: `summary()` segue servindo a foto congelada, sem a parcela nova; `cycle.settled == true`.
  - `gross-debts` e `settlements` do mesmo ciclo retroativo batem (ambos sem a parcela).
  - Toda a matriz de `plan.md` §4 implementada; `./vendor/bin/pint --test` limpo; `php artisan test` completo verde (sem regressão).
  - `security-reviewer` e `pr-readiness-checker` rodados antes do PR; `implementation.md` com o comando real + resultado de cada verificação.

- **TASK-002**:
  - Arquivo `docs/feature/20260904-parcela-retroativa-contabilizacao/fix-prod-3878.sql` com: query de diagnóstico (`plan.md` §3.4), `CREATE TABLE` de backup das linhas de `ex_quotas`/`ex_group_cycle_snapshots` afetadas, e as 3 etapas de `UPDATE` (`plan.md` §3.1–3.3) cada uma precedida do `SELECT` de conferência, tudo dentro de transação explícita (`START TRANSACTION` / `COMMIT`).
  - `implementation.md` com runbook: pré-requisito (TASK-001 mergeada e deployada em produção), ordem dos passos, e os `GET` de verificação pós-script (`plan.md` §3.5) com o resultado esperado de cada um.
  - A decisão sobre backfill amplo vs pontual (resultado da query de diagnóstico) registrada em `implementation.md`.
  - **Não** executado por automação — o `UPDATE` em produção é ação manual do usuário (gate). A task fica `Concluída` quando o script + runbook estão revisados e versionados; a execução em si é registrada em `implementation.md` quando o usuário rodar.
