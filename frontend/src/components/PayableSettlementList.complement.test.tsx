import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { ConfirmationStatus, SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';
import PaymentSettlementsPanel from './PaymentSettlementsPanel';
import PayableSettlementList from './PayableSettlementList';

/**
 * TASK-635 (feature 20261114-historico-de-comprovantes-e-justificativa, plan §12, R9, D7-B): o botão
 * "Adicionar justificativa e comprovante" é só de quem paga pelo devedor, sobre o comprovante `sent` do próprio
 * devedor, e vale também com o ciclo encerrado (não depende de `canConfirm`).
 */
const DEBTOR = 10;
const CREDITOR = 20;
const PAYER = 30; // paga pelo devedor
const THIRD = 40;
const BALANCES: SummaryBalance[] = [
  { user_id: DEBTOR, name: 'Joana', balance: 0 },
  { user_id: CREDITOR, name: 'Carlos', balance: 0 },
  { user_id: PAYER, name: 'Maria', balance: 0 },
  { user_id: THIRD, name: 'Pedro', balance: 0 }
];
const SENT_AT = '2026-08-20T10:00:00-03:00';
const BUTTON = 'Adicionar justificativa e comprovante';

const settlementIn = (status: ConfirmationStatus | null, confirmedBy: SummarySettlement['confirmedBy'] = null): SummarySettlement => ({
  from_user_id: DEBTOR,
  to_user_id: CREDITOR,
  amount: 100,
  confirmedAt: status && status !== 'refused' ? SENT_AT : null,
  confirmedProofUrl: status && status !== 'refused' ? 'http://x/p.jpg' : null,
  confirmedBy,
  confirmationStatus: status
});

type Viewer = 'payer' | 'debtor' | 'creditor' | 'third';
const VIEWER: Record<Viewer, number> = { payer: PAYER, debtor: DEBTOR, creditor: CREDITOR, third: THIRD };

function renderList(settlement: SummarySettlement, viewer: Viewer, props: Partial<React.ComponentProps<typeof PayableSettlementList>> = {}) {
  const onComplement = vi.fn();
  render(
    <PayableSettlementList
      settlements={[settlement]}
      balances={BALANCES}
      currentUserId={VIEWER[viewer]}
      canConfirm
      cycleSettled={false}
      payForUserIds={viewer === 'payer' ? [DEBTOR] : []}
      onPayWithPix={vi.fn()}
      onSendProof={vi.fn()}
      onComplement={onComplement}
      {...props}
    />
  );
  return { onComplement };
}

const button = () => screen.queryByRole('button', { name: BUTTON });

describe('PayableSettlementList: Adicionar justificativa e comprovante', () => {
  it('shows for who pays for the debtor, over the debtor’s own sent proof, and calls onComplement with the settlement', async () => {
    const settlement = settlementIn('sent');
    const { onComplement } = renderList(settlement, 'payer');

    await userEvent.click(button()!);

    expect(onComplement).toHaveBeenCalledWith(settlement);
  });

  it.each(['debtor', 'creditor', 'third'] as const)('does not show for %s', viewer => {
    renderList(settlementIn('sent'), viewer);

    expect(button()).not.toBeInTheDocument();
  });

  it('does not show for a payer who pays for someone else', () => {
    renderList(settlementIn('sent'), 'payer', { payForUserIds: [999] });

    expect(button()).not.toBeInTheDocument();
  });

  it.each(['awaiting_acceptance', 'refused', 'accepted'] as const)('does not show over a %s proof', status => {
    renderList(settlementIn(status, { id: PAYER, name: 'Maria' }), 'payer');

    expect(button()).not.toBeInTheDocument();
  });

  it('does not show without a proof', () => {
    renderList(settlementIn(null), 'payer');

    expect(button()).not.toBeInTheDocument();
  });

  it('does not show over a proof a third party sent, even if it looks sent (confirmedBy filled)', () => {
    renderList(settlementIn('sent', { id: PAYER, name: 'Maria' }), 'payer');

    expect(button()).not.toBeInTheDocument();
  });

  it('shows with the cycle sealed (it does not depend on canConfirm)', () => {
    renderList(settlementIn('sent'), 'payer', { canConfirm: false, cycleSettled: true });

    expect(button()).toBeInTheDocument();
  });

  it('does not show when no handler was given (the Painel)', () => {
    renderList(settlementIn('sent'), 'payer', { onComplement: undefined });

    expect(button()).not.toBeInTheDocument();
  });
});

describe('PaymentSettlementsPanel passes onComplement to the list', () => {
  it('shows the button on the paid tab and forwards the click', async () => {
    const settlement = settlementIn('sent');
    const onComplement = vi.fn();
    render(
      <PaymentSettlementsPanel
        settlements={[settlement]}
        balances={BALANCES}
        currentUserId={PAYER}
        canConfirm
        cycleSettled={false}
        tab="paid"
        onTabChange={vi.fn()}
        onPayWithPix={vi.fn()}
        onSendProof={vi.fn()}
        payForUserIds={[DEBTOR]}
        onComplement={onComplement}
      />
    );

    await userEvent.click(screen.getByRole('button', { name: BUTTON }));

    expect(onComplement).toHaveBeenCalledWith(settlement);
  });
});
