import { useMemo, useState } from 'react';
import type { SummaryExpense } from './useGroupCycle';
import {
  buildCreditorOptions,
  buildDebtorOptions,
  filterExpenses,
  type FilterOption,
  type StatusFilter,
  type TypeFilter
} from '../utils/expenseFilters';

export type ExpenseFilterControls = {
  search: string;
  setSearch: (value: string) => void;
  typeFilter: TypeFilter;
  setTypeFilter: (value: TypeFilter) => void;
  statusFilter: StatusFilter;
  setStatusFilter: (value: StatusFilter) => void;
  creditorOptions: FilterOption[];
  selectedCreditors: FilterOption[];
  setCreditorKeys: (keys: string[]) => void;
  debtorOptions: FilterOption[];
  selectedDebtors: FilterOption[];
  setDebtorKeys: (keys: string[]) => void;
  /** As despesas recebidas que passam nos cinco critérios, na ordem em que chegaram. */
  filteredExpenses: SummaryExpense[];
};

/**
 * Busca, tipo, credor, devedores e status sobre as despesas de uma competência
 * já carregada — client-side, sem chamada de API. Compartilhado por Despesas e
 * Pagamentos; cada tela tem o seu próprio estado, sem persistência.
 *
 * As opções de Credor e Devedores saem das despesas recebidas. A seleção é
 * derivada delas na renderização: quem não aparece na competência navegada some
 * do campo e do critério, e o `Autocomplete` nunca recebe valor fora das opções.
 * `filteredExpenses` preserva a ordem de entrada: quem chama ordena antes.
 *
 * docs/feature/20261026-organizacao-despesas-pagamentos/plan.md §4, que herda
 * docs/feature/concluidas/202610/20261010-filtros-credor-devedores-status-despesas/plan.md §2.
 */
export function useExpenseFilters(expenses: SummaryExpense[]): ExpenseFilterControls {
  const [search, setSearch] = useState<string>('');
  const [typeFilter, setTypeFilter] = useState<TypeFilter>('all');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
  const [creditorKeys, setCreditorKeys] = useState<string[]>([]);
  const [debtorKeys, setDebtorKeys] = useState<string[]>([]);

  const creditorOptions = useMemo(() => buildCreditorOptions(expenses), [expenses]);
  const debtorOptions = useMemo(() => buildDebtorOptions(expenses), [expenses]);
  const selectedCreditors = useMemo(
    () => creditorOptions.filter(option => creditorKeys.includes(option.key)),
    [creditorOptions, creditorKeys]
  );
  const selectedDebtors = useMemo(
    () => debtorOptions.filter(option => debtorKeys.includes(option.key)),
    [debtorOptions, debtorKeys]
  );

  const filteredExpenses = useMemo(
    () =>
      filterExpenses(expenses, {
        search,
        type: typeFilter,
        status: statusFilter,
        creditorKeys: selectedCreditors.map(option => option.key),
        debtorKeys: selectedDebtors.map(option => option.key)
      }),
    [expenses, search, typeFilter, statusFilter, selectedCreditors, selectedDebtors]
  );

  return {
    search,
    setSearch,
    typeFilter,
    setTypeFilter,
    statusFilter,
    setStatusFilter,
    creditorOptions,
    selectedCreditors,
    setCreditorKeys,
    debtorOptions,
    selectedDebtors,
    setDebtorKeys,
    filteredExpenses
  };
}
