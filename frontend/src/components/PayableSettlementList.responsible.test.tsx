import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import PayableSettlementList from './PayableSettlementList';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';

/**
 * Feature 20261109-responsavel-do-devedor (TASK-592, CA-15): em Pagamentos, quem paga por um
 * dependente vê o selo "Você paga por {nome}" e os botões de hoje nos acertos dele, e não nos de
 * terceiros nem nos que ele recebe. Arquivo próprio: `PayableSettlementList.test.tsx` não é editado.
 */

const ME = 1;
const BRUNO = 2; // dependente: o usuário paga por ele
const CARLA = 3; // credora
const DIEGO = 4; // terceiro: o usuário não paga por ele

const balances: SummaryBalance[] = [
  { user_id: ME, name: 'Ana', balance: 0 },
  { user_id: BRUNO, name: 'Bruno', balance: 0 },
  { user_id: CARLA, name: 'Carla', balance: 0 },
  { user_id: DIEGO, name: 'Diego', balance: 0 }
];

const settle = (from: number, to: number, extra: Partial<SummarySettlement> = {}): SummarySettlement => ({
  from_user_id: from,
  to_user_id: to,
  amount: 30,
  confirmedProofUrl: null,
  confirmedAt: null,
  ...extra
});

const CONFIRMED = { confirmedAt: '2026-08-20T10:00:00-03:00', confirmedProofUrl: 'http://api/proofs/9' };

const noop = () => {};

function renderList(props: Partial<React.ComponentProps<typeof PayableSettlementList>> = {}) {
  return render(
    <PayableSettlementList
      settlements={[settle(BRUNO, CARLA)]}
      balances={balances}
      currentUserId={ME}
      canConfirm
      cycleSettled={false}
      onPayWithPix={noop}
      onSendProof={noop}
      payForUserIds={[BRUNO]}
      {...props}
    />
  );
}

const PAY_PIX = { name: 'Pagar com Pix' };
const SEND = { name: 'Enviar comprovante' };

