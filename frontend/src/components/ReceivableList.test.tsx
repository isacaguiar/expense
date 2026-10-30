import { render, screen, within } from '@testing-library/react';
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
    expect(proof).toHaveAttribute('target', '_blank');
    expect(proof).toHaveAttribute('rel', 'noreferrer');
    expect(within(row).queryByText('Aguardando pagamento')).not.toBeInTheDocument();
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
