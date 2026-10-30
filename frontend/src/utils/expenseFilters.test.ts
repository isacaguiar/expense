import { describe, expect, it } from 'vitest';
import type { SummaryExpense } from '../hooks/useGroupCycle';
import {
  buildCreditorOptions,
  buildDebtorOptions,
  creditorOf,
  debtorsOf,
  filterExpenses,
  type ExpenseFilters
} from './expenseFilters';

// Despesa completa, como o `summary` entrega: Isac (500) é o credor e rateia
// com João (501) e Maria (502). Cada teste sobrescreve só o que importa.
const makeExpense = (overrides: Partial<SummaryExpense> = {}): SummaryExpense => ({
  id: 1,
  description: 'Aluguel',
  date: '2026-08-01',
  value: 300,
  valuePerPerson: 100,
  paid: false,
  paymentProofUrl: null,
  payerName: 'Isac',
  payerAvatarUrl: null,
  participants: ['Isac', 'João', 'Maria'],
  participantDetails: [
    { id: 500, name: 'Isac', avatarUrl: null },
    { id: 501, name: 'João', avatarUrl: null },
    { id: 502, name: 'Maria', avatarUrl: null }
  ],
  isFixed: false,
  userPayerId: 500,
  userCreatorId: 500,
  ...overrides
});

describe('creditorOf', () => {
  it('identifica o credor pelo id do usuário pagador e exibe o nome dele', () => {
    expect(creditorOf(makeExpense())).toEqual({ key: 'id:500', name: 'Isac' });
  });

  it('mostra "Sem nome" quando a despesa não traz o nome do credor, mantendo a chave', () => {
    expect(creditorOf(makeExpense({ payerName: null }))).toEqual({ key: 'id:500', name: 'Sem nome' });
  });
});

describe('debtorsOf', () => {
  it('lista todos os participantes pelo id, o credor incluído', () => {
    expect(debtorsOf(makeExpense())).toEqual([
      { key: 'id:500', name: 'Isac' },
      { key: 'id:501', name: 'João' },
      { key: 'id:502', name: 'Maria' }
    ]);
  });

  it('cai nos nomes quando o snapshot de ciclo selado não tem participantDetails', () => {
    const sealed = makeExpense({ participantDetails: undefined, participants: ['Isac', '501'] });

    expect(debtorsOf(sealed)).toEqual([
      { key: 'name:Isac', name: 'Isac' },
      { key: 'name:501', name: '501' }
    ]);
  });
});

describe('buildCreditorOptions', () => {
  it('devolve cada credor uma vez só, mesmo com várias despesas dele', () => {
    const expenses = [
      makeExpense({ id: 1, userPayerId: 501, payerName: 'João' }),
      makeExpense({ id: 2, userPayerId: 500, payerName: 'Isac' }),
      makeExpense({ id: 3, userPayerId: 501, payerName: 'João' })
    ];

    expect(buildCreditorOptions(expenses)).toEqual([
      { key: 'id:500', name: 'Isac' },
      { key: 'id:501', name: 'João' }
    ]);
  });

  it('ordena pelo nome em pt-BR, com acento entrando na letra base ("Álvaro" antes de "Bruno")', () => {
    const expenses = [
      makeExpense({ id: 1, userPayerId: 503, payerName: 'Bruno' }),
      makeExpense({ id: 2, userPayerId: 504, payerName: 'Álvaro' })
    ];

    expect(buildCreditorOptions(expenses).map(o => o.name)).toEqual(['Álvaro', 'Bruno']);
  });

  it('devolve lista vazia quando a competência não tem despesas', () => {
    expect(buildCreditorOptions([])).toEqual([]);
  });
});

describe('buildDebtorOptions', () => {
  it('reúne os participantes de todas as despesas sem repetir ninguém, o credor incluído', () => {
    const expenses = [
      makeExpense({
        id: 1,
        participantDetails: [
          { id: 501, name: 'João', avatarUrl: null },
          { id: 502, name: 'Maria', avatarUrl: null }
        ]
      }),
      makeExpense({
        id: 2,
        participantDetails: [
          { id: 500, name: 'Isac', avatarUrl: null },
          { id: 502, name: 'Maria', avatarUrl: null }
        ]
      })
    ];

    expect(buildDebtorOptions(expenses)).toEqual([
      { key: 'id:500', name: 'Isac' },
      { key: 'id:501', name: 'João' },
      { key: 'id:502', name: 'Maria' }
    ]);
  });

  it('monta as opções por nome numa competência de snapshot, sem participantDetails', () => {
    const expenses = [
      makeExpense({ id: 1, participantDetails: undefined, participants: ['Maria', 'Isac'] }),
      makeExpense({ id: 2, participantDetails: undefined, participants: ['Isac', 'João'] })
    ];

    expect(buildDebtorOptions(expenses)).toEqual([
      { key: 'name:Isac', name: 'Isac' },
      { key: 'name:João', name: 'João' },
      { key: 'name:Maria', name: 'Maria' }
    ]);
  });
});

