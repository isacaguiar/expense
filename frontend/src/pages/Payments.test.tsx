import { render, screen, waitFor, within } from '@testing-library/react';
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
  paymentNote?: string | null;
  payerName?: string | null;
  participants?: string[];
  participantDetails?: { id: number; name: string; avatarUrl: string | null }[];
  isFixed?: boolean;
  expenseType?: 'IN_CASH' | 'IN_INSTALLMENTS' | 'FIXED';
  installmentNumber?: number | null;
  installmentsTotal?: number;
  userPayerId?: number;
  userCreatorId?: number;
};

const CURRENT_USER_ID = 700;

type SettlementFixture = {
  from_user_id: number;
  to_user_id: number;
  amount: number;
  confirmedAt?: string | null;
  confirmedProofUrl?: string | null;
  confirmedNote?: string | null;
};

type SummaryOverrides = {
  cycle?: Record<string, unknown>;
  balances?: { user_id: number; name: string; balance: number }[];
  settlements?: SettlementFixture[];
};

function summaryResponse(expensesList: SummaryExpenseFixture[] = [], overrides: SummaryOverrides = {}) {
  return {
    cycle: { start: '2026-08-01', end: '2026-08-31', closes_at: '2026-09-05', status: 'open', settled: false, ...overrides.cycle },
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
  members: MemberFixture[] = [],
  focusCyclesAgo: number | null = null
) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    // Sem `focusCyclesAgo` o focus-cycle continua "inesperado" (a tela cai no
    // ciclo corrente, como nos demais testes). Com ele, a tela abre no ciclo
    // fechado que o backend aponta — o caminho real de quem tem acerto pendente.
    if (url.includes('/expenses/focus-cycle') && focusCyclesAgo !== null) {
      return Promise.resolve({ data: { cycles_ago: focusCyclesAgo } });
    }
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

    expect(screen.getByText('Credor:')).toBeInTheDocument();
    expect(screen.getAllByText('Isac').length).toBeGreaterThan(0);
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

  it('keeps Confirmar disabled until a photo or a note is given, then posts multipart to /pay', async () => {
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

  // Decisão D2 da feature comprovante-ao-marcar-paga: o popup do credor passou
  // a aceitar "foto OU descrição" (antes, foto obrigatória), igual ao de Despesas.
  it('lets the creditor confirm with only a note and posts it as JSON', async () => {
    const user = userEvent.setup();

    mockGetResponses([
      { id: 14, description: 'Água', date: '2026-08-10', value: 150, userPayerId: CURRENT_USER_ID },
    ]);
    vi.mocked(axios.post).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );

    await user.click(await screen.findByRole('button', { name: 'Confirmar pagamento' }));

    const dialog = await screen.findByRole('dialog');
    const confirmButton = within(dialog).getByRole('button', { name: 'Confirmar' });
    expect(confirmButton).toBeDisabled();

    await user.type(within(dialog).getByLabelText('Descrição'), 'Pago em dinheiro, recibo 123');
    expect(confirmButton).toBeEnabled();
    await user.click(confirmButton);

    await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
    const [url, body] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/expenses/14/pay');
    expect(body).toEqual({ cycles_ago: 0, descricao: 'Pago em dinheiro, recibo 123' });
    expect(await screen.findByText('Pagamento confirmado.')).toBeInTheDocument();
  });

  it('posts the photo and the note together in the multipart body', async () => {
    const user = userEvent.setup();

    mockGetResponses([
      { id: 15, description: 'Luz', date: '2026-08-10', value: 150, userPayerId: CURRENT_USER_ID },
    ]);
    vi.mocked(axios.post).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );

    await user.click(await screen.findByRole('button', { name: 'Confirmar pagamento' }));

    const dialog = await screen.findByRole('dialog');
    const file = new File(['foto'], 'comprovante.png', { type: 'image/png' });
    await user.upload(dialog.querySelector('input[type="file"]') as HTMLInputElement, file);
    await user.type(within(dialog).getByLabelText('Descrição'), 'Pix agendado para sexta');
    await user.click(within(dialog).getByRole('button', { name: 'Confirmar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
    const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
    expect(body.get('comprovante')).toBe(file);
    expect(body.get('descricao')).toBe('Pix agendado para sexta');
    expect(body.get('cycles_ago')).toBe('0');
  });

  it('shows the payment note on the card of a paid expense, with or without a photo', async () => {
    mockGetResponses([
      {
        id: 16,
        description: 'Só texto',
        date: '2026-08-10',
        value: 100,
        paid: true,
        paymentNote: 'Pago em dinheiro',
        paymentProofUrl: null,
        userPayerId: 999,
      },
      {
        id: 17,
        description: 'Foto e texto',
        date: '2026-08-11',
        value: 100,
        paid: true,
        paymentNote: 'Pix agendado',
        paymentProofUrl: 'http://api/proofs/1',
        userPayerId: 999,
      },
    ]);

    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );

    await screen.findByText('Só texto');

    expect(screen.getByText('Descrição do pagamento: Pago em dinheiro')).toBeInTheDocument();
    expect(screen.getByText('Descrição do pagamento: Pix agendado')).toBeInTheDocument();
    // O link só existe quando há foto.
    expect(screen.getAllByRole('link', { name: 'Ver comprovante' })).toHaveLength(1);
  });

  it('shows no payment note line when the expense has none, and renders markup as plain text', async () => {
    mockGetResponses([
      { id: 18, description: 'Sem nota', date: '2026-08-10', value: 100, paid: true, paymentNote: null, userPayerId: 999 },
      { id: 19, description: 'Nota com tag', date: '2026-08-11', value: 100, paid: true, paymentNote: '<b>negrito</b>', userPayerId: 999 },
    ]);

    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );

    await screen.findByText('Sem nota');

    expect(screen.getAllByText(/Descrição do pagamento:/)).toHaveLength(1);
    expect(screen.getByText('Descrição do pagamento: <b>negrito</b>')).toBeInTheDocument();
    expect(document.querySelector('b')).toBeNull();
  });

  it('does not show a note line for a pending expense', async () => {
    mockGetResponses([
      { id: 20, description: 'Conta de luz', date: '2026-08-10', value: 100, paid: false, paymentNote: null, userPayerId: 999 },
    ]);

    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );

    await screen.findByText('Conta de luz');
    expect(screen.getByText('Pendente')).toBeInTheDocument();
    expect(screen.queryByText(/Descrição do pagamento:/)).not.toBeInTheDocument();
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
            cycle: { status: 'closed' },
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
        cycle: { status: 'closed' },
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
        cycle: { status: 'closed' },
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
    expect((body as FormData).get('cycles_ago')).toBe('0');

    expect(await screen.findByText('Comprovante enviado.')).toBeInTheDocument();
  });

  describe('popup do devedor (acerto) com descrição', () => {
    const debtorSetup = () => {
      mockGetResponses(
        [],
        {
          balances: [
            { user_id: CURRENT_USER_ID, name: 'Isac', balance: -50 },
            { user_id: 999, name: 'Maria', balance: 50 },
          ],
          settlements: [{ from_user_id: CURRENT_USER_ID, to_user_id: 999, amount: 50 }],
          cycle: { status: 'closed' },
        },
        [{ id: 999, name: 'Maria', email: 'maria@example.com', pix: 'maria@pix.com' }]
      );

      render(
        <MemoryRouter>
          <Payments />
        </MemoryRouter>
      );
    };

    const openDebtorDialog = async (user: ReturnType<typeof userEvent.setup>) => {
      await user.click(await screen.findByRole('button', { name: 'Enviar comprovante' }));
      return screen.findByRole('dialog');
    };

    it('keeps the photo mandatory: a note alone does not enable Confirmar', async () => {
      const user = userEvent.setup();
      debtorSetup();

      const dialog = await openDebtorDialog(user);
      await user.type(within(dialog).getByLabelText('Descrição (opcional)'), 'Pix agendado para sexta');

      expect(within(dialog).getByRole('button', { name: 'Confirmar' })).toBeDisabled();
      expect(axios.post).not.toHaveBeenCalled();
    });

    it('sends the note as descricao together with the photo and cycles_ago', async () => {
      const user = userEvent.setup();
      debtorSetup();
      vi.mocked(axios.post).mockResolvedValue({ data: {} });

      const dialog = await openDebtorDialog(user);
      const file = new File(['foto'], 'pix.png', { type: 'image/png' });
      await user.upload(dialog.querySelector('input[type="file"]') as HTMLInputElement, file);
      await user.type(within(dialog).getByLabelText('Descrição (opcional)'), 'Pix agendado para sexta');
      await user.click(within(dialog).getByRole('button', { name: 'Confirmar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
      expect(body.get('comprovante')).toBe(file);
      expect(body.get('descricao')).toBe('Pix agendado para sexta');
      expect(body.get('to_user_id')).toBe('999');
      expect(body.get('cycles_ago')).toBe('0');
      expect(await screen.findByText('Comprovante enviado.')).toBeInTheDocument();
    });

    it('does not send descricao when the note is left blank', async () => {
      const user = userEvent.setup();
      debtorSetup();
      vi.mocked(axios.post).mockResolvedValue({ data: {} });

      const dialog = await openDebtorDialog(user);
      await user.upload(
        dialog.querySelector('input[type="file"]') as HTMLInputElement,
        new File(['foto'], 'pix.png', { type: 'image/png' })
      );
      await user.type(within(dialog).getByLabelText('Descrição (opcional)'), '   ');
      await user.click(within(dialog).getByRole('button', { name: 'Confirmar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      expect((vi.mocked(axios.post).mock.calls[0][1] as FormData).has('descricao')).toBe(false);
    });

    it('shows the server error inside the popup and keeps it open', async () => {
      const user = userEvent.setup();
      debtorSetup();
      vi.mocked(axios.post).mockRejectedValue({
        response: { data: { error: 'O acerto só pode ser confirmado depois que a competência é fechada.' } },
      });

      const dialog = await openDebtorDialog(user);
      await user.upload(
        dialog.querySelector('input[type="file"]') as HTMLInputElement,
        new File(['foto'], 'pix.png', { type: 'image/png' })
      );
      await user.click(within(dialog).getByRole('button', { name: 'Confirmar' }));

      expect(
        await within(dialog).findByText('O acerto só pode ser confirmado depois que a competência é fechada.')
      ).toBeInTheDocument();
      expect(screen.getByRole('dialog')).toBeInTheDocument();
    });

    it('closes without sending anything when cancelled', async () => {
      const user = userEvent.setup();
      debtorSetup();

      const dialog = await openDebtorDialog(user);
      await user.click(within(dialog).getByRole('button', { name: 'Cancelar' }));

      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
      expect(axios.post).not.toHaveBeenCalled();
    });
  });

  // Regressão: o envio do comprovante do acerto não mandava `cycles_ago`, então o
  // backend avaliava o ciclo CORRENTE (aberto) em vez do que está na tela e
  // respondia 422 "O acerto só pode ser confirmado depois que a competência é
  // fechada." para quem acertava um ciclo anterior já fechado.
  it('sends the cycles_ago of the cycle on screen when the debtor confirms a past closed cycle', async () => {
    const user = userEvent.setup();

    mockGetResponses(
      [],
      {
        balances: [
          { user_id: CURRENT_USER_ID, name: 'Isac', balance: -50 },
          { user_id: 999, name: 'Maria', balance: 50 },
        ],
        settlements: [{ from_user_id: CURRENT_USER_ID, to_user_id: 999, amount: 50 }],
        cycle: { start: '2026-09-01', end: '2026-09-30', status: 'closed' },
      },
      [{ id: 999, name: 'Maria', email: 'maria@example.com', pix: 'maria@pix.com' }],
      1
    );
    vi.mocked(axios.post).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );

    await user.click(await screen.findByRole('button', { name: 'Enviar comprovante' }));

    const file = new File(['foto'], 'pix.png', { type: 'image/png' });
    const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement;
    await user.upload(fileInput, file);
    await user.click(await screen.findByRole('button', { name: 'Confirmar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [url, body] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/groups/1/settlements/confirm');
    expect((body as FormData).get('to_user_id')).toBe('999');
    expect((body as FormData).get('cycles_ago')).toBe('1');
  });
});

describe('Payments - editar comprovante depois de paga', () => {
  const PROOF_URL = 'https://api.exemplo.com/api/proofs/9?signature=abc';
  const paid: SummaryExpenseFixture = {
    id: 30,
    description: 'Mercado',
    date: '2026-08-10',
    value: 300,
    paid: true,
    userPayerId: CURRENT_USER_ID,
    paymentProofUrl: PROOF_URL,
    paymentNote: 'Pix agendado',
  };

  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    mockGetResponses([paid]);
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
  });

  const renderPayments = () =>
    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );

  const openEditDialog = async (user: ReturnType<typeof userEvent.setup>) => {
    await user.click(await screen.findByRole('button', { name: 'Editar comprovante' }));
    return screen.findByRole('dialog');
  };

  describe('visibilidade do botão', () => {
    it('shows "Editar comprovante" on the card of a paid expense for its creditor, before "Desfazer pagamento"', async () => {
      renderPayments();

      const edit = await screen.findByRole('button', { name: 'Editar comprovante' });
      const undo = screen.getByRole('button', { name: 'Desfazer pagamento' });
      expect(edit.compareDocumentPosition(undo) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('also shows it on a paid expense that has neither photo nor description', async () => {
      mockGetResponses([{ ...paid, paymentProofUrl: null, paymentNote: null }]);
      renderPayments();

      expect(await screen.findByRole('button', { name: 'Editar comprovante' })).toBeInTheDocument();
    });

    it('does not show it for someone who is not the creditor', async () => {
      mockGetResponses([{ ...paid, userPayerId: 999 }]);
      renderPayments();

      await screen.findByText('Mercado');
      expect(screen.queryByRole('button', { name: 'Editar comprovante' })).not.toBeInTheDocument();
    });

    it('does not show it on a pending expense', async () => {
      mockGetResponses([{ ...paid, paid: false }]);
      renderPayments();

      await screen.findByText('Mercado');
      expect(screen.queryByRole('button', { name: 'Editar comprovante' })).not.toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Confirmar pagamento' })).toBeInTheDocument();
    });

    it('does not show it in a sealed (settled) cycle, where "Desfazer pagamento" is still offered', async () => {
      mockGetResponses([paid], { cycle: { status: 'closed', settled: true } });
      renderPayments();

      await screen.findByText('Mercado');
      expect(screen.queryByRole('button', { name: 'Editar comprovante' })).not.toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Desfazer pagamento' })).toBeInTheDocument();
    });

    it('shows it in a closed cycle that is not sealed yet', async () => {
      mockGetResponses([paid], { cycle: { status: 'closed', settled: false } });
      renderPayments();

      expect(await screen.findByRole('button', { name: 'Editar comprovante' })).toBeInTheDocument();
    });

    it('does not show it in a future cycle', async () => {
      mockGetResponses([paid], { cycle: { status: 'future' } });
      renderPayments();

      await screen.findByText('Mercado');
      expect(screen.queryByRole('button', { name: 'Editar comprovante' })).not.toBeInTheDocument();
    });
  });

  describe('popup', () => {
    it('opens "Editar comprovante" already filled with the current description and photo, sending nothing yet', async () => {
      const user = userEvent.setup();
      renderPayments();

      const dialog = await openEditDialog(user);

      expect(within(dialog).getByText('Editar comprovante')).toBeInTheDocument();
      expect(within(dialog).getByText(/O pagamento continua marcado como pago/)).toBeInTheDocument();
      expect(within(dialog).getByLabelText('Descrição')).toHaveValue('Pix agendado');
      expect(within(dialog).getByRole('link', { name: 'Ver foto atual' })).toHaveAttribute('href', PROOF_URL);
      expect(within(dialog).getByRole('checkbox', { name: 'Remover a foto atual' })).not.toBeChecked();
      expect(within(dialog).getByText('Trocar a foto')).toBeInTheDocument();
      expect(within(dialog).getByRole('button', { name: 'Salvar' })).toBeDisabled();
      expect(axios.post).not.toHaveBeenCalled();
    });

    it('opens empty, with no photo link and "Selecionar foto", for a paid expense that had neither', async () => {
      mockGetResponses([{ ...paid, paymentProofUrl: null, paymentNote: null }]);
      const user = userEvent.setup();
      renderPayments();

      const dialog = await openEditDialog(user);

      expect(within(dialog).getByLabelText('Descrição')).toHaveValue('');
      expect(within(dialog).getByText('Selecionar foto')).toBeInTheDocument();
      expect(within(dialog).queryByRole('link', { name: 'Ver foto atual' })).not.toBeInTheDocument();
      expect(within(dialog).queryByRole('checkbox')).not.toBeInTheDocument();
      expect(within(dialog).getByRole('button', { name: 'Salvar' })).toBeDisabled();
    });

    it('closes without sending anything when it is cancelled', async () => {
      const user = userEvent.setup();
      renderPayments();

      const dialog = await openEditDialog(user);
      await user.type(within(dialog).getByLabelText('Descrição'), ' vou desistir');
      await user.click(within(dialog).getByRole('button', { name: 'Cancelar' }));

      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
      expect(axios.post).not.toHaveBeenCalled();
    });

    it('keeps the "Confirmar pagamento" popup independent: each opens its own dialog, blank or filled', async () => {
      mockGetResponses([
        paid,
        { id: 31, description: 'Aluguel', date: '2026-08-11', value: 500, paid: false, userPayerId: CURRENT_USER_ID },
      ]);
      const user = userEvent.setup();
      renderPayments();

      const editDialog = await openEditDialog(user);
      expect(within(editDialog).getByLabelText('Descrição')).toHaveValue('Pix agendado');
      await user.click(within(editDialog).getByRole('button', { name: 'Cancelar' }));
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());

      await user.click(screen.getByRole('button', { name: 'Confirmar pagamento' }));
      const payDialog = await screen.findByRole('dialog');
      expect(within(payDialog).getByText('Anexe uma foto do comprovante ou descreva o pagamento.')).toBeInTheDocument();
      expect(within(payDialog).getByLabelText('Descrição')).toHaveValue('');
      expect(within(payDialog).queryByRole('checkbox')).not.toBeInTheDocument();
      await user.type(within(payDialog).getByLabelText('Descrição'), 'Pago em dinheiro');
      await user.click(within(payDialog).getByRole('button', { name: 'Confirmar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      const [url, body] = vi.mocked(axios.post).mock.calls[0];
      expect(url).toMatch(/\/api\/expenses\/31\/pay$/);
      expect(body).toEqual({ cycles_ago: 0, descricao: 'Pago em dinheiro' });
    });

    it('opening the "Confirmar pagamento" popup first does not leak into the edit popup', async () => {
      mockGetResponses([
        paid,
        { id: 31, description: 'Aluguel', date: '2026-08-11', value: 500, paid: false, userPayerId: CURRENT_USER_ID },
      ]);
      const user = userEvent.setup();
      renderPayments();

      await user.click(await screen.findByRole('button', { name: 'Confirmar pagamento' }));
      const payDialog = await screen.findByRole('dialog');
      await user.type(within(payDialog).getByLabelText('Descrição'), 'rascunho do pagamento');
      await user.click(within(payDialog).getByRole('button', { name: 'Cancelar' }));
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());

      const editDialog = await openEditDialog(user);
      expect(within(editDialog).getByText('Editar comprovante')).toBeInTheDocument();
      expect(within(editDialog).getByLabelText('Descrição')).toHaveValue('Pix agendado');
    });
  });

  describe('salvar', () => {
    it('sends only the description as JSON to .../payment-proof when no photo is involved', async () => {
      const user = userEvent.setup();
      renderPayments();

      const dialog = await openEditDialog(user);
      await user.type(within(dialog).getByLabelText('Descrição'), ' sexta');
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      const [url, body] = vi.mocked(axios.post).mock.calls[0];
      expect(url).toMatch(/\/api\/expenses\/30\/payment-proof$/);
      expect(body).toEqual({ cycles_ago: 0, descricao: 'Pix agendado sexta' });
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    });

    it('sends the new photo as multipart, keeping the description', async () => {
      const user = userEvent.setup();
      renderPayments();

      const dialog = await openEditDialog(user);
      const file = new File(['x'], 'novo.jpg', { type: 'image/jpeg' });
      await user.upload(dialog.querySelector('input[type="file"]') as HTMLInputElement, file);
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
      expect(body).toBeInstanceOf(FormData);
      expect(body.get('comprovante')).toBe(file);
      expect(body.get('cycles_ago')).toBe('0');
      expect(body.get('descricao')).toBe('Pix agendado');
      expect(body.has('remover_comprovante')).toBe(false);
    });

    it('sends remover_comprovante when the current photo is removed', async () => {
      const user = userEvent.setup();
      renderPayments();

      const dialog = await openEditDialog(user);
      await user.click(within(dialog).getByRole('checkbox', { name: 'Remover a foto atual' }));
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      expect(vi.mocked(axios.post).mock.calls[0][1]).toEqual({
        cycles_ago: 0,
        descricao: 'Pix agendado',
        remover_comprovante: true,
      });
    });

    it('attaches a first photo to an expense that was paid with neither photo nor description', async () => {
      mockGetResponses([{ ...paid, paymentProofUrl: null, paymentNote: null }]);
      const user = userEvent.setup();
      renderPayments();

      const dialog = await openEditDialog(user);
      const file = new File(['x'], 'primeira.jpg', { type: 'image/jpeg' });
      await user.upload(dialog.querySelector('input[type="file"]') as HTMLInputElement, file);
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
      expect(body.get('comprovante')).toBe(file);
      expect(body.get('descricao')).toBe('');
    });

    it('edits the cycle on screen (cycles_ago), not always the current one', async () => {
      mockGetResponses([paid], { cycle: { start: '2026-09-01', end: '2026-09-30', status: 'closed' } }, [], 1);
      const user = userEvent.setup();
      renderPayments();

      const dialog = await openEditDialog(user);
      await user.type(within(dialog).getByLabelText('Descrição'), '!');
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      expect((vi.mocked(axios.post).mock.calls[0][1] as { cycles_ago: number }).cycles_ago).toBe(1);
    });

    it('shows "Comprovante atualizado." (not the payment message) and reloads the summary on success', async () => {
      const user = userEvent.setup();
      renderPayments();

      const dialog = await openEditDialog(user);
      await user.type(within(dialog).getByLabelText('Descrição'), '!');
      const summaryGets = () =>
        vi.mocked(axios.get).mock.calls.filter(([u]) => String(u).includes('/expenses/summary')).length;
      const before = summaryGets();
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      expect(await screen.findByText('Comprovante atualizado.')).toBeInTheDocument();
      await waitFor(() => expect(summaryGets()).toBeGreaterThan(before));
      expect(screen.queryByText('Pagamento confirmado.')).not.toBeInTheDocument();
    });

    it('shows the API error in the Snackbar and no success message', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {});
      vi.mocked(axios.post).mockRejectedValue({
        response: { data: { error: 'Esta competência já foi encerrada. Desfaça o pagamento para corrigir o comprovante.' } },
      });
      const user = userEvent.setup();
      renderPayments();

      const dialog = await openEditDialog(user);
      await user.type(within(dialog).getByLabelText('Descrição'), '!');
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      expect(
        await screen.findByText('Esta competência já foi encerrada. Desfaça o pagamento para corrigir o comprovante.')
      ).toBeInTheDocument();
      expect(screen.queryByText('Comprovante atualizado.')).not.toBeInTheDocument();
    });

    it('falls back to the default message when the API gives no text', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {});
      vi.mocked(axios.post).mockRejectedValue(new Error('Network Error'));
      const user = userEvent.setup();
      renderPayments();

      const dialog = await openEditDialog(user);
      await user.type(within(dialog).getByLabelText('Descrição'), '!');
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      expect(await screen.findByText('Falha ao atualizar o comprovante.')).toBeInTheDocument();
    });

    it('disables the button of that expense while the request is in flight', async () => {
      let resolve!: (value: unknown) => void;
      vi.mocked(axios.post).mockReturnValue(new Promise(r => { resolve = r; }));
      const user = userEvent.setup();
      renderPayments();

      const dialog = await openEditDialog(user);
      await user.type(within(dialog).getByLabelText('Descrição'), '!');
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      await waitFor(() => expect(screen.getByRole('button', { name: 'Editar comprovante' })).toBeDisabled());
      resolve({ data: {} });
      await waitFor(() => expect(screen.getByRole('button', { name: 'Editar comprovante' })).toBeEnabled());
    });
  });

  describe('alvo do popup', () => {
    const two: SummaryExpenseFixture[] = [
      { ...paid, id: 32, description: 'Aluguel', paymentNote: 'nota do aluguel' },
      { ...paid, id: 33, description: 'Internet', paymentNote: 'nota da internet' },
    ];
    const cardOf = (title: string) => screen.getByText(title).closest('.MuiCard-root') as HTMLElement;

    it('edits the expense whose button was clicked when there are several', async () => {
      mockGetResponses(two);
      const user = userEvent.setup();
      renderPayments();

      await screen.findByText('Aluguel');
      await user.click(within(cardOf('Internet')).getByRole('button', { name: 'Editar comprovante' }));
      const dialog = await screen.findByRole('dialog');

      expect(within(dialog).getByLabelText('Descrição')).toHaveValue('nota da internet');
      await user.type(within(dialog).getByLabelText('Descrição'), '!');
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      expect(vi.mocked(axios.post).mock.calls[0][0]).toMatch(/\/api\/expenses\/33\/payment-proof$/);
    });

    it('switches to the next expense when another button is clicked after closing the popup', async () => {
      mockGetResponses(two);
      const user = userEvent.setup();
      renderPayments();

      await screen.findByText('Aluguel');
      await user.click(within(cardOf('Aluguel')).getByRole('button', { name: 'Editar comprovante' }));
      let dialog = await screen.findByRole('dialog');
      expect(within(dialog).getByLabelText('Descrição')).toHaveValue('nota do aluguel');
      await user.click(within(dialog).getByRole('button', { name: 'Cancelar' }));
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());

      await user.click(within(cardOf('Internet')).getByRole('button', { name: 'Editar comprovante' }));
      dialog = await screen.findByRole('dialog');
      expect(within(dialog).getByLabelText('Descrição')).toHaveValue('nota da internet');
      await user.type(within(dialog).getByLabelText('Descrição'), '!');
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      expect(vi.mocked(axios.post).mock.calls[0][0]).toMatch(/\/api\/expenses\/33\/payment-proof$/);
    });

    it('reads the target from the current list: after a reload the popup shows the fresh description', async () => {
      const user = userEvent.setup();
      let current = { ...paid, paymentNote: 'versão antiga' };
      vi.mocked(axios.get).mockImplementation((url: string) => {
        if (url.includes('/expenses/summary')) return Promise.resolve({ data: summaryResponse([current]) });
        if (url.includes('/api/me')) return Promise.resolve({ data: { id: CURRENT_USER_ID } });
        if (url.includes('/members')) return Promise.resolve({ data: [] });
        return Promise.reject(new Error(`unexpected GET ${url}`));
      });
      renderPayments();

      let dialog = await openEditDialog(user);
      expect(within(dialog).getByLabelText('Descrição')).toHaveValue('versão antiga');
      current = { ...paid, paymentNote: 'versão nova' };
      await user.type(within(dialog).getByLabelText('Descrição'), '!');
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
      await screen.findByText('Comprovante atualizado.');
      await screen.findByText('Descrição do pagamento: versão nova');
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());

      dialog = await openEditDialog(user);
      expect(within(dialog).getByLabelText('Descrição')).toHaveValue('versão nova');
    });

    it('closes the popup if the expense disappears from the list while it is open', async () => {
      const user = userEvent.setup();
      let list: SummaryExpenseFixture[] = [paid];
      vi.mocked(axios.get).mockImplementation((url: string) => {
        if (url.includes('/expenses/summary')) return Promise.resolve({ data: summaryResponse(list) });
        if (url.includes('/api/me')) return Promise.resolve({ data: { id: CURRENT_USER_ID } });
        if (url.includes('/members')) return Promise.resolve({ data: [] });
        return Promise.reject(new Error(`unexpected GET ${url}`));
      });
      renderPayments();

      await openEditDialog(user);
      list = [];
      // `getByLabelText`: com o diálogo aberto o MUI esconde a página de trás da
      // árvore de acessibilidade, e `getByRole` não a enxergaria.
      await user.click(screen.getByLabelText('Ciclo anterior'));

      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    });
  });

  describe('texto puro', () => {
    it('shows a description with markup as literal text in the popup, never as HTML', async () => {
      mockGetResponses([{ ...paid, paymentNote: '<img src=x onerror="alert(1)"><b>negrito</b>' }]);
      const user = userEvent.setup();
      renderPayments();

      const dialog = await openEditDialog(user);

      expect(within(dialog).getByLabelText('Descrição')).toHaveValue('<img src=x onerror="alert(1)"><b>negrito</b>');
      expect(dialog.querySelector('img')).toBeNull();
      expect(dialog.querySelector('b')).toBeNull();
    });
  });
});

describe('Payments - ordem e data das despesas', () => {
  // Chega na ordem de hoje do backend (pendentes primeiro, data crescente): é a ordem
  // que a tela deve DESFAZER. Specify §5.1 de organizacao-despesas-pagamentos.
  const doBackend: SummaryExpenseFixture[] = [
    {
      id: 25,
      description: 'Sofá 3/6',
      value: 300,
      date: '2026-09-20',
      payerName: 'Isac',
      paid: false,
      expenseType: 'IN_INSTALLMENTS',
      installmentNumber: 3,
      installmentsTotal: 6,
    },
    { id: 31, description: 'Mercado', value: 150, date: '2026-10-03', payerName: 'João', paid: false },
    { id: 30, description: 'Luz', value: 90, date: '2026-09-12', payerName: 'Isac', paid: true },
    { id: 28, description: 'Internet', value: 120, date: '2026-10-03', payerName: 'Isac', paid: true, isFixed: true },
  ];
  const overrides: SummaryOverrides = { cycle: { start: '2026-09-06', end: '2026-10-05' } };
  const NAMES = /^(Sofá 3\/6|Mercado|Luz|Internet)$/;

  const renderPayments = () =>
    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );

  // Descrições na ordem em que os cards aparecem no DOM.
  const shownOrder = () => screen.getAllByText(NAMES).map(title => title.textContent);
  const cardOf = (title: string) => screen.getByText(title).closest('[class*="MuiCard-root"]') as HTMLElement;

  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
  });

  it('ordena os cards por data da ocorrência decrescente, desempata pelo id e não prioriza pendentes', async () => {
    mockGetResponses(doBackend, overrides);
    renderPayments();

    await screen.findByText('Mercado');

    expect(shownOrder()).toEqual(['Mercado', 'Internet', 'Sofá 3/6', 'Luz']);
    // Pendente e paga intercaladas: o status não decide a posição.
    expect(screen.getAllByText(/^(Paga|Pendente)$/).map(chip => chip.textContent)).toEqual([
      'Pendente',
      'Paga',
      'Pendente',
      'Paga',
    ]);
  });

  it('mostra a mesma ordem quando o resumo chega em ordem crescente, como um ciclo selado', async () => {
    mockGetResponses([...doBackend].sort((a, b) => a.date.localeCompare(b.date) || a.id - b.id), overrides);
    renderPayments();

    await screen.findByText('Mercado');

    expect(shownOrder()).toEqual(['Mercado', 'Internet', 'Sofá 3/6', 'Luz']);
  });

  it('não altera a lista recebida da API (o resumo é estado do hook)', async () => {
    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/expenses/summary')) {
        const data = summaryResponse(doBackend, overrides);
        Object.freeze(data.expenses);
        return Promise.resolve({ data });
      }
      if (url.includes('/api/me')) return Promise.resolve({ data: { id: CURRENT_USER_ID } });
      if (url.includes('/members')) return Promise.resolve({ data: [] });
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });
    renderPayments();

    await screen.findByText('Mercado');

    expect(shownOrder()).toEqual(['Mercado', 'Internet', 'Sofá 3/6', 'Luz']);
  });

  it('mostra a linha "Data:" em cada card', async () => {
    mockGetResponses(doBackend, overrides);
    renderPayments();

    await screen.findByText('Mercado');

    expect(within(cardOf('Mercado')).getByText(/^Data:\s*03\b.*out/i)).toBeInTheDocument();
    expect(within(cardOf('Sofá 3/6')).getByText(/^Data:\s*20\b.*set/i)).toBeInTheDocument();
    expect(within(cardOf('Luz')).getByText(/^Data:\s*12\b.*set/i)).toBeInTheDocument();
  });

  it('renderiza duas parcelas da mesma despesa sem aviso de chave duplicada', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
    const parcela = (installmentNumber: number): SummaryExpenseFixture => ({
      id: 40,
      description: 'Geladeira',
      value: 200,
      date: '2026-10-03',
      payerName: 'Isac',
      expenseType: 'IN_INSTALLMENTS',
      installmentNumber,
      installmentsTotal: 6,
    });
    mockGetResponses([parcela(1), parcela(2)], overrides);
    renderPayments();

    await screen.findAllByText('Geladeira');

    expect(screen.getAllByText('Geladeira')).toHaveLength(2);
    expect(consoleError.mock.calls.flat().join(' ')).not.toMatch(/same key/i);
    consoleError.mockRestore();
  });
});

