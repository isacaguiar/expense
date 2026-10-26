import { describe, expect, it } from 'vitest';
import type { SummaryBalance, SummaryExpense, SummarySettlement } from '../hooks/useGroupCycle';
import {
  isSettlementConfirmed,
  orderSettlementsForViewer,
  sortBalancesForViewer,
  sortExpensesByOccurrence,
  splitSettlementsByConfirmation
} from './cycleOrdering';

// Valores esperados abaixo foram derivados à mão dos exemplos do specify
// (docs/feature/20261026-organizacao-despesas-pagamentos/specify.md §5.1 a §5.3),
// nunca calculados pelo código sob teste.

const makeExpense = (overrides: Partial<SummaryExpense> = {}): SummaryExpense => ({
  id: 1,
  description: 'Despesa',
  date: '2026-10-01',
  value: 100,
  valuePerPerson: 50,
  paid: false,
  paymentProofUrl: null,
  payerName: 'Ana',
  participants: ['Ana', 'Bruno'],
  isFixed: false,
  userPayerId: 1,
  userCreatorId: 1,
  ...overrides
});

const makeBalance = (user_id: number, name: string, balance: number): SummaryBalance => ({ user_id, name, balance });

const makeSettlement = (
  from_user_id: number,
  to_user_id: number,
  amount: number,
  overrides: Partial<SummarySettlement> = {}
): SummarySettlement => ({
  from_user_id,
  to_user_id,
  amount,
  confirmedProofUrl: null,
  confirmedAt: null,
  ...overrides
});

const CONFIRMED_AT = '2026-08-20T10:00:00-03:00';

describe('sortExpensesByOccurrence', () => {
  it('ordena pela data da ocorrência, da mais recente para a mais antiga, sem priorizar pendentes (§5.1)', () => {
    // Ordem de hoje do backend: pendentes primeiro, data crescente.
    const doBackend = [
      makeExpense({ id: 25, description: 'Sofá 3/6', date: '2026-09-20', paid: false }),
      makeExpense({ id: 31, description: 'Mercado', date: '2026-10-03', paid: false }),
      makeExpense({ id: 30, description: 'Luz', date: '2026-09-12', paid: true }),
      makeExpense({ id: 28, description: 'Internet', date: '2026-10-03', paid: true })
    ];

    const ordenadas = sortExpensesByOccurrence(doBackend);

    expect(ordenadas.map(exp => exp.description)).toEqual(['Mercado', 'Internet', 'Sofá 3/6', 'Luz']);
    // Pagas e pendentes ficam intercaladas: o status não decide a posição.
    expect(ordenadas.map(exp => exp.paid)).toEqual([false, true, false, true]);
  });

  it('desempata a mesma data pelo id da despesa, do maior para o menor', () => {
    const ordenadas = sortExpensesByOccurrence([
      makeExpense({ id: 3, date: '2026-10-03' }),
      makeExpense({ id: 7, date: '2026-10-03' }),
      makeExpense({ id: 5, date: '2026-10-03' })
    ]);

    expect(ordenadas.map(exp => exp.id)).toEqual([7, 5, 3]);
  });

  it('desempata duas parcelas da mesma despesa pelo número da parcela, tratando ausente como 0', () => {
    const ordenadas = sortExpensesByOccurrence([
      makeExpense({ id: 4, date: '2026-10-03', installmentNumber: 1 }),
      makeExpense({ id: 4, date: '2026-10-03' }),
      makeExpense({ id: 4, date: '2026-10-03', installmentNumber: 2 })
    ]);

    expect(ordenadas.map(exp => exp.installmentNumber)).toEqual([2, 1, undefined]);
  });

  it('compara datas de meses e anos diferentes pela data inteira', () => {
    const ordenadas = sortExpensesByOccurrence([
      makeExpense({ id: 1, date: '2025-12-31' }),
      makeExpense({ id: 2, date: '2026-01-01' }),
      makeExpense({ id: 3, date: '2026-01-31' })
    ]);

    expect(ordenadas.map(exp => exp.date)).toEqual(['2026-01-31', '2026-01-01', '2025-12-31']);
  });

  it('devolve uma lista nova e não altera a recebida (o resumo é estado do hook)', () => {
    const recebida = Object.freeze([
      makeExpense({ id: 1, date: '2026-10-01' }),
      makeExpense({ id: 2, date: '2026-10-02' })
    ]);

    const ordenadas = sortExpensesByOccurrence(recebida);

    expect(ordenadas).not.toBe(recebida);
    expect(recebida.map(exp => exp.id)).toEqual([1, 2]);
    expect(ordenadas.map(exp => exp.id)).toEqual([2, 1]);
  });

  it('devolve lista vazia para lista vazia', () => {
    expect(sortExpensesByOccurrence([])).toEqual([]);
  });
});

