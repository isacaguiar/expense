import { render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ExpenseView from './ExpenseView';

vi.mock('axios');

const navigateMock = vi.fn();

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => navigateMock,
    useParams: () => ({ id: '1', expenseId: '9' }),
  };
});

const expenses = [
  { id: 9, description: 'Aluguel', value: 1200, date: '2026-08-01', payerName: 'Isac', isFixed: true },
  { id: 10, description: 'Mercado', value: 150, date: '2026-08-05', payerName: 'João', isFixed: false },
];

describe('ExpenseView', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
  });

  it('shows the expense details when it is found in the currently loaded month', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({ data: expenses });

    render(
      <MemoryRouter>
        <ExpenseView />
      </MemoryRouter>
    );

    expect(await screen.findByText('Aluguel')).toBeInTheDocument();
    expect(screen.getByText('R$ 1.200,00')).toBeInTheDocument();
    expect(screen.getByText('Fixa')).toBeInTheDocument();
    expect(screen.getByText('Pago por Isac')).toBeInTheDocument();
  });

  it('shows "não encontrada" with a link back when the expense is not in the loaded month', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({ data: [expenses[1]] });

    render(
      <MemoryRouter>
        <ExpenseView />
      </MemoryRouter>
    );

    expect(await screen.findByText('Despesa não encontrada')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Voltar para a listagem' })).toHaveAttribute(
      'href',
      '/groups/1/expenses'
    );
  });

  it('redirects to login on a 401', async () => {
    vi.mocked(axios.get).mockRejectedValueOnce({ response: { status: 401 } });

    render(
      <MemoryRouter>
        <ExpenseView />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(navigateMock).toHaveBeenCalledWith('/', { replace: true });
    });
  });
});
