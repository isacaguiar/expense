import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ExpenseForm from './ExpenseForm';

vi.mock('axios');

type ExpensePayload = {
  expense_type: string;
  installments: number;
  payers: number[];
  quotas: { number: number; date_expected: string; paid: boolean; value_quota: number }[];
};

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

const members = [
  { id: 1, name: 'Isac' },
  { id: 2, name: 'João' },
];

function mockGetResponses() {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/members')) {
      return Promise.resolve({ data: members });
    }
    if (url.includes('/me')) {
      return Promise.resolve({ data: { id: 1 } });
    }
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

async function renderForm() {
  render(
    <MemoryRouter>
      <ExpenseForm />
    </MemoryRouter>
  );

  await screen.findByRole('checkbox', { name: 'João' });
}

async function selectExpenseType(label: string) {
  await userEvent.click(screen.getByLabelText('Tipo de despesa'));
  const listbox = await screen.findByRole('listbox');
  await userEvent.click(within(listbox).getByText(label));
}

describe('ExpenseForm', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: { expense_id: 1 } });
    mockGetResponses();
  });

  it('creates an "à vista" expense with a single paid quota (regression) and returns to the listing', async () => {
    await renderForm();

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
    expect(navigateMock).toHaveBeenCalledWith('/groups/1/expenses');
  });

  it('creates an "parcelada" expense with N quotas, rounding absorbed in the last one', async () => {
    await renderForm();

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
    await renderForm();

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
    await renderForm();

    await userEvent.type(screen.getByLabelText('Descrição'), 'Cinema');
    await userEvent.type(screen.getByLabelText('Valor'), '50,00');

    await userEvent.click(screen.getByRole('checkbox', { name: 'João' }));

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [, payload] = lastPostCall<ExpensePayload>();
    expect(payload.payers).toEqual([1]);
  });

  it('defaults all participants checked even when members resolve after mount (regression)', async () => {
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
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });

    render(
      <MemoryRouter>
        <ExpenseForm />
      </MemoryRouter>
    );

    resolveMembers({ data: members });
    await screen.findByRole('checkbox', { name: 'João' });

    await userEvent.type(screen.getByLabelText('Descrição'), 'Cinema');
    await userEvent.type(screen.getByLabelText('Valor'), '50,00');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [, payload] = lastPostCall<ExpensePayload>();
    expect(payload.payers).toEqual([1, 2]);
  });

  it('blocks saving with an in-app alert (never window.alert) when no participant is selected', async () => {
    const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
    await renderForm();

    await userEvent.type(screen.getByLabelText('Descrição'), 'Cinema');
    await userEvent.type(screen.getByLabelText('Valor'), '50,00');

    await userEvent.click(screen.getByRole('checkbox', { name: 'Isac' }));
    await userEvent.click(screen.getByRole('checkbox', { name: 'João' }));

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(axios.post).not.toHaveBeenCalled();
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Selecione ao menos um participante da divisão.'
    );
    expect(alertSpy).not.toHaveBeenCalled();
    alertSpy.mockRestore();
  });

  it('surfaces the backend error message in an in-app alert (never window.alert) when the API rejects', async () => {
    const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
    vi.mocked(axios.post).mockRejectedValueOnce({
      response: { data: { error: 'Não é possível alterar dados de uma competência já fechada.' } },
    });
    await renderForm();

    await userEvent.type(screen.getByLabelText('Descrição'), 'Adestrador');
    await userEvent.type(screen.getByLabelText('Valor'), '1754,40');

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Não é possível alterar dados de uma competência já fechada.'
    );
    expect(alertSpy).not.toHaveBeenCalled();
    expect(navigateMock).not.toHaveBeenCalled();
    alertSpy.mockRestore();
  });

  it('falls back to a generic in-app message when the API error has no body', async () => {
    const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
    vi.mocked(axios.post).mockRejectedValueOnce(new Error('Network Error'));
    await renderForm();

    await userEvent.type(screen.getByLabelText('Descrição'), 'Mercado');
    await userEvent.type(screen.getByLabelText('Valor'), '80,00');

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    expect(await screen.findByRole('alert')).toHaveTextContent('Falha ao salvar despesa.');
    expect(alertSpy).not.toHaveBeenCalled();
    alertSpy.mockRestore();
  });

  it('navigates back to the listing when Cancelar is clicked', async () => {
    await renderForm();

    await userEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

    expect(navigateMock).toHaveBeenCalledWith('/groups/1/expenses');
  });
});
