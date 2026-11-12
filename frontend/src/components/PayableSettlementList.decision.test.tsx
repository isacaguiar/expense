import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';
import PayableSettlementList from './PayableSettlementList';

/**
 * Feature 20261110-quem-paga-se-declara-e-credor-recusa (TASK-608, plan §11, CA-11): o que cada papel vê e
 * pode fazer em cada estado do comprovante. Arquivo próprio: `PayableSettlementList.test.tsx` e
 * `.responsible.test.tsx` não são editados.
 */

const DEBTOR = 10;
const CREDITOR = 20;
const SENDER = 30; // quem paga pelo devedor e enviou o comprovante
const OTHER_PAYER = 31; // também paga pelo devedor, mas não enviou
const THIRD = 40;

const BALANCES: SummaryBalance[] = [
  { user_id: DEBTOR, name: 'Joana', balance: 0 },
  { user_id: CREDITOR, name: 'Carlos', balance: 0 },
  { user_id: SENDER, name: 'Maria', balance: 0 },
  { user_id: OTHER_PAYER, name: 'Rita', balance: 0 },
  { user_id: THIRD, name: 'Pedro', balance: 0 }
];

const SENT_AT = '2026-08-20T10:00:00-03:00';
const MARIA = { id: SENDER, name: 'Maria' };

// `legacy`: resposta de antes da feature, com comprovante e sem `confirmationStatus`.
type State = 'awaiting' | 'refused' | 'accepted' | 'sent' | 'none' | 'legacy';

const settlementIn = (state: State): SummarySettlement => {
  const base = { from_user_id: DEBTOR, to_user_id: CREDITOR, amount: 100 };
  switch (state) {
    case 'awaiting':
      return { ...base, confirmedAt: SENT_AT, confirmedProofUrl: 'http://x/p.jpg', confirmedBy: MARIA, confirmationStatus: 'awaiting_acceptance' };
    case 'accepted':
      return { ...base, confirmedAt: SENT_AT, confirmedProofUrl: 'http://x/p.jpg', confirmedBy: MARIA, confirmationStatus: 'accepted' };
    case 'refused':
      return { ...base, confirmedAt: null, confirmedProofUrl: null, confirmedBy: MARIA, confirmationStatus: 'refused', refusedAt: SENT_AT };
    case 'sent':
      return { ...base, confirmedAt: SENT_AT, confirmedProofUrl: 'http://x/p.jpg', confirmedBy: null, confirmationStatus: 'sent' };
    case 'legacy':
      return { ...base, confirmedAt: SENT_AT, confirmedProofUrl: 'http://x/p.jpg', confirmedBy: null };
    default:
      return { ...base, confirmedAt: null, confirmedProofUrl: null, confirmationStatus: null };
  }
};

type Viewer = 'creditor' | 'debtor' | 'sender' | 'otherPayer' | 'third';
const VIEWER_ID: Record<Viewer, number> = { creditor: CREDITOR, debtor: DEBTOR, sender: SENDER, otherPayer: OTHER_PAYER, third: THIRD };

function renderList(state: State, viewer: Viewer, props: Partial<React.ComponentProps<typeof PayableSettlementList>> = {}) {
  const settlement = settlementIn(state);
  const handlers = { onAccept: vi.fn(), onRefuse: vi.fn(), onDelete: vi.fn(), onPayWithPix: vi.fn(), onSendProof: vi.fn() };
  render(
    <PayableSettlementList
      settlements={[settlement]}
      balances={BALANCES}
      currentUserId={VIEWER_ID[viewer]}
      canConfirm
      cycleSettled={false}
      payForUserIds={viewer === 'sender' || viewer === 'otherPayer' ? [DEBTOR] : []}
      {...handlers}
      {...props}
    />
  );
  return { settlement, ...handlers };
}

const button = (name: string) => screen.queryByRole('button', { name });
const DECISION = ['Aceitar', 'Recusar'];
const ALL_ACTIONS = ['Aceitar', 'Recusar', 'Excluir', 'Pagar com Pix', 'Enviar comprovante', 'Reenviar comprovante'];

