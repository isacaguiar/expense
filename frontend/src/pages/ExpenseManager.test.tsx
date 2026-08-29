import { render, screen, waitFor, within } from '@testing-library/react';
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
  paymentProofUrl?: string | null;
  payerName?: string | null;
  participants?: string[];
  isFixed?: boolean;
  userPayerId?: number;
  userCreatorId?: number;
};

const CURRENT_USER_ID = 500;

function summaryResponse(
  expensesList: SummaryExpenseFixture[] = [],
  cycleOverride: Record<string, unknown> = {},
  extra: Record<string, unknown> = {}
) {
  return {
    cycle: { start: '2026-08-01', end: '2026-08-31', status: 'open', ...cycleOverride },
    totals: { total: 0, paid: 0, pending: 0 },
    expenses: expensesList.map(exp => ({
      paid: false,
      paymentProofUrl: null,
      participants: [],
      userPayerId: CURRENT_USER_ID,
      userCreatorId: CURRENT_USER_ID,
      ...exp,
    })),
    balances: [],
    settlements: [],
    ...extra,
  };
}

function mockGetResponses(
  expensesList: SummaryExpenseFixture[] = [],
  cycleOverride: Record<string, unknown> = {},
  extra: Record<string, unknown> = {}
) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/expenses/summary')) {
      return Promise.resolve({ data: summaryResponse(expensesList, cycleOverride, extra) });
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

  it('shows a "Ver comprovante" link (row and detail modal) when the expense is paid with a proof', async () => {
    const user = userEvent.setup();

    mockGetResponses([
      {
        id: 9,
        description: 'Aluguel',
        value: 1200,
        date: '2026-08-01',
        payerName: 'Isac',
        isFixed: true,
        paid: true,
        paymentProofUrl: '/api/groups/1/proofs/quota/9?signature=stub',
      },
      { id: 10, description: 'Mercado', value: 150, date: '2026-08-05', payerName: 'João', isFixed: false },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');

    const proofLinks = screen.getAllByRole('link', { name: 'Ver comprovante' });
    expect(proofLinks).toHaveLength(1);
    expect(proofLinks[0]).toHaveAttribute('href', '/api/groups/1/proofs/quota/9?signature=stub');

    await user.click(screen.getAllByRole('button', { name: 'Ver detalhes' })[0]);

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByRole('link', { name: 'Ver comprovante' })).toHaveAttribute(
      'href',
      '/api/groups/1/proofs/quota/9?signature=stub'
    );
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

  it('displays the expense date in the details modal without an off-by-one-day shift in negative timezones', async () => {
    mockGetResponses([
      { id: 11, description: 'Assinatura', value: 50, date: '2026-07-16', payerName: 'Isac', isFixed: false },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Assinatura');
    // Data não é mais coluna da tabela (TASK-006) — só aparece no modal de
    // detalhes (TASK-007), aberto pelo ícone "Ver detalhes".
    await userEvent.click(screen.getByRole('button', { name: 'Ver detalhes' }));

    expect(await screen.findByText(/16\/07\/2026/)).toBeInTheDocument();
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

  it('shows status chip and credor with an initials avatar', async () => {
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
    // Coluna "Credor" já dá o rótulo — a célula só tem o avatar de iniciais
    // e o valor, sem prefixo. "Pagadores" não é mais coluna da tabela (fica
    // acessível pelo modal de detalhes, TASK-007).
    expect(screen.getByText('Isac')).toBeInTheDocument();
    expect(screen.getByText('I')).toBeInTheDocument();
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

describe('ExpenseManager - excluir despesa variável', () => {
  const deletableExpense: SummaryExpenseFixture = {
    id: 30,
    description: 'Mercado',
    value: 300,
    date: '2026-08-10',
    payerName: 'Isac',
    paid: false,
    isFixed: false,
    userPayerId: CURRENT_USER_ID,
    userCreatorId: CURRENT_USER_ID,
  };

  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.delete).mockReset();
    mockGetResponses([deletableExpense]);
  });

  it('shows the expense description in the confirmation dialog', async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Excluir despesa' }));

    expect(await screen.findByText('"Mercado"', { exact: false })).toBeInTheDocument();
  });

  it('does not call DELETE when cancelled', async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Excluir despesa' }));
    await screen.findByText('Excluir despesa');
    await userEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

    expect(axios.delete).not.toHaveBeenCalled();
    await waitFor(() => expect(screen.queryByText('Excluir despesa')).not.toBeInTheDocument());
  });

  it('calls DELETE and shows a success toast when confirmed', async () => {
    vi.mocked(axios.delete).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Excluir despesa' }));
    await screen.findByText('Excluir despesa');
    await userEvent.click(screen.getByRole('button', { name: 'Excluir' }));

    await waitFor(() => expect(axios.delete).toHaveBeenCalled());
    const [url] = vi.mocked(axios.delete).mock.calls[0];
    expect(url).toContain('/api/expenses/30');
    expect(await screen.findByText('Despesa excluída com sucesso.')).toBeInTheDocument();
  });

  it('shows the error message returned by the API when deletion fails', async () => {
    vi.mocked(axios.delete).mockRejectedValue({
      response: { data: { error: 'Não é possível excluir uma despesa já paga. Desfaça o pagamento primeiro.' } },
    });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Excluir despesa' }));
    await screen.findByText('Excluir despesa');
    await userEvent.click(screen.getByRole('button', { name: 'Excluir' }));

    expect(
      await screen.findByText('Não é possível excluir uma despesa já paga. Desfaça o pagamento primeiro.')
    ).toBeInTheDocument();
    expect(screen.getByText('Excluir despesa')).toBeInTheDocument();
  });
});

describe('ExpenseManager - fechar/reabrir mês', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
  });

  it('shows "Fechar mês" when the cycle is open and calls POST .../close', async () => {
    mockGetResponses([], { status: 'open' });
    vi.mocked(axios.post).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Fechar mês' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [url] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/groups/1/expenses/close');
    expect(await screen.findByText('Competência fechada com sucesso.')).toBeInTheDocument();
  });

  it('shows "Reabrir mês" when the cycle is closed_manually and calls POST .../reopen', async () => {
    mockGetResponses([], { status: 'closed_manually' });
    vi.mocked(axios.post).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Reabrir mês' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [url] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/groups/1/expenses/reopen');
    expect(await screen.findByText('Competência reaberta com sucesso.')).toBeInTheDocument();
  });

  it('does not show either button when the cycle is automatically closed', async () => {
    mockGetResponses([], { status: 'closed' });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Nenhuma despesa encontrada para esta competência.');
    expect(screen.queryByRole('button', { name: 'Fechar mês' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reabrir mês' })).not.toBeInTheDocument();
  });

  it('does not show either button when the cycle is future', async () => {
    mockGetResponses([], { status: 'future' });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Nenhuma despesa encontrada para esta competência.');
    expect(screen.queryByRole('button', { name: 'Fechar mês' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reabrir mês' })).not.toBeInTheDocument();
  });

  it('hides the buttons after navigating away from the current cycle', async () => {
    const user = userEvent.setup();
    vi.mocked(axios.get).mockImplementation((url: string, config?: { params?: { cycles_ago?: number } }) => {
      if (url.includes('/expenses/summary')) {
        const cyclesAgo = config?.params?.cycles_ago ?? 0;
        const status = cyclesAgo === 0 ? 'open' : 'closed';
        return Promise.resolve({ data: summaryResponse([], { status }) });
      }
      if (url.includes('/api/me')) {
        return Promise.resolve({ data: { id: CURRENT_USER_ID } });
      }
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByRole('button', { name: 'Fechar mês' });

    await user.click(screen.getByLabelText('Competência anterior'));

    await waitFor(() => {
      expect(screen.queryByRole('button', { name: 'Fechar mês' })).not.toBeInTheDocument();
    });
    expect(screen.queryByRole('button', { name: 'Reabrir mês' })).not.toBeInTheDocument();
  });

  it('shows the error message returned by the API when closing fails', async () => {
    mockGetResponses([], { status: 'open' });
    vi.mocked(axios.post).mockRejectedValue({ response: { data: { error: 'Falha genérica de teste.' } } });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Fechar mês' }));

    expect(await screen.findByText('Falha genérica de teste.')).toBeInTheDocument();
  });
});

describe('ExpenseManager - marcar como paga / desfazer pagamento', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
  });

  it('calls POST .../pay and shows a success toast when marking as paid', async () => {
    mockGetResponses([
      {
        id: 40,
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
    vi.mocked(axios.post).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Marcar como paga' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [url] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/expenses/40/pay');
    expect(await screen.findByText('Despesa marcada como paga.')).toBeInTheDocument();
  });

  it('calls POST .../unpay and shows a success toast when undoing payment', async () => {
    mockGetResponses([
      {
        id: 41,
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
    vi.mocked(axios.post).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Desfazer pagamento' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [url] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/expenses/41/unpay');
    expect(await screen.findByText('Pagamento desfeito.')).toBeInTheDocument();
  });

  it('does not show "Desfazer pagamento" for someone who is not the creditor', async () => {
    mockGetResponses([
      {
        id: 42,
        description: 'Mercado',
        value: 300,
        date: '2026-08-10',
        payerName: 'Outra Pessoa',
        paid: true,
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
    expect(screen.queryByRole('button', { name: 'Desfazer pagamento' })).not.toBeInTheDocument();
  });

  it('shows the error message returned by the API when marking as paid fails', async () => {
    mockGetResponses([
      {
        id: 43,
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
    vi.mocked(axios.post).mockRejectedValue({
      response: { data: { error: 'Não é possível alterar dados de uma competência já fechada.' } },
    });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Marcar como paga' }));

    expect(await screen.findByText('Não é possível alterar dados de uma competência já fechada.')).toBeInTheDocument();
  });
});

describe('ExpenseManager - fluxo completo (grid, pagar, despagar, excluir)', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.delete).mockReset();
  });

  it('renders the grid with balances, transitions action icons through pay/unpay, then deletes the expense', async () => {
    const user = userEvent.setup();

    let paid = false;
    let deleted = false;

    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/expenses/summary')) {
        const expensesList: SummaryExpenseFixture[] = deleted
          ? []
          : [
              {
                id: 50,
                description: 'Mercado',
                value: 300,
                date: '2026-08-10',
                payerName: 'Isac',
                paid,
                isFixed: false,
                userPayerId: CURRENT_USER_ID,
                userCreatorId: CURRENT_USER_ID,
              },
            ];
        return Promise.resolve({ data: summaryResponse(expensesList) });
      }
      if (url.includes('/api/me')) {
        return Promise.resolve({ data: { id: CURRENT_USER_ID } });
      }
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });

    vi.mocked(axios.post).mockImplementation((url: string) => {
      if (url.includes('/pay')) {
        paid = true;
        return Promise.resolve({ data: {} });
      }
      if (url.includes('/unpay')) {
        paid = false;
        return Promise.resolve({ data: {} });
      }
      return Promise.reject(new Error(`unexpected POST ${url}`));
    });

    vi.mocked(axios.delete).mockImplementation(() => {
      deleted = true;
      return Promise.resolve({ data: {} });
    });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    // Grid: listagem e o painel lateral (SummarySidePanel) juntos na mesma tela.
    expect(await screen.findByText('Mercado')).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Saldo' })).toBeInTheDocument();

    // Pendente: pode marcar como paga; "Desfazer pagamento" ainda não existe.
    expect(screen.getByRole('button', { name: 'Marcar como paga' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Desfazer pagamento' })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Marcar como paga' }));
    await waitFor(() => expect(screen.getByText('Paga')).toBeInTheDocument());

    // Paga: "Excluir"/"Marcar como paga" somem, "Desfazer pagamento" aparece.
    expect(screen.queryByRole('button', { name: 'Excluir despesa' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Marcar como paga' })).not.toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'Desfazer pagamento' })).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Desfazer pagamento' }));
    await waitFor(() => expect(screen.getByText('Pendente')).toBeInTheDocument());

    // Pendente de novo: "Excluir despesa" volta a existir.
    expect(await screen.findByRole('button', { name: 'Excluir despesa' })).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Excluir despesa' }));
    await screen.findByText('Excluir despesa');
    await user.click(screen.getByRole('button', { name: 'Excluir' }));

    await waitFor(() => {
      expect(screen.getByText('Nenhuma despesa encontrada para esta competência.')).toBeInTheDocument();
    });
  });
});

describe('ExpenseManager - aba "À pagar" do painel lateral', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
  });

  it('shows real settlement data from this page when switching to the "À pagar" tab', async () => {
    const user = userEvent.setup();

    mockGetResponses([], {}, {
      balances: [
        { user_id: 533, name: 'QA Despesas', balance: 550 },
        { user_id: 534, name: 'QA Membro 2', balance: -550 },
      ],
      settlements: [{ from_user_id: 534, to_user_id: 533, amount: 550 }],
    });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByRole('tab', { name: 'Saldo' });

    await user.click(screen.getByRole('tab', { name: 'À pagar' }));

    expect(screen.getByText('QA Membro 2')).toBeInTheDocument();
    expect(screen.getByText('QA Despesas')).toBeInTheDocument();
    expect(screen.getByText('R$ 550,00')).toBeInTheDocument();
    expect(screen.getByText(/deve pagar/)).toBeInTheDocument();
  });
});

describe('ExpenseManager - modal de detalhes', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.delete).mockReset();
  });

  it('shows description, type, status, value, date, credor and pagadores, and closes without navigating or changing data', async () => {
    mockGetResponses([
      {
        id: 60,
        description: 'Internet',
        value: 120,
        date: '2026-08-05',
        payerName: 'Isac',
        participants: ['Isac', 'Maria'],
        paid: false,
        isFixed: true,
      },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Internet');
    await userEvent.click(screen.getByRole('button', { name: 'Ver detalhes' }));

    expect(await screen.findByText('Detalhes da despesa')).toBeInTheDocument();
    const modal = within(screen.getByRole('dialog'));
    expect(modal.getByText('R$ 120,00')).toBeInTheDocument();
    expect(modal.getByText(/05\/08\/2026/)).toBeInTheDocument();
    expect(modal.getByText('Credor: Isac')).toBeInTheDocument();
    expect(modal.getByText('Isac, Maria')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Fechar' }));

    await waitFor(() => {
      expect(screen.queryByText('Detalhes da despesa')).not.toBeInTheDocument();
    });
    expect(navigateMock).not.toHaveBeenCalled();
    expect(axios.post).not.toHaveBeenCalled();
    expect(axios.delete).not.toHaveBeenCalled();
  });
});
