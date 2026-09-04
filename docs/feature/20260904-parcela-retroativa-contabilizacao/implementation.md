# Implementation — Contabilização da parcela retroativa

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260904

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum — segue `docs/sdd/04-implementation.md` sem exceção. TASK-001 é implementada
direto na branch da feature `feature/20260904-parcela-retroativa-contabilizacao`,
criada a partir de `dev` atualizada (`48fecc7a8c`).

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-001 | Concluída | 2026-09-04 | Claude (IA) | Ver detalhamento abaixo | TDD (RED→GREEN); `born_paid` + filtro em `computeCycleSummary` |
| TASK-002 | Concluída | 2026-09-04 | Isac (usuário) | Ver detalhamento abaixo | Executado em produção via phpMyAdmin/SSH, verificado no app |

### TASK-001 — detalhamento

Mudança: `backend/app/Http/Controllers/ExpenseController.php` + `backend/app/Models/Quota.php` + migration nova.

1. **Migration** `2026_09_04_000000_add_born_paid_to_ex_quotas_table.php`: `ex_quotas.born_paid boolean default(false)`.
2. **`Quota`**: `born_paid` em `$fillable` e cast `boolean`.
3. **`store()`** (`:434-441`): grava `'born_paid' => $bornPaid` (mesma condição que já define `paid`).
4. **`collectCycleEntries()`** (`:1381-1474`): propaga `'bornPaid'` nas 3 origens de entry (direct, installments, fixed).
5. **`computeCycleSummary()`** (`:1114-1233`): `expenses[]` ganha a chave `bornPaid`; o laço de `balances`/`$owed`/`settlements` (`:1165-1195`) pula entry `bornPaid` — nunca `paid`, pra não regredir a feature `20260902-pagamento-ciclo-fechado`.
6. **`sealCycleIfSettled()`** (`:1325-...`): guard de "ciclo sem conteúdo" passa a exigir despesa real (não-`bornPaid`) — evita selar/notificar um mês passado cujo único conteúdo é parcela retroativa.

Testes novos/alterados (`backend/tests/Feature/`):
- `ExpenseControllerStoreTest`: `test_installments_expense_starting_in_a_closed_cycle_is_created_with_past_quotas_paid` (estendido: asserts `born_paid`); `test_installments_expense_shared_with_a_debtor_marks_past_quotas_born_paid` (novo); `test_new_expense_quota_starts_as_pending_even_if_client_sends_paid_true` (estendido: `born_paid=false`).
- `ExpenseControllerSummaryTest`: `test_born_paid_installment_is_paid_line_without_settlement_in_past_closed_cycle` (novo); `test_closed_cycle_expense_paid_via_pay_still_generates_settlement` (novo, regressão 20260902); `test_retroactive_only_cycle_does_not_auto_seal_or_notify` (novo).
- `ExpenseControllerCycleIsFullySettledTest`: `test_true_when_only_born_paid_entries_no_confirmation_needed` (novo).
- `FocusCycleTest`: `test_retroactive_shared_installment_does_not_drag_home_back` (novo).
- `ExpenseControllerGrossDebtsTest`: `test_retroactive_paid_installment_absent_and_matches_settlements` (novo, paridade Dashboard×Pagamentos).

Comandos executados / resultado:

| Comando | Resultado |
|---|---|
| `php artisan test --filter=ExpenseControllerStoreTest` (baseline, antes da mudança) | 21 passed (68 assertions) |
| `php artisan migrate --path=database/migrations/2026_09_04_000000_add_born_paid_to_ex_quotas_table.php` | DONE |
| `php artisan test --filter='...8 novos/alterados...'` (RED, antes do código) | 6 failed, 2 passed (30 assertions) — os 2 verdes são a regressão (ciclo aberto/paid via pay) e o teste de não-selagem, que já passavam pelo motivo errado (settlement fantasma bloqueava a selagem de qualquer jeito) |
| Implementação de `store()`/`collectCycleEntries`/`computeCycleSummary`/`sealCycleIfSettled` | — |
| `./vendor/bin/pint app/Http/Controllers/ExpenseController.php app/Models/Quota.php database/migrations/2026_09_04_000000_add_born_paid_to_ex_quotas_table.php tests/Feature/{ExpenseControllerStoreTest,ExpenseControllerSummaryTest,ExpenseControllerCycleIsFullySettledTest,FocusCycleTest,ExpenseControllerGrossDebtsTest}.php` | PASS, 8 files |
| `php artisan test --filter='ExpenseControllerStoreTest\|ExpenseControllerSummaryTest\|ExpenseControllerCycleIsFullySettledTest\|FocusCycleTest\|ExpenseControllerGrossDebtsTest\|SettlementConfirmationControllerTest\|ExpenseControllerCloseTest\|ExpenseControllerReopenTest\|GroupCycleSnapshotTest\|ExpenseControllerPayTest'` (GREEN) | 128 passed (487 assertions) |
| `php artisan test` (suíte completa) | 327 passed (1027 assertions) — sem regressão |
| `php artisan migrate:rollback --step=1` + `php artisan migrate --path=...` | DONE / DONE — down()/up() limpos |

