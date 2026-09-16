/**
 * Tipos do recurso Despesa compartilhados entre telas.
 *
 * Item de backlog 003 · docs/feature/20260912-expense-view-tipo-e-pagadores/plan.md §4
 */

/**
 * ENUM `ex_expenses.expense_type`. Estava declarado idêntico em três lugares
 * (`ExpenseForm`, `ExpenseView` e, com outro nome — `SummaryExpenseType` —, em
 * `hooks/useGroupCycle.ts`).
 *
 * - `IN_CASH`: à vista, uma Quota só.
 * - `IN_INSTALLMENTS`: parcelada, N Quotas criadas de uma vez pelo cliente.
 * - `FIXED`: recorrente mensal; nasce **sem** Quota, que é materializada sob
 *   demanda quando alguém marca a competência como paga.
 */
export type ExpenseType = 'IN_CASH' | 'IN_INSTALLMENTS' | 'FIXED';
