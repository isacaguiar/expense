import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PayableSettlementList from './PayableSettlementList';
import { settlementLines } from './settlementLines.testHelper';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';

/**
 * Feature 20261112-ajustes-de-texto-participantes-e-pagamentos (TASK-611): o helper que os testes de
 * Pagamentos usam para ler a **ordem** das linhas de acerto sem depender da frase "deve pagar" (que saiu do
 * arranjo "route"). Devolve "Pagador → Credor R$ N", uma por cartão, na ordem do DOM.
 */

const balances: SummaryBalance[] = [
  { user_id: 1, name: 'Carla', balance: 0 },
  { user_id: 2, name: 'Ana', balance: 0 },
  { user_id: 3, name: 'Diego', balance: 0 }
];

const settle = (from: number, to: number, amount: number): SummarySettlement => ({
  from_user_id: from,
  to_user_id: to,
  amount,
  confirmedProofUrl: null,
  confirmedAt: null
});

const noop = () => {};

function renderRoute(settlements: SummarySettlement[], rowLayout: 'route' | 'sentence' = 'route') {
  return render(
    <PayableSettlementList
      settlements={settlements}
      balances={balances}
      currentUserId={null}
      canConfirm
      cycleSettled={false}
      onPayWithPix={noop}
      onSendProof={noop}
      rowLayout={rowLayout}
    />
  );
}

describe('settlementLines', () => {
  it('returns "Pagador → Credor R$ N" for each card, in the order of the DOM', () => {
    renderRoute([settle(1, 2, 60), settle(3, 2, 90), settle(3, 1, 40)]);

    expect(settlementLines()).toEqual(['Carla → Ana R$ 60,00', 'Diego → Ana R$ 90,00', 'Diego → Carla R$ 40,00']);
  });

  it('follows the order of the cards, not the alphabet or the amount', () => {
    renderRoute([settle(3, 1, 10), settle(1, 2, 90)]);

    expect(settlementLines()).toEqual(['Diego → Carla R$ 10,00', 'Carla → Ana R$ 90,00']);
  });

  it('formats the amount in pt-BR, with the thousands separator', () => {
    renderRoute([settle(1, 2, 1234.5)]);

    expect(settlementLines()).toEqual(['Carla → Ana R$ 1.234,50']);
  });

  it('reads only inside the root it is given', () => {
    const first = renderRoute([settle(1, 2, 10)]);
    const second = renderRoute([settle(3, 2, 20)]);

    expect(settlementLines(first.container)).toEqual(['Carla → Ana R$ 10,00']);
    expect(settlementLines(second.container)).toEqual(['Diego → Ana R$ 20,00']);
    expect(settlementLines()).toEqual(['Carla → Ana R$ 10,00', 'Diego → Ana R$ 20,00']);
  });

  it('returns an empty list when there are no cards of the "route" layout', () => {
    renderRoute([settle(1, 2, 10)], 'sentence');

    expect(settlementLines()).toEqual([]);
  });
});