Doc atualizada: `docs/backlog/expense-parcela-retroativa-paid-by-sem-consentimento.md` — item §2 ("ciclo `closed` não selado") marcado resolvido por esta feature; §1 (consentimento do credor) continua aberto.

`security-reviewer`: sem achado novo (mass assignment de `born_paid` descartado — campo nunca vem do payload). `pr-readiness-checker`: verde para o diff desta branch (pint limpo nos arquivos tocados; débito de estilo pré-existente em arquivos fora do diff, não bloqueante).

PR aberto: https://github.com/isacaguiar/expense/pull/147 (`feature/20260904-parcela-retroativa-contabilizacao` → `dev`). **Merge é gate humano.** Mergeado em 2026-09-04.

Promoção `dev` → `main`: PR #148, mergeado em 2026-09-04 — disparou `deploy-backend.yml` (run `33924553910`), `SCRIPT_AFTER` rodou `php artisan migrate --force --no-interaction` com sucesso (job concluído sem falha).

### TASK-002 — detalhamento (execução em produção)

Script: `fix-prod-3878.sql` (nesta pasta). Rodado pelo usuário via phpMyAdmin/SSH em `expense-api.novemax.com.br` (MySQL `ex-db`), **depois** de confirmado que a migration de TASK-001 estava aplicada (1ª tentativa, antes da promoção `dev`→`main`, deu `#1054 - Unknown column 'born_paid'`; após o PR #148 e o deploy, o `SELECT` de conferência do passo 2 já leu a coluna normalmente).

Passo a passo confirmado pelo usuário:

| Passo | Ação | Resultado confirmado |
|---|---|---|
| 2 | `UPDATE ex_quotas SET born_paid = 1 WHERE expense_id IN (8658, 8659) AND paid = 1` | 4 linhas afetadas (8658 #1/#2, 8659 #1/#2) — conferido por print do phpMyAdmin: `paid=1`, `paid_by=5573`, `born_paid=1` |
| 3 | `UPDATE ex_group_cycle_snapshots SET settled_at = NULL WHERE group_id=3878 AND cycle_start='2026-07-01'` | `SELECT` antes tinha `settled_at` preenchido; depois, `NULL` |
| 4 | `UPDATE ex_group_cycle_snapshots SET settled_at = NULL WHERE group_id=3878 AND cycle_start='2026-08-01'` | `settled_at` **já estava `NULL`** antes do `UPDATE` (agosto havia dessellado sozinho entre o diagnóstico original e a execução — grupo em uso real; `UPDATE` rodou como no-op) |

Verificação final no app (confirmada pelo usuário, grupo 3878 "Piatã House"):
1. Home do grupo abre no ciclo vigente (setembro) — não fica mais presa em junho.
2. Junho: Adestrador e Construção parede aparecem "Paga", sem card "deve pagar" para ninguém.
3. Julho: as duas parcelas passaram a aparecer (antes: "Nenhuma despesa neste ciclo").
4. Agosto: a parcela de cada despesa aparece como pendência real, junto com os demais itens do mês.

Diagnóstico do passo 0 (outras parceladas retroativas na mesma janela) não chegou a ser reportado pelo usuário — se aparecer outra despesa afetada além de 8658/8659, decidir separadamente (fora do escopo desta feature).
