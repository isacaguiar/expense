# Tasks — Editar Tipo de Despesa (com trava para parcelada já iniciada)

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260826

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-001 | Validação + bloqueios de `expense_type`/`installments`/`quotas` em `update()` | backend | plan.md §1 | nenhum | Concluída |
| TASK-002 | Regeneração de quotas ao mudar o tipo | backend | plan.md §2 | nenhum | Concluída |
| TASK-003 | Testes PHPUnit (transições, bloqueios, substituir teste antigo) | backend | plan.md §1, §2 | nenhum | Concluída |
| TASK-004 | Extrair `buildInstallmentQuotas`/`addMonthsClamped` para módulo compartilhado | frontend | plan.md §3 | nenhum | Concluída |
| TASK-005 | Campo Tipo + parcelas + trava de edição em `ExpenseView.tsx` | frontend | plan.md §4 | nenhum | Concluída |
| TASK-006 | Testes frontend (`ExpenseView.test.tsx`, `ExpenseForm.test.tsx`) | frontend | plan.md §3, §4 | nenhum | Concluída |

## Critérios de aceite

- **TASK-001**: `PUT /api/expenses/{id}` aceita `expense_type`/`installments`/`quotas` (`sometimes`); rejeita `expense_type` quando a despesa é `FIXED` (422); rejeita quando `IN_INSTALLMENTS` tem quota paga, qualquer campo do payload (422, mensagem cobrindo "não é possível editar despesa parcelada..."); mantém o bloqueio existente de `total_value` agora cobrindo `expense_type` também.
- **TASK-002**: transição `IN_CASH → IN_INSTALLMENTS` com `installments`/`quotas` válidos recria as quotas corretamente (contagem e soma batendo); transição `IN_INSTALLMENTS → IN_CASH` colapsa pra 1 quota com o valor final; quotas antigas realmente somem do banco (delete, não sobra lixo).
- **TASK-003**: substitui `test_update_ignores_expense_type_installments_and_quotas` por um teste confirmando que `expense_type: 'FIXED'` como alvo é rejeitado (422) e outro confirmando uma transição real aplicada; novos testes cobrindo cada item do critério de TASK-001/002; confirma que `test_update_allows_non_value_changes_when_expense_is_paid` e `test_update_rejects_total_value_change_for_installments_when_any_quota_is_paid` continuam passando sem alteração; `php artisan test` completo sem regressão (baseline: 212 passed).
- **TASK-004**: `frontend/src/utils/installments.ts` existe com `pad`/`addMonthsClamped`/`buildInstallmentQuotas`; `ExpenseForm.tsx` importa de lá; `ExpenseForm.test.tsx` continua verde sem alteração de asserção.
- **TASK-005**: campo Tipo visível e funcional pra despesa não-Fixa, ausente pra Fixa; campo de parcelas condicional; botão "Editar" desabilitado quando parcelada com quota paga; `handleSave()` inclui os campos novos no PUT quando aplicável; `npx tsc --noEmit` sem erro.
- **TASK-006**: `ExpenseView.test.tsx` cobre os cenários de TASK-005; suíte completa (`ExpenseView.test.tsx` + `ExpenseForm.test.tsx`) verde.
