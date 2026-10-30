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

  describe('o que define um acerto confirmado', () => {
    const CONFIRMED_AT = '2026-08-20T10:00:00-03:00';

    it('treats confirmedAt as the confirmation even when the proof URL is missing, with no broken link', () => {
      renderList({ settlements: [{ ...settlements[0], confirmedAt: CONFIRMED_AT, confirmedProofUrl: null }] });

      expect(screen.getByText('Comprovante enviado')).toBeInTheDocument();
      // Nem `<a>` sem `href` (que também não tem papel de link): o chip é só um rótulo.
      expect(screen.getByText('Comprovante enviado').closest('a')).toBeNull();
      expect(screen.getByRole('button', { name: 'Reenviar comprovante' })).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'Enviar comprovante' })).not.toBeInTheDocument();
    });

    it('links the chip to the proof when there is one', () => {
      renderList({
        settlements: [{ ...settlements[0], confirmedAt: CONFIRMED_AT, confirmedProofUrl: 'http://api/proofs/9' }]
      });

      expect(screen.getByRole('link', { name: 'Comprovante enviado' })).toHaveAttribute('href', 'http://api/proofs/9');
    });

    it('does not treat a proof URL without confirmedAt as confirmed', () => {
      renderList({ settlements: [{ ...settlements[0], confirmedAt: null, confirmedProofUrl: 'http://api/proofs/9' }] });

      expect(screen.queryByText('Comprovante enviado')).not.toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Enviar comprovante' })).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'Reenviar comprovante' })).not.toBeInTheDocument();
    });
  });

  describe('valores que o usuário logado tem a receber', () => {
    // Bruno (2) é o credor do acerto Ana → Bruno.
    it('marks an unpaid settlement owed to the logged user with text, and shows him no debtor buttons', () => {
      renderList({ currentUserId: 2, canConfirm: true });

      expect(screen.getByText('Deve pagar a você')).toBeInTheDocument();
      expect(screen.queryByText('Pagou a você')).not.toBeInTheDocument();
      expect(screen.queryByRole('button', { name: /Pagar com Pix/ })).not.toBeInTheDocument();
      expect(screen.queryByRole('button', { name: /comprovante/ })).not.toBeInTheDocument();
    });

    it('says "Pagou a você" once the settlement is confirmed', () => {
      renderList({
        currentUserId: 2,
        settlements: [{ ...settlements[0], confirmedAt: '2026-08-20T10:00:00-03:00', confirmedProofUrl: null }]
      });

      expect(screen.getByText('Pagou a você')).toBeInTheDocument();
      expect(screen.queryByText('Deve pagar a você')).not.toBeInTheDocument();
    });

    it('shows no such label to the debtor or to a third member', () => {
      const { rerender } = renderList({ currentUserId: 1 });
      expect(screen.queryByText(/a você/)).not.toBeInTheDocument();

      rerender(
        <PayableSettlementList
          settlements={settlements}
          balances={balances}
          currentUserId={3}
          canConfirm
          cycleSettled={false}
          onPayWithPix={noop}
          onSendProof={noop}
        />
      );
      expect(screen.queryByText(/a você/)).not.toBeInTheDocument();
    });
  });

  describe('lista vazia e ordem', () => {
    it('keeps the default empty message and lets the caller replace it', () => {
      const { rerender } = renderList({ settlements: [] });
      expect(screen.getByText('Ninguém deve nada a ninguém neste ciclo.')).toBeInTheDocument();

      rerender(
        <PayableSettlementList
          settlements={[]}
          balances={balances}
          currentUserId={1}
          canConfirm
          cycleSettled={false}
          onPayWithPix={noop}
          onSendProof={noop}
          emptyMessage="Nenhum valor a pagar neste ciclo."
        />
      );
      expect(screen.getByText('Nenhum valor a pagar neste ciclo.')).toBeInTheDocument();
      expect(screen.queryByText('Ninguém deve nada a ninguém neste ciclo.')).not.toBeInTheDocument();
    });

    it('renders the settlements in the order received, without reordering them', () => {
      renderList({
        balances: [...balances, { user_id: 3, name: 'Caio', balance: -10 }],
        settlements: [
          { from_user_id: 3, to_user_id: 2, amount: 10, confirmedProofUrl: null, confirmedAt: null },
          { from_user_id: 1, to_user_id: 2, amount: 90, confirmedProofUrl: null, confirmedAt: null }
        ]
      });

      expect(screen.getAllByText(/deve pagar/).map(el => el.textContent)).toEqual([
        'Caio deve pagar R$ 10,00 a Bruno',
        'Ana deve pagar R$ 90,00 a Bruno'
      ]);
    });
  });

  it('shows no debtor UI at all for a non-debtor', () => {
    renderList({ currentUserId: 2, canConfirm: false });
    expect(screen.queryByRole('button', { name: /Pagar com Pix/ })).not.toBeInTheDocument();
    expect(screen.queryByText(/após o fechamento do ciclo/)).not.toBeInTheDocument();
    expect(screen.queryByText('Ciclo encerrado.')).not.toBeInTheDocument();
  });
});
