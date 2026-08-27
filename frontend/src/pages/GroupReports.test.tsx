import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GroupReports from './GroupReports';

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

const cycleJune = {
  cycle: { start: '2026-06-01', end: '2026-06-30', status: 'closed' as const },
  totals: { total: 500, paid: 500, pending: 0 },
  expenses: [
    {
      id: 10,
      description: 'Aluguel',
      date: '2026-06-05',
      value: 500,
      paid: true,
      payerName: 'QA Relatórios',
      participants: ['QA Relatórios'],
      isFixed: true,
    },
  ],
  balances: [{ user_id: 1, name: 'QA Relatórios', balance: 0 }],
  settlements: [] as { from_user_id: number; to_user_id: number; amount: number }[],
};

const cycleJuly = {
  cycle: { start: '2026-07-01', end: '2026-07-31', status: 'closed' as const },
  totals: { total: 300, paid: 0, pending: 300 },
  expenses: [] as typeof cycleJune.expenses,
  balances: [{ user_id: 1, name: 'QA Relatórios', balance: 0 }],
  settlements: [] as { from_user_id: number; to_user_id: number; amount: number }[],
};

function mockCyclesResponse(data: typeof cycleJune[] = [cycleJuly, cycleJune], lastPage = 1) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/expenses/cycles')) {
      return Promise.resolve({ data: { data, current_page: 1, last_page: lastPage } });
    }
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

describe('GroupReports', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
  });

  it('shows a message when there are no closed cycles yet', async () => {
    mockCyclesResponse([]);

    render(
      <MemoryRouter>
        <GroupReports />
      </MemoryRouter>
    );

    expect(await screen.findByText('Nenhum ciclo fechado ainda.')).toBeInTheDocument();
  });

  it('lists the closed cycles most recent first', async () => {
    mockCyclesResponse();

    render(
      <MemoryRouter>
        <GroupReports />
      </MemoryRouter>
    );

    expect(await screen.findByText(/jul.*jul/i)).toBeInTheDocument();
    expect(screen.getByText(/jun.*jun/i)).toBeInTheDocument();
    expect(screen.getByText('Total: R$ 300,00')).toBeInTheDocument();
    expect(screen.getByText('Total: R$ 500,00')).toBeInTheDocument();
  });

  it('shows the detail panel for the selected cycle', async () => {
    mockCyclesResponse();
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <GroupReports />
      </MemoryRouter>
    );

    await screen.findByText(/jun.*jun/i);

    expect(screen.queryByText('Aluguel')).not.toBeInTheDocument();

    await user.click(screen.getByText(/jun.*jun/i));

    expect(await screen.findByText(/Aluguel/)).toBeInTheDocument();
  });
});
