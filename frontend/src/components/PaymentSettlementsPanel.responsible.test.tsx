import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PaymentSettlementsPanel from './PaymentSettlementsPanel';
import { settlementLines } from './settlementLines.testHelper';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';

/**
 * Feature 20261109-responsavel-do-devedor (TASK-592, plan §10): o painel repassa `payForUserIds` à lista
 * e à ordenação. Arquivo próprio: `PaymentSettlementsPanel.test.tsx` não é editado.
 */

const ME = 1;
const BRUNO = 2; // dependente
const CARLA = 3; // credora
const DIEGO = 4; // terceiro

const balances: SummaryBalance[] = [
  { user_id: ME, name: 'Ana', balance: 0 },
  { user_id: BRUNO, name: 'Bruno', balance: 0 },
  { user_id: CARLA, name: 'Carla', balance: 0 },
  { user_id: DIEGO, name: 'Diego', balance: 0 }
];

const settle = (from: number, to: number, amount: number): SummarySettlement => ({
  from_user_id: from,
  to_user_id: to,
  amount,
  confirmedProofUrl: null,
  confirmedAt: null
});

// Fora de ordem útil, como o servidor os manda: o terceiro (maior) vem primeiro.
const settlements = [settle(DIEGO, CARLA, 900), settle(BRUNO, CARLA, 10), settle(ME, CARLA, 5)];

const noop = () => {};

function renderPanel(props: Partial<React.ComponentProps<typeof PaymentSettlementsPanel>> = {}) {
  return render(
    <PaymentSettlementsPanel
      settlements={settlements}
      balances={balances}
      currentUserId={ME}
      canConfirm
      cycleSettled={false}
      tab="toPay"
      onTabChange={noop}
      onPayWithPix={noop}
      onSendProof={noop}
      {...props}
    />
  );
}

const lines = () => settlementLines();

describe('PaymentSettlementsPanel: quem o usuário paga', () => {
  it('hands payForUserIds to the list: the badge and the buttons appear on the settlement of who the user pays for', () => {
    renderPanel({ payForUserIds: [BRUNO] });

    expect(screen.getByText('Você paga por Bruno')).toBeInTheDocument();
    // O próprio (Ana) e o dependente (Bruno) têm botões; o terceiro (Diego) não.
    expect(screen.getAllByRole('button', { name: 'Enviar comprovante' })).toHaveLength(2);
  });

  it('orders the own outgoing first, then the ones of who the user pays for, then the others', () => {
    renderPanel({ payForUserIds: [BRUNO] });

    expect(lines()).toEqual([
      expect.stringContaining('Ana →'),
      expect.stringContaining('Bruno →'),
      expect.stringContaining('Diego →')
    ]);
  });

  it('keeps today order and no badge when payForUserIds is not given', () => {
    renderPanel();

    expect(lines()).toEqual([
      expect.stringContaining('Ana →'),
      expect.stringContaining('Diego →'),
      expect.stringContaining('Bruno →')
    ]);
    expect(screen.queryByText(/Você paga por/)).not.toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Enviar comprovante' })).toHaveLength(1);
  });
});
