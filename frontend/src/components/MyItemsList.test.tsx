import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import MyItemsList from './MyItemsList';
import type { OverviewItem } from '../types/overview';

function item(overrides: Partial<OverviewItem> = {}): OverviewItem {
  return {
    expense_id: 51,
    description: 'Construção parede',
    date: '2026-09-10',
    value: 543,
    my_share: 135.75,
    paid: false,
    role: 'debtor',
    creditor: { id: 9, name: 'Nádia' },
    installment: { number: 2, total: 6 },
    can_pay: false,
    can_unpay: false,
    ...overrides,
  };
}

function renderList(items: OverviewItem[], busyExpenseId: number | null = null) {
  const onPay = vi.fn();
  const onUnpay = vi.fn();
  render(<MyItemsList items={items} busyExpenseId={busyExpenseId} onPay={onPay} onUnpay={onUnpay} />);

  return { onPay, onUnpay };
}

describe('MyItemsList', () => {
  it('shows the debtor their share, the total and who the creditor is', () => {
    renderList([item()]);

    const row = screen.getByTestId('my-item-51');
    expect(within(row).getByText('Construção parede')).toBeInTheDocument();
    expect(within(row).getByText('Sua parte R$ 135,75')).toBeInTheDocument();
    expect(within(row).getByText('Total da despesa R$ 543,00')).toBeInTheDocument();
    expect(within(row).getByText('Credor: Nádia')).toBeInTheDocument();
    expect(within(row).getByText(/10 de set/)).toBeInTheDocument();
    expect(within(row).queryByText(/Você pagou/)).not.toBeInTheDocument();
  });

  it('shows the creditor what they paid instead of a share', () => {
    renderList([item({ role: 'creditor', creditor: { id: 7, name: 'Isac' } })]);

    const row = screen.getByTestId('my-item-51');
    expect(within(row).getByText('Você pagou R$ 543,00')).toBeInTheDocument();
    expect(within(row).queryByText(/Sua parte/)).not.toBeInTheDocument();
    expect(within(row).queryByText(/Credor:/)).not.toBeInTheDocument();
  });

  it('shows "Parcela n/N" only for installment expenses', () => {
    renderList([item(), item({ expense_id: 52, description: 'Mercado', installment: null })]);

    expect(within(screen.getByTestId('my-item-51')).getByText('Parcela 2/6')).toBeInTheDocument();
    expect(within(screen.getByTestId('my-item-52')).queryByText(/Parcela/)).not.toBeInTheDocument();
  });

  it('labels each item as Paga or Pendente', () => {
    renderList([item(), item({ expense_id: 52, description: 'Mercado', paid: true })]);

    expect(within(screen.getByTestId('my-item-51')).getByText('Pendente')).toBeInTheDocument();
    expect(within(screen.getByTestId('my-item-52')).getByText('Paga')).toBeInTheDocument();
  });

  it('offers "Marcar como paga" only when the server says can_pay, and reports the expense', async () => {
    const user = userEvent.setup();
    const { onPay, onUnpay } = renderList([item({ role: 'creditor', can_pay: true })]);

    await user.click(screen.getByRole('button', { name: 'Marcar como paga' }));

    expect(onPay).toHaveBeenCalledWith(51);
    expect(onUnpay).not.toHaveBeenCalled();
    expect(screen.queryByRole('button', { name: 'Desfazer' })).not.toBeInTheDocument();
  });

  it('offers "Desfazer" only when the server says can_unpay, and reports the expense', async () => {
    const user = userEvent.setup();
    const { onPay, onUnpay } = renderList([item({ role: 'creditor', paid: true, can_unpay: true })]);

    await user.click(screen.getByRole('button', { name: 'Desfazer' }));

    expect(onUnpay).toHaveBeenCalledWith(51);
    expect(onPay).not.toHaveBeenCalled();
    expect(screen.queryByRole('button', { name: 'Marcar como paga' })).not.toBeInTheDocument();
  });

  it('does not recompute permissions: an unpaid item of the creditor without can_pay has no button', () => {
    // Regressão da regra no servidor: o cliente não deduz "sou o credor e não
    // está paga, então posso marcar". Quem decide é `can_pay`.
    renderList([item({ role: 'creditor', paid: false, can_pay: false, can_unpay: false })]);

    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('disables the buttons of the item being saved', () => {
    renderList(
      [
        item({ role: 'creditor', can_pay: true }),
        item({ expense_id: 52, description: 'Mercado', role: 'creditor', can_pay: true }),
      ],
      51
    );

    expect(within(screen.getByTestId('my-item-51')).getByRole('button', { name: 'Marcar como paga' })).toBeDisabled();
    expect(within(screen.getByTestId('my-item-52')).getByRole('button', { name: 'Marcar como paga' })).toBeEnabled();
  });

  it('says there is nothing of the user in the cycle when the list is empty', () => {
    renderList([]);

    expect(screen.getByText('Nenhum item seu neste ciclo.')).toBeInTheDocument();
  });
});