describe('sortBalancesForViewer', () => {
  // Soma zero, como o rateio: Ana +90 · Bruno +90 · Carla −30 · Diego 0 · Eva −45 · Fábio −105.
  const balances = [
    makeBalance(6, 'Fábio', -105),
    makeBalance(1, 'Ana', 90),
    makeBalance(4, 'Diego', 0),
    makeBalance(3, 'Carla', -30),
    makeBalance(2, 'Bruno', 90),
    makeBalance(5, 'Eva', -45)
  ];
  const names = (list: SummaryBalance[]) => list.map(balance => balance.name);

  it('põe a logada com saldo negativo primeiro e ordena os demais do maior saldo para o menor (§5.2)', () => {
    expect(names(sortBalancesForViewer(balances, 3))).toEqual(['Carla', 'Ana', 'Bruno', 'Diego', 'Eva', 'Fábio']);
  });

  it('põe a logada com saldo positivo primeiro, e os negativos seguem do menos negativo ao mais negativo (§5.2)', () => {
    expect(names(sortBalancesForViewer(balances, 1))).toEqual(['Ana', 'Bruno', 'Diego', 'Carla', 'Eva', 'Fábio']);
  });

  it('sem usuário logado (nulo, indefinido ou fora da lista) usa só a regra dos demais, sem erro', () => {
    const semLogado = ['Ana', 'Bruno', 'Diego', 'Carla', 'Eva', 'Fábio'];

    expect(names(sortBalancesForViewer(balances, null))).toEqual(semLogado);
    expect(names(sortBalancesForViewer(balances, undefined))).toEqual(semLogado);
    expect(names(sortBalancesForViewer(balances, 99))).toEqual(semLogado);
  });

  it('desempata saldos iguais pelo nome em pt-BR, com "Álvaro" antes de "Bruno"', () => {
    const empatados = [makeBalance(10, 'Bruno', 10), makeBalance(11, 'Álvaro', 10)];

    expect(names(sortBalancesForViewer(empatados, null))).toEqual(['Álvaro', 'Bruno']);
  });

  it('desempata nomes iguais pelo id do usuário, do menor para o maior', () => {
    const homonimos = [makeBalance(9, 'Ana', 10), makeBalance(8, 'Ana', 10)];

    expect(sortBalancesForViewer(homonimos, null).map(balance => balance.user_id)).toEqual([8, 9]);
  });

  it('compara saldos em centavos: diferença abaixo de um centavo é empate e cai no nome', () => {
    // Por valor bruto, "Zé" (10,004) viria antes de "Ana" (10,001); em centavos os dois valem 1000.
    const quaseIguais = [makeBalance(1, 'Zé', 10.004), makeBalance(2, 'Ana', 10.001)];

    expect(names(sortBalancesForViewer(quaseIguais, null))).toEqual(['Ana', 'Zé']);
  });

  it('trata -0 e 0 como zero, empatados, e ordena pelo nome', () => {
    const zeros = [makeBalance(1, 'Zita', 0), makeBalance(2, 'Beto', -0)];

    expect(names(sortBalancesForViewer(zeros, null))).toEqual(['Beto', 'Zita']);
  });

  it('devolve uma lista nova e não altera a recebida', () => {
    const recebida = Object.freeze([makeBalance(2, 'Bruno', 5), makeBalance(1, 'Ana', 50)]);

    const ordenados = sortBalancesForViewer(recebida, null);

    expect(ordenados).not.toBe(recebida);
    expect(recebida.map(balance => balance.name)).toEqual(['Bruno', 'Ana']);
  });
});

