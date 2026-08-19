import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ExpenseManager from './ExpenseManager';

vi.mock('axios');

type ExpensePayload = {
  expense_type: string;
  installments: number;
  payers: number[];
  quotas: { number: number; date_expected: string; paid: boolean; value_quota: number }[];
};

type StopRecurrencePayload = { year: number; month: number };

function lastPostCall<T>(): [string, T] {
  const calls = vi.mocked(axios.post).mock.calls;
  return calls[calls.length - 1] as unknown as [string, T];
}

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => vi.fn(),
    useParams: () => ({ id: '1' }),
  };
});

const members = [
  { id: 1, name: 'Isac' },
  { id: 2, name: 'João' },
];

function mockGetResponses(expensesList: unknown[] = []) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/members')) {
      return Promise.resolve({ data: members });
    }
    if (url.includes('/me')) {
      return Promise.resolve({ data: { id: 1 } });
    }
    if (url.includes('/expenses')) {
      return Promise.resolve({ data: expensesList });
    }
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

async function openNewExpenseModal() {
  render(
    <MemoryRouter>
      <ExpenseManager />
    </MemoryRouter>
  );

  await waitFor(() => expect(axios.get).toHaveBeenCalled());

  await userEvent.click(screen.getByRole('button', { name: /nova despesa/i }));
  await screen.findByText('Cadastrar nova despesa');
}

async function selectExpenseType(label: string) {
  await userEvent.click(screen.getByLabelText('Tipo de despesa'));
  const listbox = await screen.findByRole('listbox');
  await userEvent.click(within(listbox).getByText(label));
}

describe('ExpenseManager - Nova Despesa', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: { expense_id: 1 } });
    mockGetResponses();
  });

  it('creates an "à vista" expense with a single paid quota (regression)', async () => {
    await openNewExpenseModal();

    await userEvent.type(screen.getByLabelText('Descrição'), 'Mercado');
    await userEvent.type(screen.getByLabelText('Valor'), '150,00');

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [, payload] = lastPostCall<ExpensePayload>();
    expect(payload).toMatchObject({
      expense_type: 'IN_CASH',
      installments: 1,
      payers: [1, 2],
    });
    expect(payload.quotas).toEqual([
      { number: 1, date_expected: expect.any(String), paid: true, value_quota: 150 },
    ]);
  });

  it('creates an "parcelada" expense with N quotas, rounding absorbed in the last one', async () => {
    await openNewExpenseModal();

    await userEvent.type(screen.getByLabelText('Descrição'), 'Sofá');
    await userEvent.type(screen.getByLabelText('Valor'), '100,00');
    await selectExpenseType('Parcelada');

    const installmentsField = await screen.findByLabelText('Quantidade de parcelas');
    await userEvent.type(installmentsField, '3');

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [, payload] = lastPostCall<ExpensePayload>();
    expect(payload.expense_type).toBe('IN_INSTALLMENTS');
    expect(payload.installments).toBe(3);
    expect(payload.quotas).toHaveLength(3);
    expect(payload.quotas.every(q => q.paid === false)).toBe(true);
    const total = payload.quotas.reduce((sum, q) => sum + q.value_quota, 0);
    expect(total).toBeCloseTo(100, 2);
  });

  it('creates a "fixa" expense with a single unpaid quota', async () => {
    await openNewExpenseModal();

    await userEvent.type(screen.getByLabelText('Descrição'), 'Aluguel');
    await userEvent.type(screen.getByLabelText('Valor'), '1200,00');
    await selectExpenseType('Fixa');

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [, payload] = lastPostCall<ExpensePayload>();
    expect(payload.expense_type).toBe('FIXED');
    expect(payload.installments).toBe(1);
    expect(payload.quotas).toEqual([
      { number: 1, date_expected: expect.any(String), paid: false, value_quota: 1200 },
    ]);
  });

  it('lets the user uncheck a participant so payers[] excludes them', async () => {
    await openNewExpenseModal();

    await userEvent.type(screen.getByLabelText('Descrição'), 'Cinema');
    await userEvent.type(screen.getByLabelText('Valor'), '50,00');

    await userEvent.click(screen.getByRole('checkbox', { name: 'João' }));

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [, payload] = lastPostCall<ExpensePayload>();
    expect(payload.payers).toEqual([1]);
  });

  it('defaults all participants checked even when members resolve after the modal is already open (regression)', async () => {
    let resolveMembers: (value: { data: typeof members }) => void = () => {};
    const membersPromise = new Promise<{ data: typeof members }>(resolve => {
      resolveMembers = resolve;
    });

    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/members')) {
        return membersPromise;
      }
      if (url.includes('/me')) {
        return Promise.resolve({ data: { id: 1 } });
      }
      if (url.includes('/expenses')) {
        return Promise.resolve({ data: [] });
      }
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(screen.getByRole('button', { name: /nova despesa/i }));
    await screen.findByText('Cadastrar nova despesa');

    resolveMembers({ data: members });
    await screen.findByRole('checkbox', { name: 'João' });

    await userEvent.type(screen.getByLabelText('Descrição'), 'Cinema');
    await userEvent.type(screen.getByLabelText('Valor'), '50,00');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [, payload] = lastPostCall<ExpensePayload>();
    expect(payload.payers).toEqual([1, 2]);
  });

  it('blocks saving when no participant is selected', async () => {
    const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
    await openNewExpenseModal();

    await userEvent.type(screen.getByLabelText('Descrição'), 'Cinema');
    await userEvent.type(screen.getByLabelText('Valor'), '50,00');

    await userEvent.click(screen.getByRole('checkbox', { name: 'Isac' }));
    await userEvent.click(screen.getByRole('checkbox', { name: 'João' }));

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(axios.post).not.toHaveBeenCalled();
    expect(alertSpy).toHaveBeenCalledWith('Selecione ao menos um participante da divisão.');
    alertSpy.mockRestore();
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
