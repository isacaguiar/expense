import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import ReceivableList from './ReceivableList';
import type { OverviewReceivable } from '../types/overview';

const confirmed: OverviewReceivable = {
  from_user: { id: 4, name: 'Norma', avatar_url: null },
  amount: 120,
  confirmed_at: '2026-10-08T14:02:11-03:00',
  confirmed_proof_url: 'https://example.test/comprovantes/1.jpg',
};

const waiting: OverviewReceivable = {
  from_user: { id: 5, name: 'Otávio', avatar_url: null },
  amount: 1234.5,
  confirmed_at: null,
  confirmed_proof_url: null,
};

describe('ReceivableList', () => {
  it('shows who owes, how much in pt-BR, and the proof link once it was sent', () => {
    render(<ReceivableList receivables={[confirmed]} />);

    const row = screen.getByTestId('receivable-4');
    expect(within(row).getByText('Norma')).toBeInTheDocument();
    expect(within(row).getByText('R$ 120,00')).toBeInTheDocument();

    const proof = within(row).getByRole('link', { name: /Comprovante enviado/ });
    expect(proof).toHaveAttribute('href', 'https://example.test/comprovantes/1.jpg');
    // Abre num diálogo (TASK-656): sem `target`; o `href` segue para ctrl+clique e botão do meio.
    expect(proof).not.toHaveAttribute('target');
    expect(within(row).queryByText('Aguardando pagamento')).not.toBeInTheDocument();
  });

  it('opens the proof in a dialog on click, with no ctrl+click opening and the focus coming back (TASK-656)', async () => {
    const user = userEvent.setup();
    render(<ReceivableList receivables={[confirmed]} />);
    const proof = screen.getByRole('link', { name: /Comprovante enviado/ });

    await user.keyboard('{Control>}');
    await user.click(proof);
    await user.keyboard('{/Control}');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

    await user.click(proof);
    expect(screen.getByRole('dialog', { name: 'Comprovante' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Comprovante de pagamento' })).toHaveAttribute(
      'src',
      'https://example.test/comprovantes/1.jpg'
    );

    await user.click(screen.getByRole('button', { name: 'Fechar' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(proof).toHaveFocus();
  });

  it('shows "Aguardando pagamento" while there is no proof', () => {
    render(<ReceivableList receivables={[waiting]} />);

    const row = screen.getByTestId('receivable-5');
    expect(within(row).getByText('Otávio')).toBeInTheDocument();
    expect(within(row).getByText('R$ 1.234,50')).toBeInTheDocument();
    expect(within(row).getByText('Aguardando pagamento')).toBeInTheDocument();
    expect(within(row).queryByText('Comprovante enviado')).not.toBeInTheDocument();
    expect(within(row).queryByRole('link')).not.toBeInTheDocument();
  });

  it('lists every debtor in the order the server sent', () => {
    render(<ReceivableList receivables={[waiting, confirmed]} />);

    const rows = screen.getAllByTestId(/^receivable-/);
    expect(rows.map(row => row.getAttribute('data-testid'))).toEqual(['receivable-5', 'receivable-4']);
  });

  it('says nobody owes anything when the list is empty', () => {
    render(<ReceivableList receivables={[]} />);

    expect(screen.getByText('Ninguém te deve neste ciclo.')).toBeInTheDocument();
  });

  it('has no action: the creditor only follows the status here', () => {
    render(<ReceivableList receivables={[confirmed, waiting]} />);

    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });
});
