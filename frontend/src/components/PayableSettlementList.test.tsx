import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PayableSettlementList from './PayableSettlementList';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';

const balances: SummaryBalance[] = [
  { user_id: 1, name: 'Ana', balance: -50 },
  { user_id: 2, name: 'Bruno', balance: 50 },
];

const settlements: SummarySettlement[] = [
  { from_user_id: 1, to_user_id: 2, amount: 50, confirmedProofUrl: null, confirmedAt: null },
];

const noop = () => {};

function renderList(props: Partial<React.ComponentProps<typeof PayableSettlementList>> = {}) {
  return render(
    <PayableSettlementList
      settlements={settlements}
      balances={balances}
      currentUserId={1}
      canConfirm
      cycleSettled={false}
      onPayWithPix={noop}
      onSendProof={noop}
      {...props}
    />
  );
}

describe('PayableSettlementList', () => {
  it('shows the debtor action buttons when canConfirm is true', () => {
    renderList({ canConfirm: true });
    expect(screen.getByRole('button', { name: /Pagar com Pix/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Enviar comprovante/ })).toBeInTheDocument();
    expect(screen.queryByText(/após o fechamento do ciclo/)).not.toBeInTheDocument();
  });

  it('hides the buttons and shows a notice when canConfirm is false', () => {
    renderList({ canConfirm: false });
    expect(screen.queryByRole('button', { name: /Pagar com Pix/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Enviar comprovante/ })).not.toBeInTheDocument();
    expect(screen.getByText('Disponível após o fechamento do ciclo.')).toBeInTheDocument();
  });

  it('says the cycle is closed when it is already settled', () => {
    renderList({ canConfirm: false, cycleSettled: true });
    expect(screen.getByText('Ciclo encerrado.')).toBeInTheDocument();
  });

  it('shows the confirmed note under the "Comprovante enviado" chip', () => {
    renderList({
      settlements: [
        { ...settlements[0], confirmedProofUrl: 'http://api/proofs/9', confirmedNote: 'Pix agendado para sexta', confirmedAt: '2026-08-20T10:00:00-03:00' },
      ],
    });
    expect(screen.getByText('Comprovante enviado')).toBeInTheDocument();
    expect(screen.getByText('Pix agendado para sexta')).toBeInTheDocument();
    expect(screen.getByTestId('settlement-note')).toHaveTextContent('Pix agendado para sexta');
  });

  it('shows no note line when the confirmation has none or nobody confirmed yet', () => {
    const { rerender } = renderList({
      settlements: [
        { ...settlements[0], confirmedProofUrl: 'http://api/proofs/9', confirmedNote: null, confirmedAt: '2026-08-20T10:00:00-03:00' },
      ],
    });
    expect(screen.queryByTestId('settlement-note')).not.toBeInTheDocument();

    rerender(
      <PayableSettlementList
        settlements={[{ ...settlements[0], confirmedNote: 'não deve aparecer sem comprovante' }]}
        balances={balances}
        currentUserId={1}
        canConfirm
        cycleSettled={false}
        onPayWithPix={noop}
        onSendProof={noop}
      />
    );
    expect(screen.queryByTestId('settlement-note')).not.toBeInTheDocument();
  });

  it('renders markup in the note as plain text', () => {
    renderList({
      settlements: [
        { ...settlements[0], confirmedProofUrl: 'http://api/proofs/9', confirmedNote: '<b>negrito</b>', confirmedAt: '2026-08-20T10:00:00-03:00' },
      ],
    });
    expect(screen.getByText('<b>negrito</b>')).toBeInTheDocument();
    expect(document.querySelector('b')).toBeNull();
  });

  it('shows no debtor UI at all for a non-debtor', () => {
    renderList({ currentUserId: 2, canConfirm: false });
    expect(screen.queryByRole('button', { name: /Pagar com Pix/ })).not.toBeInTheDocument();
    expect(screen.queryByText(/após o fechamento do ciclo/)).not.toBeInTheDocument();
    expect(screen.queryByText('Ciclo encerrado.')).not.toBeInTheDocument();
  });
});
