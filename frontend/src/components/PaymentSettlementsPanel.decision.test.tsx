import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';
import PaymentSettlementsPanel from './PaymentSettlementsPanel';

/**
 * Feature 20261110-quem-paga-se-declara-e-credor-recusa (TASK-608): o painel repassa os handlers e o
 * estado de "ocupado" à lista, e o acerto recusado volta para "Valores a pagar" (sem `confirmedAt`).
 */

const BALANCES: SummaryBalance[] = [
  { user_id: 10, name: 'Joana', balance: 0 },
  { user_id: 20, name: 'Carlos', balance: 0 },
  { user_id: 30, name: 'Maria', balance: 0 }
];

const awaiting: SummarySettlement = {
  from_user_id: 10,
  to_user_id: 20,
  amount: 100,
  confirmedAt: '2026-08-20T10:00:00-03:00',
  confirmedProofUrl: 'http://x/p.jpg',
  confirmedBy: { id: 30, name: 'Maria' },
  confirmationStatus: 'awaiting_acceptance'
};

const refused: SummarySettlement = {
  from_user_id: 11,
  to_user_id: 20,
  amount: 50,
  confirmedAt: null,
  confirmedProofUrl: null,
  confirmedBy: { id: 30, name: 'Maria' },
  confirmationStatus: 'refused',
  refusedAt: '2026-08-20T11:00:00-03:00'
};

const BALANCES_ALL = [...BALANCES, { user_id: 11, name: 'Beto', balance: 0 }];

function renderPanel(tab: 'toPay' | 'paid', props: Partial<React.ComponentProps<typeof PaymentSettlementsPanel>> = {}) {
  const handlers = { onAccept: vi.fn(), onRefuse: vi.fn(), onDelete: vi.fn() };
  render(
    <PaymentSettlementsPanel
      settlements={[awaiting, refused]}
      balances={BALANCES_ALL}
      currentUserId={20}
      canConfirm
      cycleSettled={false}
      tab={tab}
      onTabChange={vi.fn()}
      onPayWithPix={vi.fn()}
      onSendProof={vi.fn()}
      {...handlers}
      {...props}
    />
  );
  return handlers;
}

describe('PaymentSettlementsPanel: aceitar, recusar e excluir', () => {
  it('lists the refused settlement under "Valores a pagar" and the one awaiting acceptance under "Valores pagos"', () => {
    renderPanel('toPay');
    expect(screen.getByText('Você recusou o comprovante')).toBeInTheDocument();
    expect(screen.queryByText('Aguardando seu aceite')).not.toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Valores a pagar (1)' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Valores pagos (1)' })).toBeInTheDocument();
  });

  it('passes the handlers to the list: the creditor accepts and refuses from the paid tab', async () => {
    const user = userEvent.setup();
    const { onAccept, onRefuse } = renderPanel('paid');

    expect(screen.getByText('Aguardando seu aceite')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Aceitar' }));
    await user.click(screen.getByRole('button', { name: 'Recusar' }));

    expect(onAccept).toHaveBeenCalledWith(awaiting);
    expect(onRefuse).toHaveBeenCalledWith(awaiting);
  });

  it('passes the busy state to the list', () => {
    renderPanel('paid', { decisionBusy: true });

    expect(screen.getByRole('button', { name: 'Aceitar' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Recusar' })).toBeDisabled();
  });

  it('the debtor deletes through the list, after confirming', async () => {
    const user = userEvent.setup();
    const { onDelete } = renderPanel('paid', { currentUserId: 10 });

    await user.click(screen.getByRole('button', { name: 'Excluir' }));
    const dialog = await screen.findByRole('dialog', { name: 'Excluir comprovante?' });
    await user.click(dialog.querySelectorAll('button')[1]);

    expect(onDelete).toHaveBeenCalledWith(awaiting);
  });
});
