import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Payments from './Payments';

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

type SummaryExpenseFixture = {
  id: number;
  description: string;
  date: string;
  value: number;
  valuePerPerson?: number;
  paid?: boolean;
  paymentProofUrl?: string | null;
  payerName?: string | null;
  participants?: string[];
  isFixed?: boolean;
  userPayerId?: number;
  userCreatorId?: number;
};

const CURRENT_USER_ID = 700;

function summaryResponse(expensesList: SummaryExpenseFixture[] = [], cycleOverride: Record<string, unknown> = {}) {
  return {
    cycle: { start: '2026-08-01', end: '2026-08-31', status: 'open', ...cycleOverride },
    totals: { total: 0, paid: 0, pending: 0 },
    expenses: expensesList.map(exp => ({
      paid: false,
      paymentProofUrl: null,
      valuePerPerson: exp.value,
      participants: [],
      userPayerId: CURRENT_USER_ID,
      userCreatorId: CURRENT_USER_ID,
      ...exp,
    })),
    balances: [],
    settlements: [],
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

describe('Payments', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
  });

  it('renders each expense with creditor, total value, value per person and payers', async () => {
    mockGetResponses([
      {
        id: 10,
        description: 'Mercado',
        date: '2026-08-10',
        value: 300,
        valuePerPerson: 100,
        payerName: 'Isac',
        participants: ['Isac', 'João', 'Maria'],
        userPayerId: 999,
      },
    ]);

    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );

    await screen.findByText('Mercado');

    expect(screen.getByText('Credor: Isac')).toBeInTheDocument();
    expect(screen.getByText('Valor Total: R$ 300,00')).toBeInTheDocument();
    expect(screen.getByText('Valor por pessoa: R$ 100,00')).toBeInTheDocument();
    expect(screen.getByText('Pagadores: Isac, João, Maria')).toBeInTheDocument();
  });

  it('does not show "Confirmar pagamento" for someone who is not the creditor', async () => {
    mockGetResponses([
      {
        id: 11,
        description: 'Aluguel',
        date: '2026-08-10',
        value: 500,
        userPayerId: 999,
      },
    ]);

    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');

    expect(screen.queryByRole('button', { name: 'Confirmar pagamento' })).not.toBeInTheDocument();
  });

  it('requires a photo before the confirm dialog can be submitted, then posts multipart to /pay', async () => {
    const user = userEvent.setup();

    mockGetResponses([
      {
        id: 12,
        description: 'Água',
        date: '2026-08-10',
        value: 150,
        userPayerId: CURRENT_USER_ID,
      },
    ]);
    vi.mocked(axios.post).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );

    await user.click(await screen.findByRole('button', { name: 'Confirmar pagamento' }));

    const confirmButton = await screen.findByRole('button', { name: 'Confirmar' });
    expect(confirmButton).toBeDisabled();

    const file = new File(['foto'], 'comprovante.png', { type: 'image/png' });
    const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement;
    await user.upload(fileInput, file);

    expect(confirmButton).toBeEnabled();

    await user.click(confirmButton);

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [url, body] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/expenses/12/pay');
    expect(body).toBeInstanceOf(FormData);
    expect((body as FormData).get('comprovante')).toBe(file);

    expect(await screen.findByText('Pagamento confirmado.')).toBeInTheDocument();
  });

  it('shows "Desfazer pagamento" for a paid expense and calls POST .../unpay', async () => {
    const user = userEvent.setup();

    mockGetResponses([
      {
        id: 13,
        description: 'Internet',
        date: '2026-08-10',
        value: 120,
        paid: true,
        userPayerId: CURRENT_USER_ID,
      },
    ]);
    vi.mocked(axios.post).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );

    await user.click(await screen.findByRole('button', { name: 'Desfazer pagamento' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [url, body] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/expenses/13/unpay');
    expect(body).toBeNull();
    expect(await screen.findByText('Pagamento desfeito.')).toBeInTheDocument();
  });
});
