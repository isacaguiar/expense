import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ExpenseManager from './ExpenseManager';

vi.mock('axios');

type StopRecurrencePayload = { year: number; month: number };

function lastPostCall<T>(): [string, T] {
  const calls = vi.mocked(axios.post).mock.calls;
  return calls[calls.length - 1] as unknown as [string, T];
}

const navigateMock = vi.fn();

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => navigateMock,
    useParams: () => ({ id: '1' }),
  };
});

function mockGetResponses(expensesList: unknown[] = []) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/expenses')) {
      return Promise.resolve({ data: expensesList });
    }
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

describe('ExpenseManager - Nova Despesa', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    mockGetResponses();
  });

  it('navigates to the full-page creation route instead of opening a modal', async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await waitFor(() => expect(axios.get).toHaveBeenCalled());

    await userEvent.click(screen.getByRole('button', { name: /nova despesa/i }));

    expect(navigateMock).toHaveBeenCalledWith('/groups/1/expenses/new');
    expect(screen.queryByText('Cadastrar nova despesa')).not.toBeInTheDocument();
  });
});

describe('ExpenseManager - listagem em cards', () => {
  const expenses = [
    { id: 9, description: 'Aluguel', value: 1200, date: '2026-08-01', payerName: 'Isac', isFixed: true },
    { id: 10, description: 'Mercado', value: 150, date: '2026-08-05', payerName: 'João', isFixed: false },
  ];

  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    mockGetResponses(expenses);
  });

  it('renders a card per expense, each linking to its view route', async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    expect(await screen.findByText('Aluguel')).toBeInTheDocument();
    expect(screen.getByText('Mercado')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Aluguel/ })).toHaveAttribute('href', '/groups/1/expenses/9');
    expect(screen.getByRole('link', { name: /Mercado/ })).toHaveAttribute('href', '/groups/1/expenses/10');
  });

  it('filters by description on the client side', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');
    await user.type(screen.getByLabelText('Buscar despesa'), 'merc');

    expect(screen.queryByText('Aluguel')).not.toBeInTheDocument();
    expect(screen.getByText('Mercado')).toBeInTheDocument();
    expect(axios.get).toHaveBeenCalledTimes(1);
  });

  it('filters by type (Fixas/Variáveis) on the client side', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');
    await user.click(screen.getByRole('button', { name: 'Fixas' }));

    expect(screen.getByText('Aluguel')).toBeInTheDocument();
    expect(screen.queryByText('Mercado')).not.toBeInTheDocument();
  });
});

describe('ExpenseManager - remover despesa Fixa', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    mockGetResponses([
      { id: 9, description: 'Aluguel', value: 1200, date: '2026-08-01', payerName: 'Isac', isFixed: true },
    ]);
  });

  it('sends the currently viewed month when removing "a partir deste mês"', async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    const removeButton = await screen.findByRole('button', { name: 'Remover despesa fixa' });
    await userEvent.click(removeButton);

    await screen.findByText('Remover despesa fixa');
    await userEvent.click(screen.getByRole('button', { name: 'A partir deste mês' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [url, payload] = lastPostCall<StopRecurrencePayload>();
    expect(url).toContain('/api/expenses/9/stop-recurrence');
    const now = new Date();
    expect(payload).toEqual({ year: now.getFullYear(), month: now.getMonth() + 1 });
  });

  it('sends the following month when removing "a partir do mês que vem"', async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    const removeButton = await screen.findByRole('button', { name: 'Remover despesa fixa' });
    await userEvent.click(removeButton);

    await screen.findByText('Remover despesa fixa');
    await userEvent.click(screen.getByRole('button', { name: 'A partir do mês que vem' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [, payload] = lastPostCall<StopRecurrencePayload>();
    const next = new Date();
    next.setMonth(next.getMonth() + 1);
    expect(payload).toEqual({ year: next.getFullYear(), month: next.getMonth() + 1 });
  });
});