describe('isSettlementConfirmed', () => {
  it('é confirmado quando há confirmedAt, mesmo sem URL de comprovante', () => {
    expect(isSettlementConfirmed(makeSettlement(1, 2, 10, { confirmedAt: CONFIRMED_AT, confirmedProofUrl: null }))).toBe(
      true
    );
  });

  it('não é confirmado sem confirmedAt, mesmo com URL de comprovante', () => {
    expect(
      isSettlementConfirmed(makeSettlement(1, 2, 10, { confirmedAt: null, confirmedProofUrl: 'http://api/proofs/9' }))
    ).toBe(false);
  });

  it('não é confirmado com confirmedAt vazio', () => {
    expect(isSettlementConfirmed(makeSettlement(1, 2, 10, { confirmedAt: '' }))).toBe(false);
  });
});

describe('splitSettlementsByConfirmation', () => {
  it('separa por confirmedAt, preservando a ordem de entrada dentro de cada lista', () => {
    const entrada = [
      makeSettlement(1, 2, 10, { confirmedAt: CONFIRMED_AT, confirmedProofUrl: 'http://api/proofs/1' }),
      makeSettlement(3, 2, 20),
      makeSettlement(4, 2, 30, { confirmedAt: CONFIRMED_AT }),
      makeSettlement(5, 2, 40, { confirmedProofUrl: 'http://api/proofs/9' }),
      makeSettlement(6, 2, 50)
    ];

    const { toPay, paid } = splitSettlementsByConfirmation(entrada);

    expect(toPay.map(s => s.from_user_id)).toEqual([3, 5, 6]);
    expect(paid.map(s => s.from_user_id)).toEqual([1, 4]);
  });

  it('não perde nem repete acerto: a soma das contagens e dos valores é a da entrada', () => {
    const entrada = [
      makeSettlement(1, 2, 10.5, { confirmedAt: CONFIRMED_AT }),
      makeSettlement(3, 2, 20.25),
      makeSettlement(4, 2, 30)
    ];

    const { toPay, paid } = splitSettlementsByConfirmation(entrada);

    expect(toPay.length + paid.length).toBe(3);
    expect([...toPay, ...paid].reduce((soma, s) => soma + s.amount, 0)).toBe(60.75);
  });

  it('devolve duas listas vazias para null e undefined (snapshot antigo sem settlements)', () => {
    expect(splitSettlementsByConfirmation(null)).toEqual({ toPay: [], paid: [] });
    expect(splitSettlementsByConfirmation(undefined)).toEqual({ toPay: [], paid: [] });
  });
});

