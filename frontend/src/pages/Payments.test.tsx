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

type SummaryOverrides = {
  cycle?: Record<string, unknown>;
  balances?: { user_id: number; name: string; balance: number }[];
  settlements?: { from_user_id: number; to_user_id: number; amount: number }[];
};

function summaryResponse(expensesList: SummaryExpenseFixture[] = [], overrides: SummaryOverrides = {}) {
  return {
    cycle: { start: '2026-08-01', end: '2026-08-31', status: 'open', ...overrides.cycle },
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
    balances: overrides.balances ?? [],
    settlements: overrides.settlements ?? [],
  };
}

type MemberFixture = { id: number; name: string; email: string; pix: string | null };

function mockGetResponses(
  expensesList: SummaryExpenseFixture[] = [],
  overrides: SummaryOverrides = {},
  members: MemberFixture[] = []
) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/expenses/summary')) {
      return Promise.resolve({ data: summaryResponse(expensesList, overrides) });
    }
    if (url.includes('/api/me')) {
      return Promise.resolve({ data: { id: CURRENT_USER_ID } });
    }
    if (url.includes('/members')) {
      return Promise.resolve({ data: members });
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
    expect(body).toEqual({ cycles_ago: 0 });
    expect(await screen.findByText('Pagamento desfeito.')).toBeInTheDocument();
  });

  it('shows the "valores a pagar" region and opens the Pix dialog when the creditor has a key registered', async () => {
    const user = userEvent.setup();

    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/pix/generate')) {
        return Promise.resolve({ data: { qrcode: 'data:image/png;base64,abc', copiacola: '00020126...6304ABCD' } });
      }
      if (url.includes('/expenses/summary')) {
        return Promise.resolve({
          data: summaryResponse([], {
            balances: [
              { user_id: CURRENT_USER_ID, name: 'Isac', balance: -50 },
              { user_id: 999, name: 'Maria', balance: 50 },
            ],
            settlements: [{ from_user_id: CURRENT_USER_ID, to_user_id: 999, amount: 50 }],
          })
        });
      }
      if (url.includes('/api/me')) {
        return Promise.resolve({ data: { id: CURRENT_USER_ID } });
      }
      if (url.includes('/members')) {
        return Promise.resolve({ data: [{ id: 999, name: 'Maria', email: 'maria@example.com', pix: 'maria@pix.com' }] });
      }
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });

    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );

    await user.click(await screen.findByRole('button', { name: 'Pagar com Pix' }));

    expect(await screen.findByRole('img', { name: /QR Code Pix para pagar Maria/ })).toBeInTheDocument();
    expect(screen.getByDisplayValue('00020126...6304ABCD')).toBeInTheDocument();

    const [, config] = vi.mocked(axios.get).mock.calls.find(call => (call[0] as string).includes('/pix/generate'))!;
    expect((config as { params?: Record<string, unknown> }).params).toMatchObject({
      email: 'maria@example.com',
      valor: '50.00',
    });
  });

  it('warns instead of opening the dialog when the creditor has no Pix key', async () => {
    const user = userEvent.setup();

    mockGetResponses(
      [],
      {
        balances: [
          { user_id: CURRENT_USER_ID, name: 'Isac', balance: -50 },
          { user_id: 999, name: 'Maria', balance: 50 },
        ],
        settlements: [{ from_user_id: CURRENT_USER_ID, to_user_id: 999, amount: 50 }],
      },
      [{ id: 999, name: 'Maria', email: 'maria@example.com', pix: null }]
    );

    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );

    await user.click(await screen.findByRole('button', { name: 'Pagar com Pix' }));

    expect(await screen.findByText('Maria ainda não cadastrou uma chave Pix.')).toBeInTheDocument();
    expect(screen.queryByRole('img', { name: /QR Code Pix/ })).not.toBeInTheDocument();
    expect(vi.mocked(axios.get).mock.calls.some(call => (call[0] as string).includes('/pix/generate'))).toBe(false);
  });

  it('sends the debtor proof as multipart to /settlements/confirm and shows a success message', async () => {
    const user = userEvent.setup();

    mockGetResponses(
      [],
      {
        balances: [
          { user_id: CURRENT_USER_ID, name: 'Isac', balance: -50 },
          { user_id: 999, name: 'Maria', balance: 50 },
        ],
        settlements: [{ from_user_id: CURRENT_USER_ID, to_user_id: 999, amount: 50 }],
      },
      [{ id: 999, name: 'Maria', email: 'maria@example.com', pix: 'maria@pix.com' }]
    );
    vi.mocked(axios.post).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );

    await user.click(await screen.findByRole('button', { name: 'Enviar comprovante' }));

    const confirmButton = await screen.findByRole('button', { name: 'Confirmar' });
    expect(confirmButton).toBeDisabled();

    const file = new File(['foto'], 'pix.png', { type: 'image/png' });
    const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement;
    await user.upload(fileInput, file);

    expect(confirmButton).toBeEnabled();

    await user.click(confirmButton);

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [url, body] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/groups/1/settlements/confirm');
    expect(body).toBeInstanceOf(FormData);
    expect((body as FormData).get('to_user_id')).toBe('999');
    expect((body as FormData).get('comprovante')).toBe(file);

    expect(await screen.findByText('Comprovante enviado.')).toBeInTheDocument();
  });
});