describe('filterExpenses', () => {
  // Isac (500) é credor de Aluguel e Internet; João (501), de Mercado.
  //   9  Aluguel  fixa      credor Isac   Isac, João, Maria  paga
  //   10 Mercado  variável  credor João   João, Maria        pendente
  //   11 Internet variável  credor Isac   Isac, João         pendente
  const isac = { id: 500, name: 'Isac', avatarUrl: null };
  const joao = { id: 501, name: 'João', avatarUrl: null };
  const maria = { id: 502, name: 'Maria', avatarUrl: null };

  const aluguel = makeExpense({
    id: 9,
    description: 'Aluguel',
    isFixed: true,
    paid: true,
    participantDetails: [isac, joao, maria]
  });
  const mercado = makeExpense({
    id: 10,
    description: 'Mercado',
    userPayerId: 501,
    payerName: 'João',
    participantDetails: [joao, maria]
  });
  const internet = makeExpense({
    id: 11,
    description: 'Internet',
    participantDetails: [isac, joao]
  });
  const expenses = [aluguel, mercado, internet];

  const noFilters: ExpenseFilters = { search: '', type: 'all', status: 'all', creditorKeys: [], debtorKeys: [] };
  const idsFor = (filters: Partial<ExpenseFilters>, list = expenses): number[] =>
    filterExpenses(list, { ...noFilters, ...filters }).map(exp => exp.id);

  it('sem nenhum critério devolve a lista inteira, na mesma ordem', () => {
    expect(idsFor({})).toEqual([9, 10, 11]);
  });

  it('busca pela descrição sem diferenciar maiúsculas de minúsculas', () => {
    expect(idsFor({ search: 'MERC' })).toEqual([10]);
  });

  it('filtra por tipo: fixas só as fixas, variáveis só as não fixas', () => {
    expect(idsFor({ type: 'fixed' })).toEqual([9]);
    expect(idsFor({ type: 'variable' })).toEqual([10, 11]);
  });

  it('filtra por status pelo campo paid', () => {
    expect(idsFor({ status: 'paid' })).toEqual([9]);
    expect(idsFor({ status: 'pending' })).toEqual([10, 11]);
  });

  it('filtra por credor, e vários credores valem como OU', () => {
    expect(idsFor({ creditorKeys: ['id:501'] })).toEqual([10]);
    expect(idsFor({ creditorKeys: ['id:500', 'id:501'] })).toEqual([9, 10, 11]);
  });

  it('filtra por devedor, e vários devedores valem como OU', () => {
    expect(idsFor({ debtorKeys: ['id:502'] })).toEqual([9, 10]);
    expect(idsFor({ debtorKeys: ['id:502', 'id:500'] })).toEqual([9, 10, 11]);
  });

  it('conta o credor como devedor da própria despesa: João aparece em Mercado, que ele pagou', () => {
    expect(idsFor({ debtorKeys: ['id:501'] })).toEqual([9, 10, 11]);
  });

  it('combina os critérios com E: só passa quem atende a todos', () => {
    expect(idsFor({ creditorKeys: ['id:500'], status: 'pending' })).toEqual([11]);
    expect(idsFor({ creditorKeys: ['id:500'], debtorKeys: ['id:502'] })).toEqual([9]);
    expect(idsFor({ creditorKeys: ['id:500'], debtorKeys: ['id:502'], search: 'net' })).toEqual([]);
  });

  it('filtra devedor por nome num snapshot sem participantDetails', () => {
    const sealed = expenses.map(exp => ({
      ...exp,
      participantDetails: undefined,
      participants: exp.participantDetails?.map(p => p.name) ?? []
    }));

    expect(idsFor({ debtorKeys: ['name:Maria'] }, sealed)).toEqual([9, 10]);
  });
});