describe('orderSettlementsForViewer', () => {
  // Membros do §5.3. A logada é a Carla (3).
  const nomes: Record<number, string> = { 1: 'Ana', 2: 'Bruno', 3: 'Carla', 4: 'Diego', 5: 'Eva', 6: 'Álvaro' };
  const nameFor = (userId: number): string => nomes[userId] ?? 'Desconhecido';
  const pares = (lista: SummarySettlement[]) => lista.map(s => [s.from_user_id, s.to_user_id]);

  it('ordena a aba "Valores a pagar" do §5.3: saída da logada, depois devedores por total da aba', () => {
    const aPagar = [
      makeSettlement(5, 2, 70), // Eva → Bruno
      makeSettlement(4, 3, 40), // Diego → Carla (a receber da logada)
      makeSettlement(3, 1, 60), // Carla → Ana
      makeSettlement(4, 1, 90) // Diego → Ana
    ];

    // Carla→Ana 60 · Diego (total 130): →Ana 90, →Carla 40 · Eva (total 70): →Bruno 70
    expect(pares(orderSettlementsForViewer(aPagar, 3, nameFor))).toEqual([
      [3, 1],
      [4, 1],
      [4, 3],
      [5, 2]
    ]);
  });

  it('ordena a aba "Valores pagos" do §5.3: o total do devedor é o da própria aba', () => {
    const pagos = [
      makeSettlement(2, 4, 10, { confirmedAt: CONFIRMED_AT }), // Bruno → Diego
      makeSettlement(5, 3, 20, { confirmedAt: CONFIRMED_AT }), // Eva → Carla (recebido pela logada)
      makeSettlement(3, 2, 25, { confirmedAt: CONFIRMED_AT }), // Carla → Bruno
      makeSettlement(5, 1, 30, { confirmedAt: CONFIRMED_AT }) // Eva → Ana
    ];

    // Carla→Bruno 25 · Eva (total 50): →Ana 30, →Carla 20 · Bruno (total 10): →Diego 10
    expect(pares(orderSettlementsForViewer(pagos, 3, nameFor))).toEqual([
      [3, 2],
      [5, 1],
      [5, 3],
      [2, 4]
    ]);
  });

  it('ordena os devedores pela soma dos acertos dele, não pelo maior acerto individual', () => {
    const lista = [
      makeSettlement(4, 1, 100), // Diego: um acerto de 100
      makeSettlement(5, 1, 60), // Eva: dois acertos, 60 + 60 = 120
      makeSettlement(5, 2, 60)
    ];

    expect(pares(orderSettlementsForViewer(lista, 3, nameFor)).map(([from]) => from)).toEqual([5, 5, 4]);
  });

  it('põe todas as saídas da logada antes dos demais, do maior valor para o menor, mesmo que outro devedor deva mais', () => {
    const lista = [
      makeSettlement(4, 1, 500), // Diego deve muito
      makeSettlement(3, 2, 5), // Carla → Bruno
      makeSettlement(3, 1, 8) // Carla → Ana
    ];

    expect(pares(orderSettlementsForViewer(lista, 3, nameFor))).toEqual([
      [3, 1],
      [3, 2],
      [4, 1]
    ]);
  });

  it('desempata devedores de mesmo total pelo nome em pt-BR e, se o nome repetir, pelo id', () => {
    const porNome = [makeSettlement(2, 1, 50), makeSettlement(6, 1, 50)]; // Bruno e Álvaro, 50 cada

    expect(orderSettlementsForViewer(porNome, 3, nameFor).map(s => s.from_user_id)).toEqual([6, 2]);

    const homonimos = (id: number) => (id === 7 || id === 8 ? 'Igual' : nameFor(id));
    const porId = [makeSettlement(8, 1, 50), makeSettlement(7, 1, 50)];

    expect(orderSettlementsForViewer(porId, 3, homonimos).map(s => s.from_user_id)).toEqual([7, 8]);
  });

  it('dentro do mesmo devedor, desempata valores iguais pelo nome do credor e depois pelo id', () => {
    const lista = [makeSettlement(4, 2, 30), makeSettlement(4, 1, 30), makeSettlement(4, 6, 30)];

    // Credores Bruno (2), Ana (1) e Álvaro (6): Álvaro, Ana, Bruno.
    expect(orderSettlementsForViewer(lista, 3, nameFor).map(s => s.to_user_id)).toEqual([6, 1, 2]);
  });

  it('começa pelos demais quando a logada não tem saída na aba', () => {
    const lista = [makeSettlement(5, 2, 10), makeSettlement(4, 3, 40)];

    expect(pares(orderSettlementsForViewer(lista, 3, nameFor))).toEqual([
      [4, 3],
      [5, 2]
    ]);
  });

  it('sem usuário logado trata todos como demais devedores', () => {
    const lista = [makeSettlement(3, 1, 60), makeSettlement(4, 1, 90)];

    expect(pares(orderSettlementsForViewer(lista, null, nameFor))).toEqual([
      [4, 1],
      [3, 1]
    ]);
  });

  it('devolve uma lista nova e não altera a recebida', () => {
    const recebida = Object.freeze([makeSettlement(5, 2, 70), makeSettlement(3, 1, 60)]);

    const ordenada = orderSettlementsForViewer(recebida, 3, nameFor);

    expect(ordenada).not.toBe(recebida);
    expect(pares([...recebida])).toEqual([
      [5, 2],
      [3, 1]
    ]);
  });
});