describe('PayableSettlementList: acertos de quem o usuário paga', () => {
  it('shows "Você paga por {nome}" and the debtor buttons on the settlement of someone the user pays for', () => {
    renderList();

    expect(screen.getByText('Você paga por Bruno')).toBeInTheDocument();
    expect(screen.getByRole('button', PAY_PIX)).toBeInTheDocument();
    expect(screen.getByRole('button', SEND)).toBeInTheDocument();
  });

  it('offers "Reenviar comprovante" instead of "Enviar comprovante" once the settlement is confirmed', () => {
    renderList({ settlements: [settle(BRUNO, CARLA, CONFIRMED)] });

    expect(screen.getByText('Você paga por Bruno')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Reenviar comprovante' })).toBeInTheDocument();
    expect(screen.queryByRole('button', SEND)).not.toBeInTheDocument();
  });

  it('keeps the notice instead of the buttons, as for the debtor, while the cycle is not closed or is settled', () => {
    const { rerender } = renderList({ canConfirm: false });

    expect(screen.getByText('Disponível após o fechamento do ciclo.')).toBeInTheDocument();
    expect(screen.queryByRole('button', PAY_PIX)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', SEND)).not.toBeInTheDocument();

    rerender(
      <PayableSettlementList
        settlements={[settle(BRUNO, CARLA)]}
        balances={balances}
        currentUserId={ME}
        canConfirm={false}
        cycleSettled
        onPayWithPix={noop}
        onSendProof={noop}
        payForUserIds={[BRUNO]}
      />
    );
    expect(screen.getByText('Ciclo encerrado.')).toBeInTheDocument();
  });

  it('hands the settlement of the dependent to both actions', async () => {
    const user = userEvent.setup();
    const onPayWithPix = vi.fn();
    const onSendProof = vi.fn();
    const dependent = settle(BRUNO, CARLA);
    renderList({ settlements: [dependent], onPayWithPix, onSendProof });

    await user.click(screen.getByRole('button', PAY_PIX));
    await user.click(screen.getByRole('button', SEND));

    expect(onPayWithPix).toHaveBeenCalledWith(dependent);
    expect(onSendProof).toHaveBeenCalledWith(dependent);
  });

  it('shows no badge and no button on the settlement of a third party', () => {
    renderList({ settlements: [settle(DIEGO, CARLA)] });

    expect(screen.queryByText(/Você paga por/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', PAY_PIX)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', SEND)).not.toBeInTheDocument();
    expect(screen.queryByText('Disponível após o fechamento do ciclo.')).not.toBeInTheDocument();
  });

  it('shows no badge and no button when the dependent owes the user (R14: the user is the receiver)', () => {
    renderList({ settlements: [settle(BRUNO, ME)] });

    expect(screen.getByText('Deve pagar a você')).toBeInTheDocument();
    expect(screen.queryByText(/Você paga por/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', PAY_PIX)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', SEND)).not.toBeInTheDocument();
  });

  it('shows the badge only on the dependent, and the own settlement keeps its buttons without a badge', () => {
    renderList({
      settlements: [settle(ME, CARLA), settle(BRUNO, CARLA), settle(DIEGO, CARLA)],
      payForUserIds: [BRUNO, ME]
    });

    expect(screen.getAllByText(/Você paga por/)).toHaveLength(1);
    expect(screen.getByText('Você paga por Bruno')).toBeInTheDocument();
    // O próprio (Ana) e o dependente (Bruno) têm botões; o terceiro (Diego) não.
    expect(screen.getAllByRole('button', SEND)).toHaveLength(2);
    expect(screen.getAllByRole('button', PAY_PIX)).toHaveLength(2);
  });

  it('behaves as before when payForUserIds is not given', () => {
    render(
      <PayableSettlementList
        settlements={[settle(BRUNO, CARLA), settle(ME, CARLA)]}
        balances={balances}
        currentUserId={ME}
        canConfirm
        cycleSettled={false}
        onPayWithPix={noop}
        onSendProof={noop}
      />
    );

    expect(screen.queryByText(/Você paga por/)).not.toBeInTheDocument();
    expect(screen.getAllByRole('button', SEND)).toHaveLength(1);
  });

  it('shows nothing for the dependent while the current user is unknown', () => {
    renderList({ currentUserId: null });

    expect(screen.queryByText(/Você paga por/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', PAY_PIX)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', SEND)).not.toBeInTheDocument();
  });
});

describe('PayableSettlementList: "Enviado por {nome}"', () => {
  it('shows who sent the proof next to "Comprovante enviado" when someone else paid for the debtor', () => {
    renderList({
      settlements: [settle(BRUNO, CARLA, { ...CONFIRMED, confirmedBy: { id: ME, name: 'Ana' } })]
    });

    expect(screen.getByText('Comprovante enviado')).toBeInTheDocument();
    expect(screen.getByText('Enviado por Ana')).toBeInTheDocument();
  });

  it('is shown to anyone who sees the settlement, not only to who sent it', () => {
    renderList({
      currentUserId: CARLA,
      payForUserIds: [],
      settlements: [settle(BRUNO, CARLA, { ...CONFIRMED, confirmedBy: { id: ME, name: 'Ana' } })]
    });

    expect(screen.getByText('Enviado por Ana')).toBeInTheDocument();
  });

  it.each([
    ['null', null],
    ['absent', undefined]
  ])('is not shown when confirmedBy is %s (the debtor sent it)', (_label, confirmedBy) => {
    renderList({
      settlements: [settle(BRUNO, CARLA, { ...CONFIRMED, ...(confirmedBy === undefined ? {} : { confirmedBy }) })]
    });

    expect(screen.getByText('Comprovante enviado')).toBeInTheDocument();
    expect(screen.queryByText(/Enviado por/)).not.toBeInTheDocument();
  });

  it('is not shown on a settlement that is not confirmed', () => {
    renderList({ settlements: [settle(BRUNO, CARLA, { confirmedBy: { id: ME, name: 'Ana' } })] });

    expect(screen.queryByText(/Enviado por/)).not.toBeInTheDocument();
  });
});