describe('Payments - filtros da lista de despesas', () => {
  const isac = { id: CURRENT_USER_ID, name: 'Isac', avatarUrl: null };
  const joao = { id: 501, name: 'João', avatarUrl: null };
  const maria = { id: 502, name: 'Maria', avatarUrl: null };

  // Competência atual — Isac (o usuário logado) é credor de Aluguel e Internet; João, de Mercado:
  //   Aluguel   fixa      credor Isac  Isac, João, Maria  paga
  //   Mercado   variável  credor João  João, Maria        pendente
  //   Internet  variável  credor Isac  Isac, João         pendente
  const currentCycle: SummaryExpenseFixture[] = [
    {
      id: 9,
      description: 'Aluguel',
      value: 1200,
      date: '2026-08-01',
      payerName: 'Isac',
      userPayerId: CURRENT_USER_ID,
      isFixed: true,
      paid: true,
      paymentNote: 'Pix agendado',
      participants: ['Isac', 'João', 'Maria'],
      participantDetails: [isac, joao, maria],
    },
    {
      id: 10,
      description: 'Mercado',
      value: 150,
      date: '2026-08-05',
      payerName: 'João',
      userPayerId: 501,
      isFixed: false,
      participants: ['João', 'Maria'],
      participantDetails: [joao, maria],
    },
    {
      id: 11,
      description: 'Internet',
      value: 100,
      date: '2026-08-10',
      payerName: 'Isac',
      userPayerId: CURRENT_USER_ID,
      isFixed: false,
      participants: ['Isac', 'João'],
      participantDetails: [isac, joao],
    },
  ];

  // Competência anterior — Maria não aparece em nenhuma despesa.
  const previousCycle: SummaryExpenseFixture[] = [
    {
      id: 20,
      description: 'Condomínio',
      value: 400,
      date: '2026-07-05',
      payerName: 'Isac',
      userPayerId: CURRENT_USER_ID,
      isFixed: false,
      participants: ['Isac', 'João'],
      participantDetails: [isac, joao],
    },
    {
      id: 21,
      description: 'Seguro',
      value: 90,
      date: '2026-07-12',
      payerName: 'João',
      userPayerId: 501,
      isFixed: false,
      participants: ['João'],
      participantDetails: [joao],
    },
  ];

  const balances = [
    { user_id: CURRENT_USER_ID, name: 'Isac', balance: 100 },
    { user_id: 501, name: 'João', balance: -60 },
    { user_id: 502, name: 'Maria', balance: -40 },
  ];
  const settlements = [
    { from_user_id: 501, to_user_id: CURRENT_USER_ID, amount: 60 },
    { from_user_id: 502, to_user_id: CURRENT_USER_ID, amount: 40 },
  ];

  // `shown()` devolve os que aparecem na tela na ordem desta lista, que é a do DOM (data
  // decrescente): competência atual Internet 10/08, Mercado 05/08, Aluguel 01/08; anterior Seguro 12/07, Condomínio 05/07.
  const allDescriptions = ['Internet', 'Mercado', 'Aluguel', 'Seguro', 'Condomínio'];
  const shown = () => allDescriptions.filter(description => screen.queryByText(description) !== null);

  type User = ReturnType<typeof userEvent.setup>;

  const renderScreen = async (waitForText = 'Aluguel') => {
    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );
    await screen.findByText(waitForText);
  };

  // Abre o select múltiplo, escolhe as opções e fecha a lista (o Autocomplete mantém a lista aberta).
  const pick = async (user: User, field: 'Credor' | 'Devedores', ...names: string[]) => {
    await user.click(screen.getByRole('combobox', { name: field }));
    for (const name of names) {
      await user.click(await screen.findByRole('option', { name }));
    }
    await user.keyboard('{Escape}');
  };

  const group = (name: 'Tipo' | 'Status') => within(screen.getByRole('group', { name }));
  const cardOf = (title: string) => screen.getByText(title).closest('[class*="MuiCard-root"]') as HTMLElement;
  const selectedChips = (field: 'Credor' | 'Devedores') =>
    screen.getByRole('combobox', { name: field }).closest('.MuiAutocomplete-root')!.querySelectorAll('.MuiAutocomplete-tag');
  const getCalls = (part: string) =>
    vi.mocked(axios.get).mock.calls.filter(call => String(call[0]).includes(part)).length;

  // Devolve a competência anterior quando o hook pede `cycles_ago=1`.
  const mockTwoCycles = () => {
    vi.mocked(axios.get).mockImplementation((url: string, config?: { params?: { cycles_ago?: number } }) => {
      if (url.includes('/expenses/summary')) {
        const list = config?.params?.cycles_ago === 1 ? previousCycle : currentCycle;
        return Promise.resolve({ data: summaryResponse(list) });
      }
      if (url.includes('/api/me')) return Promise.resolve({ data: { id: CURRENT_USER_ID } });
      if (url.includes('/members')) return Promise.resolve({ data: [] });
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });
  };

  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    mockGetResponses(currentCycle, { balances, settlements });
  });

  it('mostra busca, Credor, Devedores, Tipo e Status, com os mesmos rótulos de Despesas', async () => {
    await renderScreen();

    expect(screen.getByLabelText('Buscar despesa')).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Credor' })).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Devedores' })).toBeInTheDocument();
    expect(group('Tipo').getByRole('button', { name: 'Fixas' })).toBeInTheDocument();
    expect(group('Status').getByRole('button', { name: 'Pendentes' })).toBeInTheDocument();
    expect(shown()).toEqual(['Internet', 'Mercado', 'Aluguel']);
  });

  it('filtra por Credor, e dois credores valem como OU', async () => {
    const user = userEvent.setup();
    await renderScreen();

    await pick(user, 'Credor', 'João');
    expect(shown()).toEqual(['Mercado']);

    await pick(user, 'Credor', 'Isac');
    expect(shown()).toEqual(['Internet', 'Mercado', 'Aluguel']);
  });

  it('filtra por Devedores, contando o credor como devedor da própria despesa', async () => {
    const user = userEvent.setup();
    await renderScreen();

    await pick(user, 'Devedores', 'Maria');
    expect(shown()).toEqual(['Mercado', 'Aluguel']);

    await pick(user, 'Devedores', 'João');
    expect(shown()).toEqual(['Internet', 'Mercado', 'Aluguel']);
  });

  it('filtra por Status: Pagas, Pendentes e de volta a Todas', async () => {
    const user = userEvent.setup();
    await renderScreen();

    await user.click(group('Status').getByRole('button', { name: 'Pagas' }));
    expect(shown()).toEqual(['Aluguel']);

    await user.click(group('Status').getByRole('button', { name: 'Pendentes' }));
    expect(shown()).toEqual(['Internet', 'Mercado']);

    await user.click(group('Status').getByRole('button', { name: 'Todas' }));
    expect(shown()).toEqual(['Internet', 'Mercado', 'Aluguel']);
  });

  it('ordena antes de filtrar: com um filtro ativo a lista segue em data decrescente, mesmo chegando crescente', async () => {
    const user = userEvent.setup();
    await renderScreen();
    // `currentCycle` chega da API em data crescente (Aluguel, Mercado, Internet).
    const domOrder = () => screen.getAllByText(/^(Internet|Mercado|Aluguel)$/).map(title => title.textContent);

    await user.click(group('Status').getByRole('button', { name: 'Pendentes' }));

    expect(domOrder()).toEqual(['Internet', 'Mercado']);
  });

  it('combina Credor, Status, Tipo e busca: só passa quem atende a todos', async () => {
    const user = userEvent.setup();
    await renderScreen();

    await pick(user, 'Credor', 'Isac');
    expect(shown()).toEqual(['Internet', 'Aluguel']);

    await user.click(group('Status').getByRole('button', { name: 'Pendentes' }));
    expect(shown()).toEqual(['Internet']);

    await user.click(group('Tipo').getByRole('button', { name: 'Fixas' }));
    expect(shown()).toEqual([]);
    expect(screen.getByText('Nenhuma despesa encontrada para esse filtro.')).toBeInTheDocument();

    await user.click(group('Tipo').getByRole('button', { name: 'Todas' }));
    await user.type(screen.getByLabelText('Buscar despesa'), 'net');
    expect(shown()).toEqual(['Internet']);
  });

  it('filtra Devedores por nome numa competência de ciclo selado, sem participantDetails', async () => {
    const user = userEvent.setup();
    mockGetResponses(currentCycle.map(exp => ({ ...exp, participantDetails: undefined })));
    await renderScreen();

    await pick(user, 'Devedores', 'Maria');

    expect(shown()).toEqual(['Mercado', 'Aluguel']);
  });

  it('filtrar não faz nenhuma chamada nova à API', async () => {
    const user = userEvent.setup();
    await renderScreen();
    const snapshot = () => ({
      focus: getCalls('/expenses/focus-cycle'),
      summary: getCalls('/expenses/summary'),
      members: getCalls('/members'),
      me: getCalls('/api/me'),
    });
    const before = snapshot();
    expect(before.summary).toBe(1);

    await pick(user, 'Credor', 'João');
    await pick(user, 'Devedores', 'Maria');
    await user.click(group('Status').getByRole('button', { name: 'Pendentes' }));
    await user.type(screen.getByLabelText('Buscar despesa'), 'mer');

    expect(snapshot()).toEqual(before);
  });

  it('ignora um devedor que não existe na competência navegada: some do campo e não restringe a lista', async () => {
    const user = userEvent.setup();
    mockTwoCycles();
    await renderScreen();

    await pick(user, 'Devedores', 'Maria');
    expect(shown()).toEqual(['Mercado', 'Aluguel']);
    expect(selectedChips('Devedores')).toHaveLength(1);

    await user.click(screen.getByRole('button', { name: 'Ciclo anterior' }));
    await screen.findByText('Condomínio');

    expect(shown()).toEqual(['Seguro', 'Condomínio']);
    expect(selectedChips('Devedores')).toHaveLength(0);
  });

  it('mantém o filtro de Credor ao navegar para uma competência em que a pessoa também aparece', async () => {
    const user = userEvent.setup();
    mockTwoCycles();
    await renderScreen();

    await pick(user, 'Credor', 'Isac');
    expect(shown()).toEqual(['Internet', 'Aluguel']);

    await user.click(screen.getByRole('button', { name: 'Ciclo anterior' }));
    await screen.findByText('Condomínio');

    expect(shown()).toEqual(['Condomínio']);
  });

  it('mostra a barra mesmo numa competência sem despesas, com o texto próprio de competência vazia', async () => {
    mockGetResponses([]);
    await renderScreen('Nenhuma despesa nesta competência.');

    expect(screen.getByRole('combobox', { name: 'Credor' })).toBeInTheDocument();
    expect(screen.queryByText('Nenhuma despesa encontrada para esse filtro.')).not.toBeInTheDocument();
  });

  it('não filtra os acertos, os saldos nem as ações: "Valores a pagar" mostra o mesmo com filtro ativo', async () => {
    const user = userEvent.setup();
    await renderScreen();
    const acertos = () => screen.getAllByText(/deve pagar/).map(el => el.textContent);
    const semFiltro = acertos();
    expect(semFiltro).toEqual(['João deve pagar R$ 60,00 a Isac', 'Maria deve pagar R$ 40,00 a Isac']);

    await pick(user, 'Credor', 'João');
    await user.click(group('Status').getByRole('button', { name: 'Pendentes' }));

    expect(shown()).toEqual(['Mercado']);
    expect(acertos()).toEqual(semFiltro);
  });

  it('abre "Editar comprovante" com um filtro ativo, já preenchido com a descrição atual', async () => {
    const user = userEvent.setup();
    await renderScreen();

    await pick(user, 'Credor', 'Isac');
    await user.click(group('Status').getByRole('button', { name: 'Pagas' }));
    expect(shown()).toEqual(['Aluguel']);

    await user.click(within(cardOf('Aluguel')).getByRole('button', { name: 'Editar comprovante' }));
    const dialog = await screen.findByRole('dialog');

    expect(within(dialog).getByLabelText('Descrição')).toHaveValue('Pix agendado');
  });

  it('desfaz o pagamento com o filtro "Pagas" ativo: a despesa sai da lista filtrada sem erro', async () => {
    const user = userEvent.setup();
    let unpaid = false;
    vi.mocked(axios.post).mockImplementation(() => {
      unpaid = true;
      return Promise.resolve({ data: {} });
    });
    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/expenses/summary')) {
        const list = currentCycle.map(exp => (exp.id === 9 && unpaid ? { ...exp, paid: false } : exp));
        return Promise.resolve({ data: summaryResponse(list, { balances, settlements }) });
      }
      if (url.includes('/api/me')) return Promise.resolve({ data: { id: CURRENT_USER_ID } });
      if (url.includes('/members')) return Promise.resolve({ data: [] });
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });
    await renderScreen();

    await user.click(group('Status').getByRole('button', { name: 'Pagas' }));
    expect(shown()).toEqual(['Aluguel']);

    await user.click(within(cardOf('Aluguel')).getByRole('button', { name: 'Desfazer pagamento' }));

    expect(await screen.findByText('Nenhuma despesa encontrada para esse filtro.')).toBeInTheDocument();
    expect(screen.queryByText('Aluguel')).not.toBeInTheDocument();
    expect(await screen.findByText('Pagamento desfeito.')).toBeInTheDocument();
  });
});

