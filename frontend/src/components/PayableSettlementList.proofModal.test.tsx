import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';
import PayableSettlementList from './PayableSettlementList';

/**
 * TASK-655 (feature 20261120-ver-comprovante-em-modal, plan §3): o chip "Comprovante enviado" e o link
 * "Ver comprovante recusado" abrem o comprovante num diálogo em vez de outra aba. O `href` fica (ctrl+clique
 * e botão do meio continuam abrindo o arquivo), o `target` sai.
 */
const DEBTOR = 10;
const CREDITOR = 20;
const BALANCES: SummaryBalance[] = [
  { user_id: DEBTOR, name: 'Joana', balance: 0 },
  { user_id: CREDITOR, name: 'Carlos', balance: 0 }
];
const SENT = 'https://x/enviado.jpg?signature=s';
const REFUSED = 'https://x/recusado.jpg?signature=r';

const base: SummarySettlement = {
  from_user_id: DEBTOR,
  to_user_id: CREDITOR,
  amount: 100,
  confirmedAt: null,
  confirmedProofUrl: null
};

const show = (settlement: SummarySettlement) =>
  render(
    <PayableSettlementList
      settlements={[settlement]}
      balances={BALANCES}
      currentUserId={DEBTOR}
      canConfirm
      cycleSettled={false}
      onPayWithPix={vi.fn()}
      onSendProof={vi.fn()}
    />
  );

describe('PayableSettlementList: comprovante em modal', () => {
  it('opens the sent proof in a dialog from the "Comprovante enviado" chip, with no new tab', async () => {
    const user = userEvent.setup();
    show({ ...base, confirmedAt: '2026-08-20T10:00:00-03:00', confirmedProofUrl: SENT });
    const chip = screen.getByRole('link', { name: 'Comprovante enviado' });
    expect(chip).toHaveAttribute('href', SENT);
    expect(chip).not.toHaveAttribute('target');

    await user.click(chip);

    expect(screen.getByRole('dialog', { name: 'Comprovante' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Comprovante de pagamento' })).toHaveAttribute('src', SENT);
  });

  it('opens the refused proof in a dialog from "Ver comprovante recusado"', async () => {
    const user = userEvent.setup();
    show({
      ...base,
      confirmationStatus: 'refused',
      refusedAt: '2026-08-20T10:00:00-03:00',
      refusedProof: { proofUrl: REFUSED, note: null, reason: null }
    } as SummarySettlement);
    const link = screen.getByRole('link', { name: 'Ver comprovante recusado' });
    expect(link).not.toHaveAttribute('target');

    await user.click(link);

    expect(screen.getByRole('img', { name: 'Comprovante de pagamento' })).toHaveAttribute('src', REFUSED);
  });

  it('does not open the dialog on ctrl+click (the browser keeps the new-tab behaviour)', async () => {
    const user = userEvent.setup();
    show({ ...base, confirmedAt: '2026-08-20T10:00:00-03:00', confirmedProofUrl: SENT });

    await user.keyboard('{Control>}');
    await user.click(screen.getByRole('link', { name: 'Comprovante enviado' }));
    await user.keyboard('{/Control}');

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('gives the focus back to the chip when the dialog closes', async () => {
    const user = userEvent.setup();
    show({ ...base, confirmedAt: '2026-08-20T10:00:00-03:00', confirmedProofUrl: SENT });
    const chip = screen.getByRole('link', { name: 'Comprovante enviado' });

    await user.click(chip);
    await user.click(screen.getByRole('button', { name: 'Fechar' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(chip).toHaveFocus();
  });
});
