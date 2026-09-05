import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ExpenseView from './ExpenseView';

vi.mock('axios');

const navigateMock = vi.fn();

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => navigateMock,
    useParams: () => ({ id: '1', expenseId: '9' }),
  };
});

const members = [
  { id: 500, name: 'Isac' },
  { id: 501, name: 'João' },
];

const expenseDetail = {
  id: 9,
  description: 'Aluguel',
  total_value: '1200.00',
  date_payment: '2026-08-01',
  expense_type: 'FIXED' as const,
  user_payer_id: 500,
  payers: [{ id: 500, name: 'Isac' }, { id: 501, name: 'João' }],
};

function mockGetResponses(overrides: { expense?: unknown; members?: unknown } = {}) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/expenses/9')) {
      return Promise.resolve({ data: overrides.expense ?? expenseDetail });
    }
    if (url.includes('/members')) {
      return Promise.resolve({ data: overrides.members ?? members });
    }
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

describe('ExpenseView', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.put).mockReset();
    mockGetResponses();
  });

  it('shows the expense details fetched by id', async () => {
    render(
      <MemoryRouter>
        <ExpenseView />
      </MemoryRouter>
    );

    expect(await screen.findByText('Aluguel')).toBeInTheDocument();
    expect(screen.getByText('R$ 1.200,00')).toBeInTheDocument();
    expect(screen.getByText('Fixa')).toBeInTheDocument();
    expect(await screen.findByText('Credor:')).toBeInTheDocument();
    expect(screen.getAllByText('Isac').length).toBeGreaterThan(0);

    expect(vi.mocked(axios.get).mock.calls.some(call => (call[0] as string).includes('/api/expenses/9'))).toBe(true);
  });

  it('shows "não encontrada" with a link back when the expense does not exist or is not accessible', async () => {
    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/expenses/9')) {
        return Promise.reject({ response: { status: 404 } });
      }
      return Promise.resolve({ data: members });
    });

    render(
      <MemoryRouter>
        <ExpenseView />
      </MemoryRouter>
    );

    expect(await screen.findByText('Despesa não encontrada')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Voltar para a listagem' })).toHaveAttribute(
      'href',
      '/groups/1/expenses'
    );
  });

  it('redirects to login on a 401', async () => {
    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/expenses/9')) {
        return Promise.reject({ response: { status: 401 } });
      }
      return Promise.resolve({ data: members });
    });

    render(
      <MemoryRouter>
        <ExpenseView />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(navigateMock).toHaveBeenCalledWith('/', { replace: true });
    });
  });

  it('enters edit mode with the current values pre-filled and saves via PUT', async () => {
    const user = userEvent.setup();
    vi.mocked(axios.put).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <ExpenseView />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');
    await user.click(screen.getByRole('button', { name: 'Editar' }));

    expect(await screen.findByText('Editar despesa')).toBeInTheDocument();
    expect(screen.getByDisplayValue('Aluguel')).toBeInTheDocument();

    await user.clear(screen.getByLabelText('Descrição'));
    await user.type(screen.getByLabelText('Descrição'), 'Aluguel reajustado');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.put).toHaveBeenCalled());
    const [url, payload] = vi.mocked(axios.put).mock.calls[0];
    expect(url).toContain('/api/expenses/9');
    expect(payload).toMatchObject({
      description: 'Aluguel reajustado',
      user_payer_id: 500,
      payers: [500, 501],
      // Regressão: total_value vem da API como "1200.00" (formato de
      // máquina). Sem reformatar pro padrão pt-BR que o parser de Valor
      // espera, salvar sem tocar no campo mandava 120000 (multiplicado por
      // ~100) em vez de 1200.
      total_value: 1200,
    });
  });

  it('does not corrupt total_value when saving without touching the Valor field', async () => {
    const user = userEvent.setup();
    vi.mocked(axios.put).mockResolvedValue({ data: {} });

    mockGetResponses({ expense: { ...expenseDetail, total_value: '1234.56' } });

    render(
      <MemoryRouter>
        <ExpenseView />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');
    await user.click(screen.getByRole('button', { name: 'Editar' }));
    await screen.findByText('Editar despesa');

    // O campo já vem pré-preenchido no formato pt-BR (não o formato cru da API).
    expect(screen.getByDisplayValue('1.234,56')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.put).toHaveBeenCalled());
    const [, payload] = vi.mocked(axios.put).mock.calls[0];
    expect(payload).toMatchObject({ total_value: 1234.56 });
  });

  it('shows the error message returned by the API when saving fails', async () => {
    const user = userEvent.setup();
    vi.mocked(axios.put).mockRejectedValue({
      response: { data: { error: 'Não é possível alterar o valor de uma despesa já paga.' } },
    });

    render(
      <MemoryRouter>
        <ExpenseView />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');
    await user.click(screen.getByRole('button', { name: 'Editar' }));
    await screen.findByText('Editar despesa');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(await screen.findByText('Não é possível alterar o valor de uma despesa já paga.')).toBeInTheDocument();
  });

  it('cancels edit mode without saving', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ExpenseView />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');
    await user.click(screen.getByRole('button', { name: 'Editar' }));
    await screen.findByText('Editar despesa');
    await user.click(screen.getByRole('button', { name: 'Cancelar' }));

    expect(await screen.findByText('R$ 1.200,00')).toBeInTheDocument();
    expect(axios.put).not.toHaveBeenCalled();
  });

  it('shows a "Ver comprovante" link when the latest paid quota has a proof', async () => {
    mockGetResponses({
      expense: {
        ...expenseDetail,
        quotas: [
          { date_expected: '2026-07-01', paid: true, payment_proof_url: '/api/groups/1/proofs/quota/7?signature=stub' },
          { date_expected: '2026-08-01', paid: true, payment_proof_url: '/api/groups/1/proofs/quota/8?signature=stub' },
        ],
      },
    });

    render(
      <MemoryRouter>
        <ExpenseView />
      </MemoryRouter>
    );

    const link = await screen.findByRole('link', { name: 'Ver comprovante' });
    expect(link).toHaveAttribute('href', '/api/groups/1/proofs/quota/8?signature=stub');
  });

  it('does not show "Ver comprovante" when no quota is paid with a proof', async () => {
    mockGetResponses({
      expense: {
        ...expenseDetail,
        quotas: [{ date_expected: '2026-08-01', paid: false, payment_proof_url: null }],
      },
    });

    render(
      <MemoryRouter>
        <ExpenseView />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');
    expect(screen.queryByRole('link', { name: 'Ver comprovante' })).not.toBeInTheDocument();
  });

  it('does not show a type selector for a FIXED expense (default fixture)', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ExpenseView />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');
    await user.click(screen.getByRole('button', { name: 'Editar' }));

    await screen.findByText('Editar despesa');
    expect(screen.queryByLabelText('Tipo de despesa')).not.toBeInTheDocument();
  });

  it('shows a type selector and lets an IN_CASH expense switch to installments, sending expense_type/installments/quotas', async () => {
    const user = userEvent.setup();
    vi.mocked(axios.put).mockResolvedValue({ data: {} });

    mockGetResponses({
      expense: {
        ...expenseDetail,
        expense_type: 'IN_CASH',
        installments: 1,
        total_value: '100.00',
        quotas: [{ number: 1, date_expected: '2026-08-01', paid: false, payment_proof_url: null }],
      },
    });

    render(
      <MemoryRouter>
        <ExpenseView />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');
    await user.click(screen.getByRole('button', { name: 'Editar' }));
    await screen.findByText('Editar despesa');

    // Retypa o Valor no formato pt-BR que o parser espera — o pré-preenchimento
    // vem cru da API ("100.00") e só é reformatado por
    // docs/feature/concluidas/202608/20260826-fix-edicao-despesa-valor-corrompido/ (branch
    // separada, ainda não integrada aqui); o teste não deve depender de qual
    // das duas branches mergeia primeiro.
    await user.clear(screen.getByLabelText('Valor'));
    await user.type(screen.getByLabelText('Valor'), '100,00');

    const typeField = screen.getByLabelText('Tipo de despesa');
    await user.click(typeField);
    await user.click(await screen.findByRole('option', { name: 'Parcelada' }));

    const installmentsField = await screen.findByLabelText('Quantidade de parcelas');
    await user.type(installmentsField, '2');

    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.put).toHaveBeenCalled());
    const [, payload] = vi.mocked(axios.put).mock.calls[0];
    expect(payload).toMatchObject({
      expense_type: 'IN_INSTALLMENTS',
      installments: 2,
      quotas: [
        { number: 1, value_quota: 50 },
        { number: 2, value_quota: 50 },
      ],
    });
  });

  it('disables "Editar" for an installments expense once any quota is paid', async () => {
    mockGetResponses({
      expense: {
        ...expenseDetail,
        expense_type: 'IN_INSTALLMENTS',
        installments: 2,
        quotas: [
          { number: 1, date_expected: '2026-08-01', paid: true, payment_proof_url: null },
          { number: 2, date_expected: '2026-09-01', paid: false, payment_proof_url: null },
        ],
      },
    });

    render(
      <MemoryRouter>
        <ExpenseView />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');
    expect(screen.getByRole('button', { name: 'Editar' })).toBeDisabled();
  });

  it('keeps "Editar" enabled for an installments expense when no quota is paid yet', async () => {
    mockGetResponses({
      expense: {
        ...expenseDetail,
        expense_type: 'IN_INSTALLMENTS',
        installments: 2,
        quotas: [
          { number: 1, date_expected: '2026-08-01', paid: false, payment_proof_url: null },
          { number: 2, date_expected: '2026-09-01', paid: false, payment_proof_url: null },
        ],
      },
    });

    render(
      <MemoryRouter>
        <ExpenseView />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');
    expect(screen.getByRole('button', { name: 'Editar' })).toBeEnabled();
  });
});
