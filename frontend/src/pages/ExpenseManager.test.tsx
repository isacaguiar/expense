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

type SummaryExpenseFixture = {
  id: number;
  description: string;
  date: string;
  value: number;
  paid?: boolean;
  payerName?: string | null;
  participants?: string[];
  isFixed?: boolean;
  userPayerId?: number;
  userCreatorId?: number;
};

const CURRENT_USER_ID = 500;

function summaryResponse(expensesList: SummaryExpenseFixture[] = [], cycleOverride: Record<string, unknown> = {}) {
  return {
    cycle: { start: '2026-08-01', end: '2026-08-31', status: 'open', ...cycleOverride },
    totals: { total: 0, paid: 0, pending: 0 },
    expenses: expensesList.map(exp => ({
      paid: false,
      participants: [],
      userPayerId: CURRENT_USER_ID,
      userCreatorId: CURRENT_USER_ID,
      ...exp,
    })),
    balances: [],
  };
}

function mockGetResponses(expensesList: SummaryExpenseFixture[] = [], cycleOverride: Record<string, unknown> = {}) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/expenses/summary')) {
      return Promise.resolve({ data: summaryResponse(expensesList, cycleOverride) });
    }
    if (url.includes('/api/me')) {
      return Promise.resolve({ data: { id: CURRENT_USER_ID } });
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
  const expenses: SummaryExpenseFixture[] = [
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
    expect(vi.mocked(axios.get).mock.calls.filter(call => (call[0] as string).includes('/expenses/summary'))).toHaveLength(1);
  });

  it('displays the expense date without an off-by-one-day shift in negative timezones', async () => {
    mockGetResponses([
      { id: 11, description: 'Assinatura', value: 50, date: '2026-07-16', payerName: 'Isac', isFixed: false },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    expect(await screen.findByText('Assinatura')).toBeInTheDocument();
    expect(screen.getByText(/16\/07\/2026/)).toBeInTheDocument();
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

describe('ExpenseManager - campos completos e ações condicionais', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
  });

  it('shows status chip, credor and pagadores', async () => {
    mockGetResponses([
      {
        id: 20,
        description: 'Mercado',
        value: 300,
        date: '2026-08-10',
        payerName: 'Isac',
        participants: ['Isac', 'Maria'],
        paid: true,
        isFixed: false,
      },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    expect(await screen.findByText('Paga')).toBeInTheDocument();
    expect(screen.getByText(/Credor: Isac/)).toBeInTheDocument();
    expect(screen.getByText('Pagadores: Isac, Maria')).toBeInTheDocument();
  });

  it('shows edit, delete and pay icons when the user owns and is the creditor of a pending variable expense', async () => {
    mockGetResponses([
      {
        id: 21,
        description: 'Mercado',
        value: 300,
        date: '2026-08-10',
        payerName: 'Isac',
        paid: false,
        isFixed: false,
        userPayerId: CURRENT_USER_ID,
        userCreatorId: CURRENT_USER_ID,
      },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Mercado');

    expect(screen.getByRole('link', { name: 'Editar despesa' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Excluir despesa' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Marcar como paga' })).toBeInTheDocument();
  });

  it('hides delete and pay icons when the expense is already paid', async () => {
    mockGetResponses([
      {
        id: 22,
        description: 'Mercado',
        value: 300,
        date: '2026-08-10',
        payerName: 'Isac',
        paid: true,
        isFixed: false,
        userPayerId: CURRENT_USER_ID,
        userCreatorId: CURRENT_USER_ID,
      },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Mercado');

    expect(screen.getByRole('link', { name: 'Editar despesa' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Excluir despesa' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Marcar como paga' })).not.toBeInTheDocument();
  });

  it('hides delete icon for FIXED expenses (uses "Remover despesa fixa" instead)', async () => {
    mockGetResponses([
      {
        id: 23,
        description: 'Aluguel',
        value: 1200,
        date: '2026-08-01',
        payerName: 'Isac',
        paid: false,
        isFixed: true,
        userPayerId: CURRENT_USER_ID,
        userCreatorId: CURRENT_USER_ID,
      },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');

    expect(screen.getByRole('button', { name: 'Remover despesa fixa' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Excluir despesa' })).not.toBeInTheDocument();
  });

  it('hides the pay icon when the current user is not the creditor', async () => {
    mockGetResponses([
      {
        id: 24,
        description: 'Mercado',
        value: 300,
        date: '2026-08-10',
        payerName: 'Outra Pessoa',
        paid: false,
        isFixed: false,
        userPayerId: CURRENT_USER_ID + 1,
        userCreatorId: CURRENT_USER_ID,
      },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Mercado');

    expect(screen.getByRole('link', { name: 'Editar despesa' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Marcar como paga' })).not.toBeInTheDocument();
  });

  it('hides all action icons when the cycle is not open', async () => {
    mockGetResponses(
      [
        {
          id: 25,
          description: 'Mercado',
          value: 300,
          date: '2026-07-10',
          payerName: 'Isac',
          paid: false,
          isFixed: false,
          userPayerId: CURRENT_USER_ID,
          userCreatorId: CURRENT_USER_ID,
        },
      ],
      { status: 'closed' }
    );

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Mercado');

    expect(screen.queryByRole('link', { name: 'Editar despesa' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Excluir despesa' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Marcar como paga' })).not.toBeInTheDocument();
  });
});

describe('ExpenseManager - navegação por competência', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    mockGetResponses([], { start: '2026-08-01', end: '2026-08-31', status: 'open' });
  });

  it('shows the current cycle range in the header', async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    expect(await screen.findByText(/01 de ago.*31 de ago/)).toBeInTheDocument();
  });

  it('requests the previous cycle when the back arrow is clicked', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText(/01 de ago/);
    await user.click(screen.getByLabelText('Competência anterior'));

    await waitFor(() => {
      const lastCall = vi.mocked(axios.get).mock.calls.at(-1);
      expect(lastCall?.[0]).toContain('/expenses/summary');
      expect((lastCall?.[1] as { params: { cycles_ago: number } }).params.cycles_ago).toBe(1);
    });
  });

  it('requests the next cycle when the forward arrow is clicked, even past the current cycle', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText(/01 de ago/);
    await user.click(screen.getByLabelText('Próxima competência'));

    await waitFor(() => {
      const lastCall = vi.mocked(axios.get).mock.calls.at(-1);
      expect(lastCall?.[0]).toContain('/expenses/summary');
      expect((lastCall?.[1] as { params: { cycles_ago: number } }).params.cycles_ago).toBe(-1);
    });
  });
});

describe('ExpenseManager - remover despesa Fixa', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    mockGetResponses(
      [{ id: 9, description: 'Aluguel', value: 1200, date: '2026-08-01', payerName: 'Isac', isFixed: true }],
      { start: '2026-08-01', end: '2026-08-31', status: 'open' }
    );
  });

  it('shows the expense description in the confirmation dialog and a success toast after removing', async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    const removeButton = await screen.findByRole('button', { name: 'Remover despesa fixa' });
    await userEvent.click(removeButton);

    expect(await screen.findByText('"Aluguel" deve deixar de aparecer?', { exact: false })).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'A partir deste mês' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    expect(await screen.findByText('Despesa fixa removida com sucesso.')).toBeInTheDocument();
  });

  it('sends the currently viewed cycle\'s month when removing "a partir deste mês"', async () => {
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
    expect(payload).toEqual({ year: 2026, month: 8 });
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
    expect(payload).toEqual({ year: 2026, month: 9 });
  });
});
