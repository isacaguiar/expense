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

type CycleStatus = 'closed' | 'open' | 'future' | 'closed_manually';

const summaryResponse = {
  cycle: { start: '2026-07-16', end: '2026-08-15', status: 'closed' as CycleStatus },
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
  settlements: [] as { from_user_id: number; to_user_id: number; amount: number }[],
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

  it('requests the next cycle when the forward arrow is clicked, even past the current cycle', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <GroupSummary />
      </MemoryRouter>
    );

    await screen.findByText('R$ 1.100,00');

    await user.click(screen.getByLabelText('Próximo ciclo'));

    await waitFor(() => {
      const lastCall = vi.mocked(axios.get).mock.calls.at(-1);
      expect(lastCall?.[0]).toContain('/expenses/summary');
      expect((lastCall?.[1] as { params: { cycles_ago: number } }).params.cycles_ago).toBe(-1);
    });
  });

  it.each([
    ['closed', 'Ciclo fechado'],
    ['open', 'Ciclo em andamento'],
    ['future', 'Ciclo futuro'],
  ] as const)('shows a %s cycle status chip', async (status, label) => {
    mockGetResponses({ ...summaryResponse, cycle: { ...summaryResponse.cycle, status } });

    render(
      <MemoryRouter>
        <GroupSummary />
      </MemoryRouter>
    );

    expect(await screen.findByText(label)).toBeInTheDocument();
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

  it('shows an empty state in the "À pagar" tab when settlements is empty', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <GroupSummary />
      </MemoryRouter>
    );

    await screen.findByText('R$ 1.100,00');

    await user.click(screen.getByRole('tab', { name: 'À pagar' }));

    expect(screen.getByText('Nenhuma pendência entre os membros neste ciclo.')).toBeInTheDocument();
  });

  it('shows settlements in the "À pagar" tab, resolving names from balances', async () => {
    const user = userEvent.setup();

    mockGetResponses({
      ...summaryResponse,
      settlements: [{ from_user_id: 534, to_user_id: 533, amount: 550 }],
    });

    render(
      <MemoryRouter>
        <GroupSummary />
      </MemoryRouter>
    );

    await screen.findByText('R$ 1.100,00');

    await user.click(screen.getByRole('tab', { name: 'À pagar' }));

    // Só a aba "À pagar" está visível agora (a "Saldo" some do DOM ao
    // trocar de aba, render condicional em SummarySidePanel), então cada
    // nome aparece uma única vez (dentro de SettlementList).
    expect(screen.getByText('QA Membro 2')).toBeInTheDocument();
    expect(screen.getByText('QA Resumo')).toBeInTheDocument();
    expect(screen.getByText('R$ 550,00')).toBeInTheDocument();
    expect(screen.getByText(/deve pagar/)).toBeInTheDocument();
  });

  it('has the "Saldo" tab selected by default', async () => {
    render(
      <MemoryRouter>
        <GroupSummary />
      </MemoryRouter>
    );

    await screen.findByText('R$ 1.100,00');

    expect(screen.getByRole('tab', { name: 'Saldo' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('tab', { name: 'À pagar' })).toHaveAttribute('aria-selected', 'false');
  });

  it('does not trigger a new API call when switching between tabs', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <GroupSummary />
      </MemoryRouter>
    );

    await screen.findByText('R$ 1.100,00');

    const callsBefore = vi.mocked(axios.get).mock.calls.length;

    await user.click(screen.getByRole('tab', { name: 'À pagar' }));
    await user.click(screen.getByRole('tab', { name: 'Saldo' }));

    expect(vi.mocked(axios.get).mock.calls.length).toBe(callsBefore);
  });

  it.each([
    ['closed', 'Definitivo'],
    ['closed_manually', 'Definitivo'],
    ['open', 'Prévia'],
    ['future', 'Prévia'],
  ] as const)('shows the volatility badge for cycle status %s', async (status, badgeLabel) => {
    mockGetResponses({ ...summaryResponse, cycle: { ...summaryResponse.cycle, status } });

    render(
      <MemoryRouter>
        <GroupSummary />
      </MemoryRouter>
    );

    expect(await screen.findByText(badgeLabel)).toBeInTheDocument();
  });
});
