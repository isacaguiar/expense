import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { RefusedProof, SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';
import PayableSettlementList from './PayableSettlementList';

/**
 * TASK-632 (feature 20261114-historico-de-comprovantes-e-justificativa, plan §12, R6 e R7): o acerto recusado
 * mostra o motivo da recusa e o link do comprovante recusado, **só** quando o servidor mandou `refusedProof`
 * (as partes do acerto). O texto do motivo é exibido como texto puro.
 */
const DEBTOR = 10;
const CREDITOR = 20;
const BALANCES: SummaryBalance[] = [
  { user_id: DEBTOR, name: 'Joana', balance: 0 },
  { user_id: CREDITOR, name: 'Carlos', balance: 0 }
];

const refusedSettlement = (refusedProof?: RefusedProof | null): SummarySettlement => ({
  from_user_id: DEBTOR,
  to_user_id: CREDITOR,
  amount: 100,
  confirmedAt: null,
  confirmedProofUrl: null,
  confirmedBy: { id: 30, name: 'Maria' },
  confirmationStatus: 'refused',
  refusedAt: '2026-08-20T10:00:00-03:00',
  ...(refusedProof === undefined ? {} : { refusedProof })
});

const show = (settlement: SummarySettlement, viewer = DEBTOR) =>
  render(
    <PayableSettlementList
      settlements={[settlement]}
      balances={BALANCES}
      currentUserId={viewer}
      canConfirm
      cycleSettled={false}
      onPayWithPix={vi.fn()}
      onSendProof={vi.fn()}
    />
  );

describe('PayableSettlementList: motivo e comprovante recusado', () => {
  it('shows "Motivo: …" under the "Recusado por" chip and the link to the refused proof', () => {
    show(refusedSettlement({ proofUrl: 'https://x/recusado.jpg', note: 'foto', reason: 'a foto está cortada' }));

    expect(screen.getByText('Recusado por Carlos')).toBeInTheDocument();
    expect(screen.getByText('Motivo: a foto está cortada')).toBeInTheDocument();
    const link = screen.getByRole('link', { name: 'Ver comprovante recusado' });
    expect(link).toHaveAttribute('href', 'https://x/recusado.jpg');
    expect(link).toHaveAttribute('target', '_blank');
    expect(link).toHaveAttribute('rel', 'noreferrer');
  });

  it('the creditor sees them too ("Você recusou o comprovante")', () => {
    show(refusedSettlement({ proofUrl: 'https://x/r.jpg', note: null, reason: 'não bate com o valor' }), CREDITOR);

    expect(screen.getByText('Você recusou o comprovante')).toBeInTheDocument();
    expect(screen.getByText('Motivo: não bate com o valor')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Ver comprovante recusado' })).toBeInTheDocument();
  });

  it('without a reason there is no "Motivo" line, but the link stays', () => {
    show(refusedSettlement({ proofUrl: 'https://x/r.jpg', note: null, reason: null }));

    expect(screen.queryByText(/Motivo:/)).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Ver comprovante recusado' })).toBeInTheDocument();
  });

  it('without a file (proofUrl null) there is no link, but the reason stays', () => {
    show(refusedSettlement({ proofUrl: null, note: null, reason: 'ilegível' }));

    expect(screen.getByText('Motivo: ilegível')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Ver comprovante recusado' })).not.toBeInTheDocument();
  });

  it.each([undefined, null])('when the server sent no refusedProof (%s: not a party) neither appears', refusedProof => {
    show(refusedSettlement(refusedProof));

    expect(screen.getByText('Recusado por Carlos')).toBeInTheDocument();
    expect(screen.queryByText(/Motivo:/)).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Ver comprovante recusado' })).not.toBeInTheDocument();
  });

  it('never shows them outside the refused state, even if the data came along', () => {
    show({ ...refusedSettlement({ proofUrl: 'https://x/r.jpg', note: null, reason: 'x' }), confirmationStatus: 'awaiting_acceptance', confirmedAt: '2026-08-20T10:00:00-03:00' });

    expect(screen.queryByText(/Motivo:/)).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Ver comprovante recusado' })).not.toBeInTheDocument();
  });

  it('renders the reason as plain text: no HTML, no link', () => {
    const reason = '<b>negrito</b> veja https://exemplo.com/x';
    const { container } = show(refusedSettlement({ proofUrl: null, note: null, reason }));

    expect(screen.getByText(`Motivo: ${reason}`)).toBeInTheDocument();
    expect(container.querySelector('b')).toBeNull();
    expect(container.querySelector('a[href="https://exemplo.com/x"]')).toBeNull();
  });

  it('lets a long reason wrap instead of stretching the card', () => {
    const reason = 'x'.repeat(500);
    show(refusedSettlement({ proofUrl: null, note: null, reason }));

    expect(screen.getByText(`Motivo: ${reason}`)).toHaveStyle({ overflowWrap: 'anywhere' });
  });
});
