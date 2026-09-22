# Tasks — Notificar credor quando parcela retroativa nasce paga em seu nome

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260922

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-341 | Adicionar `Notifier::expenseBornPaid()` | backend | plan.md §1 | nenhum | Concluída |
| TASK-342 | Chamar `Notifier::expenseBornPaid()` em `ExpenseController::store()` com contador de quotas `born_paid` | backend | plan.md §1 | nenhum | Concluída |
| TASK-343 | Teste que prova a notificação (credor ≠ criador notifica; credor = criador não notifica; `quotasCount` correto) | backend | plan.md §1 | nenhum | Concluída |
| TASK-344 | Adicionar `case 'expense_born_paid'` em `notificationText.ts` | frontend | plan.md §2 | nenhum | Concluída |
| TASK-345 | Teste de `notificationText` para `expense_born_paid` (singular e plural) | frontend | plan.md §2 | nenhum | Concluída |
| TASK-346 | Revisão de segurança (`security-reviewer`) antes do PR | doc | plan.md §1, §2 | antes do merge | Pendente |

## Critérios de aceite

- **TASK-341**: `backend/app/Support/Notifier.php` ganha `public static function expenseBornPaid(Expense $expense, int $bornPaidQuotasCount): void`, seguindo o padrão `guard()`/`fanOut()` dos demais métodos — destinatário é só `user_payer_id`, rejeitado (nenhuma notificação) quando `user_payer_id === user_creator_id`. Payload: `actorName`, `groupId`, `groupName`, `expenseId`, `expenseDescription`, `quotasCount`. `./vendor/bin/pint --test app/Support/Notifier.php` limpo.

- **TASK-342**: em `ExpenseController::store()`, o loop de criação de quotas (`:418-443`) acumula `$bornPaidCount` (incrementado a cada quota com `$bornPaid === true`); logo após `Notifier::expenseCreated($expense);` (`:448`), chama `Notifier::expenseBornPaid($expense, $bornPaidCount)` só quando `$bornPaidCount > 0`. `./vendor/bin/pint --test app/Http/Controllers/ExpenseController.php` limpo.

- **TASK-343**: novo bloco de testes em `backend/tests/Feature/NotifierTriggersTest.php` (mesmo arquivo dos outros gatilhos do `Notifier`) cobrindo, com uma despesa `IN_INSTALLMENTS` cujas primeiras parcelas caem em ciclo fechado (mesmo padrão de `Carbon::setTestNow('2026-09-20')` + `date_payment` em `2026-06-05` já usado em `ExpenseControllerStoreTest::test_installments_expense_starting_in_a_closed_cycle_is_created_with_past_quotas_paid`):
  1. Requisição feita por um membro **diferente** do credor (`user_creator_id !== user_payer_id`) → `Notification::where('type', 'expense_born_paid')` tem exatamente 1 linha, `user_id` = credor, `data['quotasCount']` = número de parcelas nascidas `born_paid`.
  2. Requisição feita pelo **próprio credor** (`user_creator_id === user_payer_id`, cenário já coberto por `ExpenseControllerStoreTest`) → `Notification::where('type', 'expense_born_paid')->count()` é 0.
  `php artisan test --filter=NotifierTriggersTest` verde. Reverter TASK-341/342 localmente faz o caso (1) falhar.

- **TASK-344**: `frontend/src/components/notificationText.ts` ganha `case 'expense_born_paid'`, retornando texto com `actorName`, `expenseDescription` e a contagem/pluralização de `quotasCount` ("1 parcela já paga" vs. "N parcelas já pagas"). `npx tsc --noEmit` sem erro.

- **TASK-345**: novo bloco em `frontend/src/components/notificationText.test.ts` cobrindo `quotasCount: 1` (singular) e `quotasCount: 3` (plural). `npx vitest run src/components/notificationText.test.ts` verde.

- **TASK-346**: agent `security-reviewer` executado sobre o diff final (`ExpenseController.php`, `Notifier.php`) antes de abrir o PR, sem achado bloqueante pendente — achado não-bloqueante vira item de backlog, não trava esta feature.
