import { describe, expect, it } from 'vitest';
import type { SummarySettlement } from '../hooks/useGroupCycle';
import { orderSettlementsForViewer } from './cycleOrdering';

/**
 * Feature 20261109-responsavel-do-devedor (TASK-592, plan §10): `alsoFirstIds` põe as saídas de quem o
 * usuário paga logo depois das próprias e antes das demais, mantendo o critério de hoje dentro de
 * cada grupo. Arquivo próprio: `cycleOrdering.test.ts` não é editado.
 */

const NAMES: Record<number, string> = { 1: 'Ana', 2: 'Bruno', 3: 'Carla', 4: 'Diego', 5: 'Elisa', 6: 'Fábio' };
const nameFor = (id: number): string => NAMES[id] ?? 'Desconhecido';

const settle = (from: number, to: number, amount: number): SummarySettlement => ({
  from_user_id: from,
  to_user_id: to,
  amount,
  confirmedProofUrl: null,
  confirmedAt: null
});

/** Lê a ordem como "devedor→credor". */
const order = (list: SummarySettlement[]): string[] => list.map(s => `${s.from_user_id}→${s.to_user_id}`);

describe('orderSettlementsForViewer com alsoFirstIds', () => {
  it('puts the own outgoing first, then the ones of who the user pays for, then the others', () => {
    const settlements = [settle(4, 5, 500), settle(2, 5, 30), settle(1, 5, 10), settle(3, 5, 400)];

    const result = orderSettlementsForViewer(settlements, 1, nameFor, [2]);

    expect(order(result)).toEqual(['1→5', '2→5', '4→5', '3→5']);
  });

  it('keeps today criterion inside each group: the debtor with the larger total comes first', () => {
    const settlements = [settle(6, 5, 700), settle(2, 5, 10), settle(3, 5, 90), settle(4, 5, 20), settle(1, 5, 5)];

    const result = orderSettlementsForViewer(settlements, 1, nameFor, [2, 3]);

    // Próprio; depois quem o usuário paga (Carla 90 antes de Bruno 10); depois os demais (Fábio 700, Diego 20).
    expect(order(result)).toEqual(['1→5', '3→5', '2→5', '6→5', '4→5']);
  });

  it('orders by the debtor total, then amount, inside the group of who the user pays for', () => {
    const settlements = [settle(2, 5, 20), settle(2, 3, 50), settle(3, 5, 60)];

    const result = orderSettlementsForViewer(settlements, 1, nameFor, [2, 3]);

    // Bruno soma 70 e Carla 60; os dois acertos de Bruno vão do maior para o menor.
    expect(order(result)).toEqual(['2→3', '2→5', '3→5']);
  });

  it('keeps a dependent settlement that the user receives with the dependent, ahead of the others', () => {
    const settlements = [settle(4, 5, 500), settle(2, 1, 5)];

    const result = orderSettlementsForViewer(settlements, 1, nameFor, [2]);

    expect(order(result)).toEqual(['2→1', '4→5']);
  });

  it('does not duplicate or reorder anything when the user id is also in alsoFirstIds', () => {
    const settlements = [settle(2, 5, 30), settle(1, 5, 10)];

    const result = orderSettlementsForViewer(settlements, 1, nameFor, [1, 2]);

    expect(order(result)).toEqual(['1→5', '2→5']);
  });

  it('gives the same order as today when alsoFirstIds is omitted, empty, or has nobody present', () => {
    const settlements = [settle(4, 5, 20), settle(2, 5, 30), settle(1, 5, 10), settle(3, 5, 400)];
    const expected = ['1→5', '3→5', '2→5', '4→5'];

    expect(order(orderSettlementsForViewer(settlements, 1, nameFor))).toEqual(expected);
    expect(order(orderSettlementsForViewer(settlements, 1, nameFor, []))).toEqual(expected);
    expect(order(orderSettlementsForViewer(settlements, 1, nameFor, [99]))).toEqual(expected);
  });

  it('does not mutate the argument nor alsoFirstIds', () => {
    const settlements = Object.freeze([settle(4, 5, 500), settle(2, 5, 30), settle(1, 5, 10)]);
    const alsoFirst = Object.freeze([2]);

    const result = orderSettlementsForViewer(settlements, 1, nameFor, alsoFirst);

    expect(result).not.toBe(settlements);
    expect(order([...settlements])).toEqual(['4→5', '2→5', '1→5']);
    expect(alsoFirst).toEqual([2]);
  });
});
