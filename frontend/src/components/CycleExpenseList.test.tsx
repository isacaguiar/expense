import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import CycleExpenseList from './CycleExpenseList';
import type { SummaryExpense } from '../hooks/useGroupCycle';

const expense = (overrides: Partial<SummaryExpense> = {}): SummaryExpense => ({
  id: 1,
  description: 'Aluguel',
  date: '2026-09-05',
  value: 1000,
  valuePerPerson: 500,
  paid: false,
  paymentProofUrl: null,
  payerName: 'Ana',
  participants: ['Ana', 'Beto'],
  isFixed: false,
  userPayerId: 1,
  userCreatorId: 1,
  ...overrides,
});

describe('CycleExpenseList', () => {
  it('says there are no expenses instead of showing an empty list', () => {
    render(<CycleExpenseList expenses={[]} />);

    expect(screen.getByRole('heading', { name: 'Despesas do ciclo' })).toBeInTheDocument();
    expect(screen.getByText('Nenhuma despesa neste ciclo.')).toBeInTheDocument();
    expect(screen.queryByRole('list')).not.toBeInTheDocument();
  });

  it('lists each expense with description, value, date, payer and number of people', () => {
    render(<CycleExpenseList expenses={[expense()]} />);

    const item = screen.getByRole('listitem');
    expect(within(item).getByText('Aluguel — R$ 1.000,00')).toBeInTheDocument();
    expect(within(item).getByText('05 de set. · Pago por Ana · Dividido entre 2 pessoas')).toBeInTheDocument();
    expect(within(item).getByText('Pendente')).toBeInTheDocument();
  });

  it('keeps the order it received', () => {
    render(
      <CycleExpenseList
        expenses={[expense({ id: 1, description: 'Luz' }), expense({ id: 2, description: 'Água' })]}
      />
    );

    const items = screen.getAllByRole('listitem');
    expect(items).toHaveLength(2);
    expect(within(items[0]).getByText(/^Luz/)).toBeInTheDocument();
    expect(within(items[1]).getByText(/^Água/)).toBeInTheDocument();
  });

  it('uses the singular for one person and a dash when the payer is unknown', () => {
    render(<CycleExpenseList expenses={[expense({ payerName: null, participants: ['Ana'] })]} />);

    expect(screen.getByText('05 de set. · Pago por - · Dividido entre 1 pessoa')).toBeInTheDocument();
  });

  it('marks paid and pending expenses with their own chip', () => {
    render(
      <CycleExpenseList
        expenses={[expense({ id: 1, paid: true }), expense({ id: 2, description: 'Luz', paid: false })]}
      />
    );

    const [paid, pending] = screen.getAllByRole('listitem');
    expect(within(paid).getByText('Paga')).toBeInTheDocument();
    expect(within(pending).getByText('Pendente')).toBeInTheDocument();
  });

  it('tells fixed expenses from the others by the icon', () => {
    render(
      <CycleExpenseList
        expenses={[expense({ id: 1, isFixed: true }), expense({ id: 2, description: 'Luz', isFixed: false })]}
      />
    );

    const [fixed, regular] = screen.getAllByRole('listitem');
    expect(within(fixed).getByTestId('AutorenewOutlinedIcon')).toBeInTheDocument();
    expect(within(regular).getByTestId('ReceiptOutlinedIcon')).toBeInTheDocument();
  });

  describe('payment note', () => {
    it('shows the note of a paid expense', () => {
      render(<CycleExpenseList expenses={[expense({ paid: true, paymentNote: 'Pago em dinheiro, recibo 123' })]} />);

      expect(screen.getByText('Descrição do pagamento: Pago em dinheiro, recibo 123')).toBeInTheDocument();
    });

    it('shows each note under its own expense', () => {
      render(
        <CycleExpenseList
          expenses={[
            expense({ id: 1, paid: true, paymentNote: 'nota do aluguel' }),
            expense({ id: 2, description: 'Luz', paid: true, paymentNote: 'nota da luz' }),
          ]}
        />
      );

      const [first, second] = screen.getAllByRole('listitem');
      expect(within(first).getByText('Descrição do pagamento: nota do aluguel')).toBeInTheDocument();
      expect(within(first).queryByText(/nota da luz/)).not.toBeInTheDocument();
      expect(within(second).getByText('Descrição do pagamento: nota da luz')).toBeInTheDocument();
    });

    it('shows nothing extra when the note is null', () => {
      render(<CycleExpenseList expenses={[expense({ paid: true, paymentNote: null })]} />);

      expect(screen.queryByText(/Descrição do pagamento/)).not.toBeInTheDocument();
    });

    it('shows nothing extra for an old snapshot that has no note field at all', () => {
      const old = expense({ paid: true });
      expect('paymentNote' in old).toBe(false);

      render(<CycleExpenseList expenses={[old]} />);

      expect(screen.queryByText(/Descrição do pagamento/)).not.toBeInTheDocument();
    });

    it('shows nothing extra for an empty note', () => {
      render(<CycleExpenseList expenses={[expense({ paid: true, paymentNote: '' })]} />);

      expect(screen.queryByText(/Descrição do pagamento/)).not.toBeInTheDocument();
    });

    it('does not show a note on an expense that is not paid', () => {
      render(<CycleExpenseList expenses={[expense({ paid: false, paymentNote: 'sobra de antes' })]} />);

      expect(screen.queryByText(/Descrição do pagamento/)).not.toBeInTheDocument();
    });

    it('renders the note as plain text, never as HTML', () => {
      const note = '<img src=x onerror="alert(1)"><b>negrito</b>';
      const { container } = render(<CycleExpenseList expenses={[expense({ paid: true, paymentNote: note })]} />);

      expect(screen.getByText(`Descrição do pagamento: ${note}`)).toBeInTheDocument();
      expect(container.querySelector('b')).toBeNull();
      expect(container.querySelector('img[src="x"]')).toBeNull();
    });

    it('puts the note on its own line under the date and payer', () => {
      render(<CycleExpenseList expenses={[expense({ paid: true, paymentNote: 'recibo 123' })]} />);

      expect(screen.getByText('Descrição do pagamento: recibo 123')).toHaveStyle({ display: 'block' });
    });

    it('lets a long word wrap instead of stretching the list', () => {
      const note = 'x'.repeat(300);
      render(<CycleExpenseList expenses={[expense({ paid: true, paymentNote: note })]} />);

      expect(screen.getByText(`Descrição do pagamento: ${note}`)).toHaveStyle({ overflowWrap: 'anywhere' });
    });
  });
});
