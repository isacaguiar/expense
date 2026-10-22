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