describe('Payments - abas de valores a pagar e pagos', () => {
  const CONFIRMED_AT = '2026-08-20T10:00:00-03:00';
  const PROOF_URL = 'https://api.exemplo.com/api/proofs/9?signature=abc';
  const MARIA = 999;
  const JOAO = 501;

  const balances = [
    { user_id: CURRENT_USER_ID, name: 'Isac', balance: -30 },
    { user_id: MARIA, name: 'Maria', balance: 50 },
    { user_id: JOAO, name: 'João', balance: -20 },
  ];
  const members: MemberFixture[] = [
    { id: MARIA, name: 'Maria', email: 'maria@example.com', pix: 'maria@pix.com' },
    { id: JOAO, name: 'João', email: 'joao@example.com', pix: 'joao@pix.com' },
  ];
  const closed = { status: 'closed' };

  // Ordem do servidor propositalmente "errada": a tela reordena (logado primeiro).
  //   a pagar: João → Maria 20 (terceiros) e Isac → Maria 50 (saída do logado)
  //   pagos:   João → Isac 30 (a receber do logado, confirmado)
  const toPayMine = { from_user_id: CURRENT_USER_ID, to_user_id: MARIA, amount: 50 };
  const toPayOthers = { from_user_id: JOAO, to_user_id: MARIA, amount: 20 };
  const paidToMe = {
    from_user_id: JOAO,
    to_user_id: CURRENT_USER_ID,
    amount: 30,
    confirmedAt: CONFIRMED_AT,
    confirmedProofUrl: PROOF_URL,
  };
  const paidByMe = {
    from_user_id: CURRENT_USER_ID,
    to_user_id: JOAO,
    amount: 25,
    confirmedAt: CONFIRMED_AT,
    confirmedProofUrl: PROOF_URL,
  };

  type User = ReturnType<typeof userEvent.setup>;

  const renderScreen = () =>
    render(
      <MemoryRouter>
        <Payments />
      </MemoryRouter>
    );

  const tabNamed = (name: RegExp) => screen.getByRole('tab', { name });
  const shownSettlements = () =>
    screen
      .queryAllByText(/deve pagar/)
      .map(el => (el.closest('p')?.textContent ?? '').replace(/\s+/g, ' ').trim());

  // Um resumo por `cycles_ago`; o que não estiver no mapa devolve o ciclo corrente.
  const mockCycles = (byCyclesAgo: Record<number, SummaryOverrides>, expensesList: SummaryExpenseFixture[] = []) => {
    vi.mocked(axios.get).mockImplementation((url: string, config?: { params?: { cycles_ago?: number } }) => {
      if (url.includes('/expenses/summary')) {
        const ago = config?.params?.cycles_ago ?? 0;
        return Promise.resolve({ data: summaryResponse(expensesList, byCyclesAgo[ago] ?? byCyclesAgo[0]) });
      }
      if (url.includes('/pix/generate')) {
        return Promise.resolve({ data: { qrcode: 'data:image/png;base64,abc', copiacola: '00020126...6304ABCD' } });
      }
      if (url.includes('/api/me')) return Promise.resolve({ data: { id: CURRENT_USER_ID } });
      if (url.includes('/members')) return Promise.resolve({ data: members });
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });
  };

  // Envia um comprovante pelo popup do devedor e espera a confirmação do envio.
  const sendProof = async (user: User, buttonName: 'Enviar comprovante' | 'Reenviar comprovante') => {
    await user.click(await screen.findByRole('button', { name: buttonName }));
    const dialog = await screen.findByRole('dialog');
    await user.upload(
      dialog.querySelector('input[type="file"]') as HTMLInputElement,
      new File(['foto'], 'pix.png', { type: 'image/png' })
    );
    await user.click(within(dialog).getByRole('button', { name: 'Confirmar' }));
    expect(await screen.findByText('Comprovante enviado.')).toBeInTheDocument();
  };

  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
  });

  it('abre em "Valores a pagar", com contadores e a saída do logado antes dos demais', async () => {
    mockCycles({ 0: { balances, settlements: [toPayOthers, paidToMe, toPayMine], cycle: closed } });
    renderScreen();

    await screen.findByRole('tab', { name: /Valores a pagar/ });

    expect(tabNamed(/Valores a pagar/)).toHaveAttribute('aria-selected', 'true');
    expect(tabNamed(/Valores a pagar/)).toHaveTextContent('Valores a pagar (2)');
    expect(tabNamed(/Valores pagos/)).toHaveTextContent('Valores pagos (1)');
    expect(shownSettlements()).toEqual(['Isac deve pagar R$ 50,00 a Maria', 'João deve pagar R$ 20,00 a Maria']);
  });

  it('"Valores pagos" mostra o acerto confirmado, marcado como recebido pelo logado', async () => {
    const user = userEvent.setup();
    mockCycles({ 0: { balances, settlements: [toPayMine, paidToMe], cycle: closed } });
    renderScreen();

    await user.click(await screen.findByRole('tab', { name: /Valores pagos/ }));

    expect(shownSettlements()).toEqual(['João deve pagar R$ 30,00 a Isac']);
    expect(screen.getByText('Pagou a você')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Comprovante enviado' })).toHaveAttribute('href', PROOF_URL);
  });

  it('separa os acertos pelos dados de confirmação, e não pelo status Paga/Pendente da despesa', async () => {
    const user = userEvent.setup();
    const expensesList: SummaryExpenseFixture[] = [
      { id: 1, description: 'Mercado', date: '2026-08-10', value: 100, paid: true, userPayerId: MARIA },
      { id: 2, description: 'Internet', date: '2026-08-05', value: 80, paid: false, userPayerId: MARIA },
    ];
    // A despesa paga tem acerto sem confirmação; a pendente tem acerto confirmado.
    mockCycles({ 0: { balances, settlements: [toPayMine, paidToMe], cycle: closed } }, expensesList);
    renderScreen();

    await screen.findByText('Mercado');
    expect(shownSettlements()).toEqual(['Isac deve pagar R$ 50,00 a Maria']);

    await user.click(tabNamed(/Valores pagos/));
    expect(shownSettlements()).toEqual(['João deve pagar R$ 30,00 a Isac']);
  });

  it('um acerto confirmado sem URL de comprovante também é "pago"', async () => {
    const user = userEvent.setup();
    mockCycles({
      0: { balances, settlements: [{ ...paidByMe, confirmedProofUrl: null }], cycle: closed },
    });
    renderScreen();

    await user.click(await screen.findByRole('tab', { name: /Valores pagos/ }));

    expect(shownSettlements()).toEqual(['Isac deve pagar R$ 25,00 a João']);
    expect(screen.getByText('Comprovante enviado')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Comprovante enviado' })).not.toBeInTheDocument();
  });

  it('mantém a aba escolhida ao navegar para outro ciclo', async () => {
    const user = userEvent.setup();
    mockCycles({
      0: { balances, settlements: [toPayMine, paidToMe], cycle: closed },
      1: {
        balances,
        settlements: [{ from_user_id: MARIA, to_user_id: JOAO, amount: 10 }, paidByMe],
        cycle: { start: '2026-07-01', end: '2026-07-31', status: 'closed' },
      },
    });
    renderScreen();

    await user.click(await screen.findByRole('tab', { name: /Valores pagos/ }));
    await user.click(screen.getByRole('button', { name: 'Ciclo anterior' }));

    await waitFor(() => expect(shownSettlements()).toEqual(['Isac deve pagar R$ 25,00 a João']));
    expect(tabNamed(/Valores pagos/)).toHaveAttribute('aria-selected', 'true');
    expect(tabNamed(/Valores a pagar/)).toHaveTextContent('Valores a pagar (1)');
  });

  it('mantém a aba escolhida depois de enviar um comprovante e recarregar o resumo', async () => {
    const user = userEvent.setup();
    let reloads = 0;
    mockCycles({ 0: { balances, settlements: [paidByMe], cycle: closed } });
    const baseGet = vi.mocked(axios.get).getMockImplementation()!;
    vi.mocked(axios.get).mockImplementation(((url: string, config?: unknown) => {
      if (url.includes('/expenses/summary')) reloads += 1;
      return baseGet(url, config as never);
    }) as never);
    renderScreen();

    await user.click(await screen.findByRole('tab', { name: /Valores pagos/ }));
    const before = reloads;
    await sendProof(user, 'Reenviar comprovante');

    await waitFor(() => expect(reloads).toBeGreaterThan(before));
    await waitFor(() => expect(tabNamed(/Valores pagos/)).toHaveAttribute('aria-selected', 'true'));
    expect(shownSettlements()).toEqual(['Isac deve pagar R$ 25,00 a João']);
  });

  it('o acerto muda de "Valores a pagar" para "Valores pagos" depois do comprovante enviado', async () => {
    const user = userEvent.setup();
    let confirmed = false;
    vi.mocked(axios.post).mockImplementation(() => {
      confirmed = true;
      return Promise.resolve({ data: {} });
    });
    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/expenses/summary')) {
        const settlement = confirmed ? { ...toPayMine, confirmedAt: CONFIRMED_AT, confirmedProofUrl: PROOF_URL } : toPayMine;
        return Promise.resolve({ data: summaryResponse([], { balances, settlements: [settlement], cycle: closed }) });
      }
      if (url.includes('/api/me')) return Promise.resolve({ data: { id: CURRENT_USER_ID } });
      if (url.includes('/members')) return Promise.resolve({ data: members });
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });
    renderScreen();

    await screen.findByRole('tab', { name: /Valores a pagar/ });
    expect(tabNamed(/Valores a pagar/)).toHaveTextContent('Valores a pagar (1)');
    expect(tabNamed(/Valores pagos/)).toHaveTextContent('Valores pagos (0)');

    await sendProof(user, 'Enviar comprovante');

    await waitFor(() => expect(tabNamed(/Valores pagos/)).toHaveTextContent('Valores pagos (1)'));
    expect(tabNamed(/Valores a pagar/)).toHaveTextContent('Valores a pagar (0)');
    expect(screen.getByText('Nenhum valor a pagar neste ciclo.')).toBeInTheDocument();

    await user.click(tabNamed(/Valores pagos/));
    expect(shownSettlements()).toEqual(['Isac deve pagar R$ 50,00 a Maria']);
    expect(screen.getByRole('button', { name: 'Reenviar comprovante' })).toBeInTheDocument();
  });

  it('o Pix e o envio de comprovante fazem os mesmos pedidos de antes', async () => {
    const user = userEvent.setup();
    mockCycles(
      { 0: { balances, settlements: [toPayMine], cycle: closed }, 1: { balances, settlements: [toPayMine], cycle: closed } }
    );
    renderScreen();

    await user.click(await screen.findByRole('button', { name: 'Pagar com Pix' }));
    expect(await screen.findByRole('img', { name: /QR Code Pix para pagar Maria/ })).toBeInTheDocument();
    const [, config] = vi.mocked(axios.get).mock.calls.find(call => String(call[0]).includes('/pix/generate'))!;
    expect((config as { params?: Record<string, unknown> }).params).toMatchObject({
      email: 'maria@example.com',
      valor: '50.00',
    });
    await user.keyboard('{Escape}');

    await sendProof(user, 'Enviar comprovante');
    const [url, body] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/groups/1/settlements/confirm');
    expect((body as FormData).get('to_user_id')).toBe(String(MARIA));
    expect((body as FormData).get('cycles_ago')).toBe('0');
  });

  it('reenviar da aba "Valores pagos" de um ciclo anterior manda o cycles_ago do ciclo na tela', async () => {
    const user = userEvent.setup();
    mockCycles({
      0: { balances, settlements: [toPayMine], cycle: closed },
      1: { balances, settlements: [paidByMe], cycle: { start: '2026-07-01', end: '2026-07-31', status: 'closed' } },
    });
    renderScreen();

    await user.click(await screen.findByRole('tab', { name: /Valores pagos/ }));
    await user.click(screen.getByRole('button', { name: 'Ciclo anterior' }));
    await waitFor(() => expect(shownSettlements()).toEqual(['Isac deve pagar R$ 25,00 a João']));

    await sendProof(user, 'Reenviar comprovante');

    const [url, body] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/groups/1/settlements/confirm');
    expect((body as FormData).get('to_user_id')).toBe(String(JOAO));
    expect((body as FormData).get('cycles_ago')).toBe('1');
  });

  it('o Pix também sai da aba "Valores pagos", para o acerto do logado já confirmado', async () => {
    const user = userEvent.setup();
    mockCycles({ 0: { balances, settlements: [paidByMe], cycle: closed } });
    renderScreen();

    await user.click(await screen.findByRole('tab', { name: /Valores pagos/ }));
    await user.click(screen.getByRole('button', { name: 'Pagar com Pix' }));

    expect(await screen.findByRole('img', { name: /QR Code Pix para pagar João/ })).toBeInTheDocument();
    const [, config] = vi.mocked(axios.get).mock.calls.find(call => String(call[0]).includes('/pix/generate'))!;
    expect((config as { params?: Record<string, unknown> }).params).toMatchObject({
      email: 'joao@example.com',
      valor: '25.00',
    });
  });

  it('um ciclo selado mostra as duas abas, sem botões de ação', async () => {
    const user = userEvent.setup();
    mockCycles({
      0: {
        balances,
        settlements: [toPayMine, paidByMe],
        cycle: { status: 'closed', settled: true },
      },
    });
    renderScreen();

    await screen.findByRole('tab', { name: /Valores a pagar/ });
    expect(tabNamed(/Valores a pagar/)).toHaveTextContent('(1)');
    expect(tabNamed(/Valores pagos/)).toHaveTextContent('(1)');
    expect(shownSettlements()).toEqual(['Isac deve pagar R$ 50,00 a Maria']);
    expect(screen.getByText('Ciclo encerrado.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Pagar com Pix' })).not.toBeInTheDocument();

    await user.click(tabNamed(/Valores pagos/));
    expect(shownSettlements()).toEqual(['Isac deve pagar R$ 25,00 a João']);
    expect(screen.queryByRole('button', { name: 'Reenviar comprovante' })).not.toBeInTheDocument();
  });

  it('snapshot antigo com settlements nulo mostra a mensagem de ninguém dever, sem erro', async () => {
    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/expenses/summary')) {
        return Promise.resolve({ data: { ...summaryResponse([], { balances }), settlements: null } });
      }
      if (url.includes('/api/me')) return Promise.resolve({ data: { id: CURRENT_USER_ID } });
      if (url.includes('/members')) return Promise.resolve({ data: members });
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });
    renderScreen();

    expect(await screen.findByText('Ninguém deve nada a ninguém neste ciclo.')).toBeInTheDocument();
    expect(tabNamed(/Valores a pagar/)).toHaveTextContent('Valores a pagar (0)');
    expect(tabNamed(/Valores pagos/)).toHaveTextContent('Valores pagos (0)');
  });

  it('com um filtro de despesas ativo, as abas e os contadores continuam os da competência inteira', async () => {
    const user = userEvent.setup();
    const expensesList: SummaryExpenseFixture[] = [
      {
        id: 1,
        description: 'Mercado',
        date: '2026-08-10',
        value: 100,
        payerName: 'Maria',
        userPayerId: MARIA,
        participantDetails: [{ id: MARIA, name: 'Maria', avatarUrl: null }],
      },
      {
        id: 2,
        description: 'Internet',
        date: '2026-08-05',
        value: 80,
        payerName: 'João',
        userPayerId: JOAO,
        participantDetails: [{ id: JOAO, name: 'João', avatarUrl: null }],
      },
    ];
    mockCycles({ 0: { balances, settlements: [toPayMine, toPayOthers, paidToMe], cycle: closed } }, expensesList);
    renderScreen();

    await screen.findByText('Mercado');
    await user.click(within(screen.getByRole('group', { name: 'Status' })).getByRole('button', { name: 'Pagas' }));
    expect(screen.getByText('Nenhuma despesa encontrada para esse filtro.')).toBeInTheDocument();

    expect(tabNamed(/Valores a pagar/)).toHaveTextContent('Valores a pagar (2)');
    expect(tabNamed(/Valores pagos/)).toHaveTextContent('Valores pagos (1)');
    expect(shownSettlements()).toEqual(['Isac deve pagar R$ 50,00 a Maria', 'João deve pagar R$ 20,00 a Maria']);
  });
});
