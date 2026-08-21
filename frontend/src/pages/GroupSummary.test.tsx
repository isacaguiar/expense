import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GroupSummary from './GroupSummary';

vi.mock('axios');

const navigateMock = vi.fn();

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => navigateMock,
    useParams: () => ({ id: '1' }),
  };
});

const groups = [
  { id: 1, name: 'Grupo A' },
  { id: 2, name: 'Grupo B' },
];

const summaryResponse = {
  cycle: { start: '2026-07-16', end: '2026-08-15' },
  totals: { total: 1100, paid: 300, pending: 800 },
  expenses: [
    {
      id: 190,
      description: 'Geladeira',
      date: '2026-07-20',
      value: 300,
      paid: false,
      payerName: 'QA Resumo',
      participants: ['QA Resumo', 'QA Membro 2'],
      isFixed: false,
    },
    {
      id: 189,
      description: 'Mercado',
      date: '2026-08-01',
      value: 300,
      paid: true,
      payerName: 'QA Resumo',
      participants: ['QA Resumo', 'QA Membro 2'],
      isFixed: false,
    },
  ],
  balances: [
    { user_id: 533, name: 'QA Resumo', balance: 550 },
    { user_id: 534, name: 'QA Membro 2', balance: -550 },
  ],
};

const currentUser = { name: 'QA Header Usuario', email: 'qa-header@example.com' };

function mockGetResponses(summaryOverride: typeof summaryResponse = summaryResponse) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/expenses/summary')) {
      return Promise.resolve({ data: summaryOverride });
    }
    if (url.includes('/api/groups')) {
      return Promise.resolve({ data: groups });
    }
    if (url.includes('/api/me')) {
      return Promise.resolve({ data: currentUser });
    }
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

describe('GroupSummary', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    mockGetResponses();
  });

  it('renders the totals cards from the API', async () => {
    render(
      <MemoryRouter>
        <GroupSummary />
      </MemoryRouter>
    );

    expect(await screen.findByText('R$ 1.100,00')).toBeInTheDocument();
    expect(screen.getByText('R$ 300,00')).toBeInTheDocument();
    expect(screen.getByText('R$ 800,00')).toBeInTheDocument();
  });

  it('shows a status chip per expense matching paid', async () => {
    render(
      <MemoryRouter>
        <GroupSummary />
      </MemoryRouter>
    );

    await screen.findByText(/Geladeira/);

    expect(screen.getByText('Pendente')).toBeInTheDocument();
    expect(screen.getByText('Paga')).toBeInTheDocument();
  });

  it('shows balances with a receber/a pagar matching the sign', async () => {
    render(
      <MemoryRouter>
        <GroupSummary />
      </MemoryRouter>
    );

    expect(await screen.findByText(/R\$ 550,00 a receber/)).toBeInTheDocument();
    expect(screen.getByText(/R\$ 550,00 a pagar/)).toBeInTheDocument();
  });

  it('requests the previous cycle when the back arrow is clicked', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <GroupSummary />
      </MemoryRouter>
    );

    await screen.findByText('R$ 1.100,00');

    await user.click(screen.getByLabelText('Ciclo anterior'));

    await waitFor(() => {
      const lastCall = vi.mocked(axios.get).mock.calls.at(-1);
      expect(lastCall?.[0]).toContain('/expenses/summary');
      expect((lastCall?.[1] as { params: { cycles_ago: number } }).params.cycles_ago).toBe(1);
    });
  });

  it('navigates to the selected group when the group dropdown changes', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <GroupSummary />
      </MemoryRouter>
    );

    await screen.findByText('R$ 1.100,00');

    await user.click(await screen.findByRole('combobox'));
    await user.click(await screen.findByRole('option', { name: 'Grupo B' }));

    expect(navigateMock).toHaveBeenCalledWith('/groups/2/summary');
  });

  it('sidebar links Resumo/Despesas/Participantes/Configurações to real routes and leaves the rest as placeholders', async () => {
    render(
      <MemoryRouter initialEntries={['/groups/1/summary']}>
        <GroupSummary />
      </MemoryRouter>
    );

    await screen.findByText('R$ 1.100,00');

    expect(screen.getByRole('link', { name: /Resumo/ })).toHaveAttribute('href', '/groups/1/summary');
    expect(screen.getByRole('link', { name: /Despesas/ })).toHaveAttribute('href', '/groups/1/expenses');
    expect(screen.getByRole('link', { name: /Participantes/ })).toHaveAttribute('href', '/groups/1/members');
    expect(screen.getByRole('link', { name: /Pagamentos/ })).toHaveAttribute('href', '#');
    expect(screen.getByRole('link', { name: /Relatórios/ })).toHaveAttribute('href', '#');
    expect(screen.getByRole('link', { name: /Configurações/ })).toHaveAttribute('href', '/groups/1/edit');
  });

  it('shows the logged-in user name and initials from GET /api/me', async () => {
    render(
      <MemoryRouter>
        <GroupSummary />
      </MemoryRouter>
    );

    expect(await screen.findByText('QA Header Usuario')).toBeInTheDocument();
    expect(screen.getByText('QH')).toBeInTheDocument();
  });

  it('shows the paid/pending percentage of the total', async () => {
    render(
      <MemoryRouter>
        <GroupSummary />
      </MemoryRouter>
    );

    expect(await screen.findByText('27% do total')).toBeInTheDocument();
    expect(screen.getByText('73% do total')).toBeInTheDocument();
  });

  it('does not break (no NaN) when the cycle total is zero', async () => {
    mockGetResponses({ ...summaryResponse, totals: { total: 0, paid: 0, pending: 0 } });

    render(
      <MemoryRouter>
        <GroupSummary />
      </MemoryRouter>
    );

    expect(await screen.findAllByText('0% do total')).toHaveLength(2);
    expect(screen.queryByText(/NaN/)).not.toBeInTheDocument();
  });
});
