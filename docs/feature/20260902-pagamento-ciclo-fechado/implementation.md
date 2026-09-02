# Implementation — Pagamento em ciclo fechado

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260902

---

## 1. Desvios do fluxo padrão

- **PR de desenho antecipado.** A pedido do usuário, o PR #129 (`backend/20260902-pagamento-ciclo-fechado`
  → `dev`) foi remanejado para **rascunho**, base `dev`, contendo só
  `docs/feature/20260902-pagamento-ciclo-fechado/` (specify + plan + tasks), antes de
  codar, para revisão do desenho. A implementação das tasks continua na mesma branch e o
  mesmo PR #129 vira o PR da feature (sai de rascunho quando o código estiver pronto).
- **Manuais fora desta feature.** `README.md` (reescrito), `MANUAL.md`, `MANUAL.pdf` e
  `manual-assets/` **não** entram nesta feature nem em nenhum commit desta branch. O
  commit `c4e9dbab4` (README + manual) foi movido para a branch dedicada
  `docs/20260902-readme-manual` (criada nesta sessão a partir de `dev`); o PR fica para
  quando o usuário decidir. Esses arquivos precisam ficar de fora do que os workflows de
  deploy (FTP/SSH) publicam.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-244 | Concluída | 2026-09-02 | IA (sessão) | `php artisan migrate` → `2026_09_02_000000_add_settled_at_...` DONE (422ms). Backfill conferido via tinker: `total=4 null_settled_at=0 stamped=4`. `php artisan test tests/Feature/ExpenseControllerCycleIsFullySettledTest.php tests/Feature/GroupCycleSnapshotTest.php` → 12 passed. `./vendor/bin/pint --dirty` → PASS (5 files). `php artisan test` (suíte cheia) → 251 passed / 15 failed — as 15 são pré-existentes (drift de data em `ExpenseControllerStoreTest`/`ShowUpdateDestroyTest`, sem `Carbon::setTestNow()`, batem no guard de `TASK-159`); baseline sem esta task = mesmas 15. | Migration aditiva `ex_group_cycle_snapshots.settled_at` (nullable) + backfill `= updated_at`. `GroupCycleSnapshot::isSealed()` + fillable/casts. Helpers privados `cycleIsFullySettled` / `sealCycleIfSettled` / `unsealIfBroken` em `ExpenseController` — ainda **não referenciados** (TASK-245+ fazem a fiação). Gate humano da migration em produção: pendente (só rodou em local). Débito tangencial: os 15 testes com data fixa são time-bombs — candidatos a `docs/backlog/`. |
| — (drift) | Concluída | 2026-09-02 | IA (sessão) | `setUp()` com `Carbon::setTestNow('2026-08-19')` em `ExpenseControllerStoreTest` e `ExpenseControllerShowUpdateDestroyTest`. `php artisan test` → 266 passed / 0 failed. | Não é TASK-244..255 — correção do débito dos 15 testes com data fixa, bundlada nesta branch a pedido do usuário. Commit `test(backend): congelar relogio ...`. |
| TASK-245 | Concluída | 2026-09-02 | IA (sessão) | Editado `pay()`/`unpay()` em `ExpenseController.php`: `+cycles_ago` (`nullable\|integer\|min:0`), removido `rejectIfCompetenceClosed`, `resolveQuotaForCurrentCompetence`→`resolveQuotaForCycle`, `sealCycleIfSettled` após `pay`, `unsealIfBroken` após `unpay`. Flip de `test_cannot_pay/unpay_in_a_manually_closed_cycle` → `test_can_...` (agora 200). +4 testes novos (pagar ciclo `closed` via `cycles_ago`, alvo por `cycles_ago`, selagem pós-pagamento, dessela pós-`unpay`). `php artisan test tests/Feature/ExpenseControllerPayTest.php` → 26 passed. `./vendor/bin/pint --dirty` → PASS. `php artisan test` → 270 passed / 0 failed. | Guard de `future` **omitido** (desvia do plan §1): com `cycles_ago >= 0`, `BillingCycle::cycleFor` nunca resolve para `future` — guard seria código morto. `route` de `unpay` inalterada (Laravel injeta o `Request`). |
| TASK-246 + TASK-247 | Concluída | 2026-09-02 | IA (sessão) | **Feitas juntas** (mesmo arquivo, acopladas: os testes de `confirmSettlement` dependem do `summary()` ao vivo). `confirmSettlement()`: `+cycles_ago`, guarda invertida (`$cycleIsClosed` = `closed` OU `closed_manually` ativo; senão 422 "só depois do fechamento"), 422 se selada, `sealCycleIfSettled` no fim. `summary()`: nova precedência selado→congelado / `closed`+`closed_manually` não-selado→**ao vivo** com selagem preguiçosa / resto igual; `+cycle.settled`. `sealCycleIfSettled` → `: bool` + guarda de ciclo vazio (não sela ciclo sem despesa/acerto — travaria `reopen()`). `cycleHistory()` `+whereNotNull('settled_at')`. `reopen()` recusa 422 se selado. `close()` sela se já quitado (devolve `closed`+`settled:true`), senão `closed_manually`+`settled:false`. `grossDebts()` `+cycle.settled`. Removido `cycleSnapshotFor()`. Testes: flip `test_cannot_confirm_in_a_manually_closed_cycle`→`test_can_...`; `createSettlementBetween` passa a fechar o ciclo (param `closeCycle`); +4 novos (recusa com ciclo aberto, `cycles_ago` p/ ciclo `closed` por data, selagem pós-confirmação, imutabilidade de ciclo selado); reescritos `ExpenseControllerCloseTest::test_summary_..._reflects_live_state`, `ExpenseControllerSummaryTest::test_sealed_cycle_settlements_are_immutable_...`; `ExpenseControllerCycleHistoryTest` fixtures ganham `settled_at`. `php artisan test` → 273 passed / 0 failed. `pint --dirty` → PASS. | Commit único cita as duas tasks (rastreabilidade preservada). `close()` selar ciclo vazio quebrava `reopen` → guarda de ciclo vazio em `sealCycleIfSettled`. |