describe('PayableSettlementList: o comprovante aguardando o aceite', () => {
  it('the creditor sees "Aguardando seu aceite", who sent it, the proof link and Aceitar and Recusar, but not Excluir', () => {
    renderList('awaiting', 'creditor');

    expect(screen.getByText('Aguardando seu aceite')).toBeInTheDocument();
    expect(screen.getByText('Enviado por Maria')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Comprovante enviado' })).toHaveAttribute('href', 'http://x/p.jpg');
    expect(button('Aceitar')).toBeInTheDocument();
    expect(button('Recusar')).toBeInTheDocument();
    expect(button('Excluir')).not.toBeInTheDocument();
    expect(screen.getByText('Deve pagar a você')).toBeInTheDocument();
    expect(screen.queryByText('Pagou a você')).not.toBeInTheDocument();
  });

  it.each(['debtor', 'sender', 'otherPayer', 'third'] as const)('%s sees "Aguardando aceite do credor" and never Aceitar or Recusar', viewer => {
    renderList('awaiting', viewer);

    expect(screen.getByText('Aguardando aceite do credor')).toBeInTheDocument();
    for (const name of DECISION) expect(button(name)).not.toBeInTheDocument();
  });

  it.each([
    ['debtor', true],
    ['sender', true],
    ['otherPayer', false],
    ['third', false]
  ] as const)('%s: Excluir is %s (only the debtor and who sent it can delete)', (viewer, canDelete) => {
    renderList('awaiting', viewer);

    expect(Boolean(button('Excluir'))).toBe(canDelete);
  });

  it('whoever can act on it still resends, as before', () => {
    renderList('awaiting', 'sender');

    expect(button('Reenviar comprovante')).toBeInTheDocument();
    expect(button('Pagar com Pix')).toBeInTheDocument();
  });
});

describe('PayableSettlementList: o comprovante recusado', () => {
  it('the creditor sees "Você recusou o comprovante" and no action', () => {
    renderList('refused', 'creditor');

    expect(screen.getByText('Você recusou o comprovante')).toBeInTheDocument();
    expect(screen.getByText('Deve pagar a você')).toBeInTheDocument();
    for (const name of ALL_ACTIONS) expect(button(name)).not.toBeInTheDocument();
  });

  it.each(['debtor', 'sender'] as const)('%s sees "Recusado por Carlos", Enviar comprovante and Excluir', viewer => {
    renderList('refused', viewer);

    expect(screen.getByText('Recusado por Carlos')).toBeInTheDocument();
    expect(button('Enviar comprovante')).toBeInTheDocument();
    expect(button('Excluir')).toBeInTheDocument();
    expect(button('Reenviar comprovante')).not.toBeInTheDocument();
    for (const name of DECISION) expect(button(name)).not.toBeInTheDocument();
  });

  it('a third member sees who refused and nothing to do', () => {
    renderList('refused', 'third');

    expect(screen.getByText('Recusado por Carlos')).toBeInTheDocument();
    for (const name of ALL_ACTIONS) expect(button(name)).not.toBeInTheDocument();
  });

  it('the other payer, who did not send it, can resend but cannot delete', () => {
    renderList('refused', 'otherPayer');

    expect(button('Enviar comprovante')).toBeInTheDocument();
    expect(button('Excluir')).not.toBeInTheDocument();
  });
});

describe('PayableSettlementList: o comprovante aceito', () => {
  it.each(['creditor', 'debtor', 'sender', 'otherPayer', 'third'] as const)('%s sees "Aceito pelo credor" and no action at all', viewer => {
    renderList('accepted', viewer);

    expect(screen.getByText('Aceito pelo credor')).toBeInTheDocument();
    for (const name of ALL_ACTIONS) expect(button(name)).not.toBeInTheDocument();
  });
});

describe('PayableSettlementList: o que não muda', () => {
  it('the debtor own proof (sent) looks as before: resend, no delete, no status chip', () => {
    renderList('sent', 'debtor');
    expect(button('Reenviar comprovante')).toBeInTheDocument();
    expect(button('Excluir')).not.toBeInTheDocument();
    expect(screen.queryByText(/Aguardando|Aceito pelo|Recusado por/)).not.toBeInTheDocument();
  });

  it('a settlement without proof offers to send one and nothing about a decision', () => {
    renderList('none', 'debtor');

    expect(button('Enviar comprovante')).toBeInTheDocument();
    expect(button('Excluir')).not.toBeInTheDocument();
    expect(screen.queryByText(/Aguardando|Aceito pelo|Recusado por/)).not.toBeInTheDocument();
  });

  it('a response without confirmationStatus is read by confirmedAt: a proof counts as sent', () => {
    const { settlement } = renderList('legacy', 'creditor');

    expect(settlement.confirmationStatus).toBeUndefined();
    expect(screen.getByText('Pagou a você')).toBeInTheDocument();
    expect(screen.queryByText(/Aguardando|Aceito pelo|Recusado por/)).not.toBeInTheDocument();
    for (const name of [...DECISION, 'Excluir']) expect(button(name)).not.toBeInTheDocument();
  });
});

describe('PayableSettlementList: ações e janela', () => {
  it('Aceitar and Recusar call the handlers with the settlement', async () => {
    const user = userEvent.setup();
    const { settlement, onAccept, onRefuse } = renderList('awaiting', 'creditor');

    await user.click(button('Aceitar')!);
    await user.click(button('Recusar')!);

    expect(onAccept).toHaveBeenCalledWith(settlement);
    expect(onRefuse).toHaveBeenCalledWith(settlement);
  });

  it('Excluir asks for confirmation first: cancelling sends nothing, confirming calls onDelete once', async () => {
    const user = userEvent.setup();
    const { settlement, onDelete } = renderList('awaiting', 'debtor');

    await user.click(button('Excluir')!);
    const dialog = await screen.findByRole('dialog', { name: 'Excluir comprovante?' });
    expect(within(dialog).getByText(/enviado por Maria será excluído de vez e não poderá ser recuperado/)).toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: 'Cancelar' }));
    expect(onDelete).not.toHaveBeenCalled();
    // O diálogo sai com uma transição: enquanto ela dura, o "Excluir" visível é o dele, não o do card.
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());

    await user.click(button('Excluir')!);
    await user.click(within(await screen.findByRole('dialog', { name: 'Excluir comprovante?' })).getByRole('button', { name: 'Excluir' }));

    expect(onDelete).toHaveBeenCalledTimes(1);
    expect(onDelete).toHaveBeenCalledWith(settlement);
  });

  it('shows the state but no decision button once the cycle is sealed or not yet closed', () => {
    renderList('awaiting', 'creditor', { canConfirm: false });

    expect(screen.getByText('Aguardando seu aceite')).toBeInTheDocument();
    for (const name of ALL_ACTIONS) expect(button(name)).not.toBeInTheDocument();
  });

  it('the debtor cannot delete when the cycle is sealed', () => {
    renderList('awaiting', 'debtor', { canConfirm: false });

    expect(button('Excluir')).not.toBeInTheDocument();
  });

  it('without the handlers (Painel) the creditor sees the state and no decision button', () => {
    renderList('awaiting', 'creditor', { onAccept: undefined, onRefuse: undefined, onDelete: undefined });

    expect(screen.getByText('Aguardando seu aceite')).toBeInTheDocument();
    for (const name of [...DECISION, 'Excluir']) expect(button(name)).not.toBeInTheDocument();
  });

  it('disables the decision buttons while a request is in flight', () => {
    renderList('awaiting', 'creditor', { decisionBusy: true });

    expect(button('Aceitar')).toBeDisabled();
    expect(button('Recusar')).toBeDisabled();
  });

  it('disables Excluir while a request is in flight', () => {
    renderList('refused', 'debtor', { decisionBusy: true });

    expect(button('Excluir')).toBeDisabled();
  });
});
