import { act, renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { SummaryExpense } from './useGroupCycle';
import { useExpenseFilters } from './useExpenseFilters';

// Isac (500) é o credor do Aluguel e da Internet; João (501), do Mercado; Maria (502) só participa.
const makeExpense = (overrides: Partial<SummaryExpense> = {}): SummaryExpense => ({
  id: 1,
  description: 'Despesa',
  date: '2026-10-01',
  value: 100,
  valuePerPerson: 50,
  paid: false,
  paymentProofUrl: null,
  payerName: 'Isac',
  participants: ['Isac', 'João'],
  participantDetails: [
    { id: 500, name: 'Isac', avatarUrl: null },
    { id: 501, name: 'João', avatarUrl: null }
  ],
  isFixed: false,
  userPayerId: 500,
  userCreatorId: 500,
  ...overrides
});

const aluguel = makeExpense({
  id: 9,
  description: 'Aluguel',
  paid: true,
  isFixed: true,
  participants: ['Isac', 'João', 'Maria'],
  participantDetails: [
    { id: 500, name: 'Isac', avatarUrl: null },
    { id: 501, name: 'João', avatarUrl: null },
    { id: 502, name: 'Maria', avatarUrl: null }
  ]
});
const mercado = makeExpense({
  id: 10,
  description: 'Mercado',
  payerName: 'João',
  userPayerId: 501,
  participants: ['João', 'Maria'],
  participantDetails: [
    { id: 501, name: 'João', avatarUrl: null },
    { id: 502, name: 'Maria', avatarUrl: null }
  ]
});
const internet = makeExpense({ id: 11, description: 'Internet' });

const descriptions = (list: SummaryExpense[]) => list.map(exp => exp.description);

describe('useExpenseFilters', () => {
  it('sem nenhum critério devolve as despesas na mesma ordem em que chegaram', () => {
    // Ordem de chegada propositalmente fora de qualquer ordem natural (id 11, 9, 10).
    const { result } = renderHook(() => useExpenseFilters([internet, aluguel, mercado]));

    expect(descriptions(result.current.filteredExpenses)).toEqual(['Internet', 'Aluguel', 'Mercado']);
  });

  it('lista as pessoas das despesas como opções, sem repetir e em ordem alfabética pt-BR', () => {
    const { result } = renderHook(() => useExpenseFilters([internet, aluguel, mercado]));

    expect(result.current.creditorOptions.map(option => option.name)).toEqual(['Isac', 'João']);
    expect(result.current.debtorOptions.map(option => option.name)).toEqual(['Isac', 'João', 'Maria']);
  });

  it('combina com E entre os filtros e OU dentro de Credor, mantendo a ordem de chegada', () => {
    const { result } = renderHook(() => useExpenseFilters([internet, aluguel, mercado]));

    act(() => result.current.setCreditorKeys(['id:500', 'id:501']));
    expect(descriptions(result.current.filteredExpenses)).toEqual(['Internet', 'Aluguel', 'Mercado']);

    act(() => result.current.setStatusFilter('pending'));
    expect(descriptions(result.current.filteredExpenses)).toEqual(['Internet', 'Mercado']);

    act(() => result.current.setCreditorKeys(['id:500']));
    expect(descriptions(result.current.filteredExpenses)).toEqual(['Internet']);
  });

  it('aplica busca e tipo junto dos demais critérios', () => {
    const { result } = renderHook(() => useExpenseFilters([internet, aluguel, mercado]));

    act(() => result.current.setSearch('alu'));
    expect(descriptions(result.current.filteredExpenses)).toEqual(['Aluguel']);

    act(() => result.current.setSearch(''));
    act(() => result.current.setTypeFilter('variable'));
    expect(descriptions(result.current.filteredExpenses)).toEqual(['Internet', 'Mercado']);
  });

  it('descarta da seleção quem não aparece mais nas despesas e deixa de restringir por essa pessoa', () => {
    const { result, rerender } = renderHook(({ expenses }) => useExpenseFilters(expenses), {
      initialProps: { expenses: [aluguel, mercado, internet] }
    });

    act(() => result.current.setDebtorKeys(['id:502']));
    expect(result.current.selectedDebtors.map(option => option.name)).toEqual(['Maria']);
    expect(descriptions(result.current.filteredExpenses)).toEqual(['Aluguel', 'Mercado']);

    // Outra competência, em que a Maria não participa de nada.
    rerender({ expenses: [internet] });

    expect(result.current.selectedDebtors).toEqual([]);
    expect(descriptions(result.current.filteredExpenses)).toEqual(['Internet']);
  });

  it('em snapshot antigo, sem participantDetails, filtra Devedores pelo nome', () => {
    const semDetalhes = makeExpense({
      id: 12,
      description: 'Água',
      participants: ['Isac', 'Maria'],
      participantDetails: undefined
    });
    const { result } = renderHook(() => useExpenseFilters([semDetalhes, internet]));

    expect(result.current.debtorOptions.map(option => option.key)).toContain('name:Maria');

    act(() => result.current.setDebtorKeys(['name:Maria']));
    expect(descriptions(result.current.filteredExpenses)).toEqual(['Água']);
  });
});
