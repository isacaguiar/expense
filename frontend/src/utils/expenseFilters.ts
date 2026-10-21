// Filtros da listagem de despesas (ExpenseManager) — funções puras sobre o
// `summary` já carregado, sem chamada de API (docs/feature/20261010-filtros-
// credor-devedores-status-despesas/plan.md §1).

import type { SummaryExpense } from '../hooks/useGroupCycle';

export type FilterOption = { key: string; name: string };

export type TypeFilter = 'all' | 'fixed' | 'variable';
export type StatusFilter = 'all' | 'paid' | 'pending';

export type ExpenseFilters = {
  search: string;
  type: TypeFilter;
  status: StatusFilter;
  creditorKeys: string[];
  debtorKeys: string[];
};

// A chave leva prefixo porque o filtro de Devedores precisa funcionar nos dois
// formatos de payload: com `participantDetails` (chave por id) e sem ele, no
// snapshot de ciclo selado (chave por nome). Sem o prefixo, um nome numérico
// colidiria com o id de outro participante.
const idKey = (id: number): string => `id:${id}`;
const nameKey = (name: string): string => `name:${name}`;

export const creditorOf = (exp: SummaryExpense): FilterOption => ({
  key: idKey(exp.userPayerId),
  name: exp.payerName || 'Sem nome'
});

// Todos os participantes do rateio — o credor incluído (specify.md R1). Mesmo
// critério de `renderDetailPayers` em ExpenseManager: `participantDetails` é a
// fonte preferencial e `participants` (só nomes) cobre o snapshot antigo.
export const debtorsOf = (exp: SummaryExpense): FilterOption[] =>
  exp.participantDetails?.map(p => ({ key: idKey(p.id), name: p.name })) ??
  exp.participants.map(name => ({ key: nameKey(name), name }));

// Opções únicas (a primeira ocorrência de cada chave vence), em ordem alfabética
// pt-BR — `localeCompare` trata "Álvaro" como "A", o que a ordem por código de
// caractere não faz.
const uniqueSorted = (options: FilterOption[]): FilterOption[] => {
  const byKey = new Map<string, FilterOption>();

  options.forEach(option => {
    if (!byKey.has(option.key)) byKey.set(option.key, option);
  });

  return [...byKey.values()].sort((a, b) => a.name.localeCompare(b.name, 'pt-BR'));
};

export const buildCreditorOptions = (expenses: SummaryExpense[]): FilterOption[] =>
  uniqueSorted(expenses.map(creditorOf));

export const buildDebtorOptions = (expenses: SummaryExpense[]): FilterOption[] =>
  uniqueSorted(expenses.flatMap(debtorsOf));

// Cinco critérios combinados com E. Dentro de Credor e de Devedores vale OU
// (passa se algum selecionado casa) e lista vazia = sem restrição (specify.md
// R2 e R3).
export const filterExpenses = (expenses: SummaryExpense[], filters: ExpenseFilters): SummaryExpense[] => {
  const search = filters.search.toLowerCase();
  const creditorKeys = new Set(filters.creditorKeys);
  const debtorKeys = new Set(filters.debtorKeys);

  return expenses.filter(exp => {
    if (!exp.description.toLowerCase().includes(search)) return false;
    if (filters.type === 'fixed' && !exp.isFixed) return false;
    if (filters.type === 'variable' && exp.isFixed) return false;
    if (filters.status === 'paid' && !exp.paid) return false;
    if (filters.status === 'pending' && exp.paid) return false;
    if (creditorKeys.size > 0 && !creditorKeys.has(creditorOf(exp).key)) return false;
    if (debtorKeys.size > 0 && !debtorsOf(exp).some(debtor => debtorKeys.has(debtor.key))) return false;

    return true;
  });
};
