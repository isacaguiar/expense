import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ExpenseManager from './ExpenseManager';

vi.mock('axios');

type StopRecurrencePayload = { year: number; month: number };

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
  totalValue?: number;
  userPayerId?: number;
  userCreatorId?: number;
  canUndoPurchase?: boolean;
};

const CURRENT_USER_ID = 500;

function summaryResponse(
  expensesList: SummaryExpenseFixture[] = [],
  cycleOverride: Record<string, unknown> = {},
  extra: Record<string, unknown> = {}
) {
  return {
    cycle: { start: '2026-08-01', end: '2026-08-31', closes_at: '2026-09-05', status: 'open', settled: false, ...cycleOverride },
    totals: { total: 0, paid: 0, pending: 0 },
    expenses: expensesList.map(exp => ({
      paid: false,
      paymentProofUrl: null,
      participants: [],
      userPayerId: CURRENT_USER_ID,
      userCreatorId: CURRENT_USER_ID,
      ...exp,
    })),
    balances: [],
    settlements: [],
    ...extra,
  };
}

function mockGetResponses(
  expensesList: SummaryExpenseFixture[] = [],
  cycleOverride: Record<string, unknown> = {},
  extra: Record<string, unknown> = {}
) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/expenses/summary')) {
      return Promise.resolve({ data: summaryResponse(expensesList, cycleOverride, extra) });
    }
    if (url.includes('/api/me')) {
      return Promise.resolve({ data: { id: CURRENT_USER_ID } });
    }
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

/**
 * "Marcar como paga" agora abre o popup de comprovante (specify §2.1 item 1):
 * clica no ✓, escreve a descrição e confirma.
 */
async function payThroughDialog(user: ReturnType<typeof userEvent.setup>, note = 'Pago em dinheiro') {
  await user.click(await screen.findByRole('button', { name: 'Marcar como paga' }));
  const dialog = await screen.findByRole('dialog');
  await user.type(within(dialog).getByLabelText('Descrição'), note);
  await user.click(within(dialog).getByRole('button', { name: 'Confirmar' }));
}

describe('ExpenseManager - Nova Despesa', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    mockGetResponses();
  });

  it('navigates to the full-page creation route instead of opening a modal', async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await waitFor(() => expect(axios.get).toHaveBeenCalled());

    await userEvent.click(screen.getByRole('button', { name: /nova despesa/i }));

    expect(navigateMock).toHaveBeenCalledWith('/groups/1/expenses/new');
    expect(screen.queryByText('Cadastrar nova despesa')).not.toBeInTheDocument();
  });
});

describe('ExpenseManager - listagem em cards', () => {
  const expenses: SummaryExpenseFixture[] = [
    { id: 9, description: 'Aluguel', value: 1200, date: '2026-08-01', payerName: 'Isac', isFixed: true },
    { id: 10, description: 'Mercado', value: 150, date: '2026-08-05', payerName: 'João', isFixed: false },
  ];

  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    mockGetResponses(expenses);
  });

  it('renders a card per expense, each linking to its view route', async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    expect(await screen.findByText('Aluguel')).toBeInTheDocument();
    expect(screen.getByText('Mercado')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Aluguel/ })).toHaveAttribute('href', '/groups/1/expenses/9');
    expect(screen.getByRole('link', { name: /Mercado/ })).toHaveAttribute('href', '/groups/1/expenses/10');
  });

  it('shows a "Ver comprovante" link (row and detail modal) when the expense is paid with a proof', async () => {
    const user = userEvent.setup();

    mockGetResponses([
      {
        id: 9,
        description: 'Aluguel',
        value: 1200,
        date: '2026-08-01',
        payerName: 'Isac',
        isFixed: true,
        paid: true,
        paymentProofUrl: '/api/groups/1/proofs/quota/9?signature=stub',
      },
      { id: 10, description: 'Mercado', value: 150, date: '2026-08-05', payerName: 'João', isFixed: false },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');

    const proofLinks = screen.getAllByRole('link', { name: 'Ver comprovante' });
    expect(proofLinks).toHaveLength(1);
    expect(proofLinks[0]).toHaveAttribute('href', '/api/groups/1/proofs/quota/9?signature=stub');

    // A despesa com comprovante é o Aluguel; a posição dele na lista não importa aqui.
    const aluguelRow = screen.getByRole('link', { name: 'Aluguel' }).closest('tr') as HTMLElement;
    await user.click(within(aluguelRow).getByRole('button', { name: 'Ver detalhes' }));

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByRole('link', { name: 'Ver comprovante' })).toHaveAttribute(
      'href',
      '/api/groups/1/proofs/quota/9?signature=stub'
    );
  });

  describe('descrição do pagamento no modal de detalhes', () => {
    const renderManager = () =>
      render(
        <MemoryRouter>
          <ExpenseManager />
        </MemoryRouter>
      );

    it('shows the note of a paid expense, with no proof link when it was paid with text only', async () => {
      const user = userEvent.setup();
      mockGetResponses([
        {
          id: 30,
          description: 'Condomínio',
          value: 500,
          date: '2026-08-02',
          payerName: 'Isac',
          paid: true,
          paymentProofUrl: null,
          paymentNote: 'Pago em dinheiro, recibo 123',
        },
      ]);
      renderManager();

      await screen.findByText('Condomínio');
      // Sem foto, a lista não mostra o ícone de comprovante.
      expect(screen.queryByRole('link', { name: 'Ver comprovante' })).not.toBeInTheDocument();

      await user.click(screen.getByRole('button', { name: 'Ver detalhes' }));

      const dialog = await screen.findByRole('dialog');
      expect(within(dialog).getByText('Descrição do pagamento: Pago em dinheiro, recibo 123')).toBeInTheDocument();
      expect(within(dialog).queryByRole('link', { name: 'Ver comprovante' })).not.toBeInTheDocument();
    });

    it('shows both the proof link and the note when the payment has a photo and a text', async () => {
      const user = userEvent.setup();
      mockGetResponses([
        {
          id: 31,
          description: 'Internet',
          value: 120,
          date: '2026-08-03',
          payerName: 'Isac',
          paid: true,
          paymentProofUrl: '/api/groups/1/proofs/quota/31?signature=stub',
          paymentNote: 'Pix agendado',
        },
      ]);
      renderManager();

      await screen.findByText('Internet');
      await user.click(screen.getByRole('button', { name: 'Ver detalhes' }));

      const dialog = await screen.findByRole('dialog');
      expect(within(dialog).getByRole('link', { name: 'Ver comprovante' })).toBeInTheDocument();
      expect(within(dialog).getByText('Descrição do pagamento: Pix agendado')).toBeInTheDocument();
    });

    it('shows no note block for a paid expense without a note', async () => {
      const user = userEvent.setup();
      mockGetResponses([
        { id: 32, description: 'Luz', value: 90, date: '2026-08-04', payerName: 'Isac', paid: true, paymentNote: null },
      ]);
      renderManager();

      await screen.findByText('Luz');
      await user.click(screen.getByRole('button', { name: 'Ver detalhes' }));

      const dialog = await screen.findByRole('dialog');
      expect(within(dialog).queryByText(/Descrição do pagamento:/)).not.toBeInTheDocument();
    });

    it('shows no note block for a pending expense even if the payload carries a stale note', async () => {
      const user = userEvent.setup();
      mockGetResponses([
        { id: 33, description: 'Gás', value: 80, date: '2026-08-05', payerName: 'Isac', paid: false, paymentNote: 'sobra' },
      ]);
      renderManager();

      await screen.findByText('Gás');
      await user.click(screen.getByRole('button', { name: 'Ver detalhes' }));

      const dialog = await screen.findByRole('dialog');
      expect(within(dialog).queryByText(/Descrição do pagamento:/)).not.toBeInTheDocument();
    });

    it('renders markup in the note as plain text', async () => {
      const user = userEvent.setup();
      mockGetResponses([
        {
          id: 34,
          description: 'Água',
          value: 60,
          date: '2026-08-06',
          payerName: 'Isac',
          paid: true,
          paymentNote: '<b>negrito</b>',
        },
      ]);
      renderManager();

      await screen.findByText('Água');
      await user.click(screen.getByRole('button', { name: 'Ver detalhes' }));

      const dialog = await screen.findByRole('dialog');
      expect(within(dialog).getByText('Descrição do pagamento: <b>negrito</b>')).toBeInTheDocument();
      expect(dialog.querySelector('b')).toBeNull();
    });
  });

  it('filters by description on the client side', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');
    await user.type(screen.getByLabelText('Buscar despesa'), 'merc');

    expect(screen.queryByText('Aluguel')).not.toBeInTheDocument();
    expect(screen.getByText('Mercado')).toBeInTheDocument();
    expect(vi.mocked(axios.get).mock.calls.filter(call => (call[0] as string).includes('/expenses/summary'))).toHaveLength(1);
  });

  it('displays the expense date in the details modal without an off-by-one-day shift in negative timezones', async () => {
    mockGetResponses([
      { id: 11, description: 'Assinatura', value: 50, date: '2026-07-16', payerName: 'Isac', isFixed: false },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Assinatura');
    // Data não é mais coluna da tabela (TASK-006) — só aparece no modal de
    // detalhes (TASK-007), aberto pelo ícone "Ver detalhes".
    await userEvent.click(screen.getByRole('button', { name: 'Ver detalhes' }));

    expect(await screen.findByText(/16\/07\/2026/)).toBeInTheDocument();
  });

  it('filters by type (Fixas/Variáveis) on the client side', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');
    await user.click(screen.getByRole('button', { name: 'Fixas' }));

    expect(screen.getByText('Aluguel')).toBeInTheDocument();
    expect(screen.queryByText('Mercado')).not.toBeInTheDocument();
  });
});

describe('ExpenseManager - filtros de Credor, Devedores e Status', () => {
  const isac = { id: CURRENT_USER_ID, name: 'Isac', avatarUrl: null };
  const joao = { id: 501, name: 'João', avatarUrl: null };
  const maria = { id: 502, name: 'Maria', avatarUrl: null };

  // Competência atual — Isac é credor de Aluguel e Internet; João, de Mercado:
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
      participantDetails: [isac, joao, maria]
    },
    {
      id: 10,
      description: 'Mercado',
      value: 150,
      date: '2026-08-05',
      payerName: 'João',
      userPayerId: 501,
      isFixed: false,
      participantDetails: [joao, maria]
    },
    {
      id: 11,
      description: 'Internet',
      value: 100,
      date: '2026-08-10',
      payerName: 'Isac',
      userPayerId: CURRENT_USER_ID,
      isFixed: false,
      participantDetails: [isac, joao]
    }
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
      participantDetails: [isac, joao]
    },
    {
      id: 21,
      description: 'Seguro',
      value: 90,
      date: '2026-07-12',
      payerName: 'João',
      userPayerId: 501,
      isFixed: false,
      participantDetails: [joao]
    }
  ];

  // Só existe numa competência atual alternativa (ver o teste do credor que some).
  const academia: SummaryExpenseFixture = {
    id: 12,
    description: 'Academia',
    value: 80,
    date: '2026-08-12',
    payerName: 'Maria',
    userPayerId: 502,
    isFixed: false,
    participantDetails: [maria]
  };

  const allDescriptions = ['Aluguel', 'Mercado', 'Internet', 'Academia', 'Condomínio', 'Seguro'];
  const shown = () => allDescriptions.filter(description => screen.queryByText(description) !== null);

  type User = ReturnType<typeof userEvent.setup>;

  const renderScreen = async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );
    await screen.findByText('Aluguel');
  };

  // Abre o select múltiplo, escolhe as opções e fecha a lista (o Autocomplete
  // mantém a lista aberta entre escolhas).
  const pick = async (user: User, field: 'Credor' | 'Devedores', ...names: string[]) => {
    await user.click(screen.getByRole('combobox', { name: field }));
    for (const name of names) {
      await user.click(await screen.findByRole('option', { name }));
    }
    await user.keyboard('{Escape}');
  };

  const group = (name: 'Tipo' | 'Status') => within(screen.getByRole('group', { name }));

  // Chips das pessoas selecionadas dentro de um dos selects múltiplos.
  const selectedChips = (field: 'Credor' | 'Devedores') =>
    screen.getByRole('combobox', { name: field }).closest('.MuiAutocomplete-root')!.querySelectorAll('.MuiAutocomplete-tag');

  // Devolve a competência anterior quando o hook pede `cycles_ago=1`.
  const mockTwoCycles = (current = currentCycle, previous = previousCycle) => {
    vi.mocked(axios.get).mockImplementation((url: string, config?: { params?: { cycles_ago?: number } }) => {
      if (url.includes('/expenses/summary')) {
        const list = config?.params?.cycles_ago === 1 ? previous : current;
        return Promise.resolve({ data: summaryResponse(list) });
      }
      if (url.includes('/api/me')) {
        return Promise.resolve({ data: { id: CURRENT_USER_ID } });
      }
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });
  };

  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    mockGetResponses(currentCycle);
  });

  it('filtra por Credor, e dois credores valem como OU', async () => {
    const user = userEvent.setup();
    await renderScreen();

    await pick(user, 'Credor', 'João');
    expect(shown()).toEqual(['Mercado']);

    await pick(user, 'Credor', 'Isac');
    expect(shown()).toEqual(['Aluguel', 'Mercado', 'Internet']);
  });

  it('filtra por Devedores, e dois devedores valem como OU', async () => {
    const user = userEvent.setup();
    await renderScreen();

    await pick(user, 'Devedores', 'Maria');
    expect(shown()).toEqual(['Aluguel', 'Mercado']);

    await pick(user, 'Devedores', 'Isac');
    expect(shown()).toEqual(['Aluguel', 'Mercado', 'Internet']);
  });

  it('conta o credor como devedor da própria despesa: João aparece em Mercado, que ele pagou', async () => {
    const user = userEvent.setup();
    await renderScreen();

    await pick(user, 'Devedores', 'João');

    expect(shown()).toEqual(['Aluguel', 'Mercado', 'Internet']);
  });

  it('filtra por Status: Pagas, Pendentes e de volta a Todas', async () => {
    const user = userEvent.setup();
    await renderScreen();

    await user.click(group('Status').getByRole('button', { name: 'Pagas' }));
    expect(shown()).toEqual(['Aluguel']);

    await user.click(group('Status').getByRole('button', { name: 'Pendentes' }));
    expect(shown()).toEqual(['Mercado', 'Internet']);

    await user.click(group('Status').getByRole('button', { name: 'Todas' }));
    expect(shown()).toEqual(['Aluguel', 'Mercado', 'Internet']);
  });

  it('combina Credor, Status, Tipo e busca: só passa quem atende a todos', async () => {
    const user = userEvent.setup();
    await renderScreen();

    await pick(user, 'Credor', 'Isac');
    expect(shown()).toEqual(['Aluguel', 'Internet']);

    await user.click(group('Status').getByRole('button', { name: 'Pendentes' }));
    expect(shown()).toEqual(['Internet']);

    await user.click(group('Tipo').getByRole('button', { name: 'Fixas' }));
    expect(shown()).toEqual([]);
    expect(screen.getByText('Nenhuma despesa encontrada para esse filtro.')).toBeInTheDocument();

    await user.click(group('Tipo').getByRole('button', { name: 'Todas' }));
    await user.type(screen.getByLabelText('Buscar despesa'), 'net');
    expect(shown()).toEqual(['Internet']);
  });

  it('não refaz a chamada ao summary ao filtrar', async () => {
    const user = userEvent.setup();
    await renderScreen();

    await pick(user, 'Credor', 'João');
    await pick(user, 'Devedores', 'Maria');
    await user.click(group('Status').getByRole('button', { name: 'Pendentes' }));

    expect(vi.mocked(axios.get).mock.calls.filter(call => (call[0] as string).includes('/expenses/summary'))).toHaveLength(1);
  });

  it('filtra Devedores por nome numa competência de ciclo selado, sem participantDetails', async () => {
    const user = userEvent.setup();
    mockGetResponses(
      currentCycle.map(exp => ({
        ...exp,
        participantDetails: undefined,
        participants: exp.participantDetails?.map(p => p.name) ?? []
      }))
    );
    await renderScreen();

    await pick(user, 'Devedores', 'Maria');

    expect(shown()).toEqual(['Aluguel', 'Mercado']);
  });

  it('mantém o filtro de Credor ao navegar para uma competência em que a pessoa também aparece', async () => {
    const user = userEvent.setup();
    mockTwoCycles();
    await renderScreen();

    await pick(user, 'Credor', 'Isac');
    expect(shown()).toEqual(['Aluguel', 'Internet']);

    await user.click(screen.getByRole('button', { name: 'Competência anterior' }));
    await screen.findByText('Condomínio');

    expect(shown()).toEqual(['Condomínio']);
  });

  it('ignora um devedor que não existe na competência navegada: some do campo e não restringe a lista', async () => {
    const user = userEvent.setup();
    mockTwoCycles();
    await renderScreen();

    await pick(user, 'Devedores', 'Maria');
    expect(shown()).toEqual(['Aluguel', 'Mercado']);
    expect(selectedChips('Devedores')).toHaveLength(1);

    await user.click(screen.getByRole('button', { name: 'Competência anterior' }));
    await screen.findByText('Condomínio');

    expect(shown()).toEqual(['Condomínio', 'Seguro']);
    expect(selectedChips('Devedores')).toHaveLength(0);
  });

  it('ignora um credor que não existe na competência navegada: some do campo e não restringe a lista', async () => {
    const user = userEvent.setup();
    mockTwoCycles([...currentCycle, academia]);
    await renderScreen();

    await pick(user, 'Credor', 'Maria');
    expect(shown()).toEqual(['Academia']);
    expect(selectedChips('Credor')).toHaveLength(1);

    await user.click(screen.getByRole('button', { name: 'Competência anterior' }));
    await screen.findByText('Condomínio');

    expect(shown()).toEqual(['Condomínio', 'Seguro']);
    expect(selectedChips('Credor')).toHaveLength(0);
  });
});

describe('ExpenseManager - modal de detalhes: tipo, parcela e valor por pagador', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
  });

  const openDetails = async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Ver detalhes' }));
    return within(await screen.findByRole('dialog'));
  };

  it('shows "Parcelada n/N", the full expense total and each payer\'s share', async () => {
    mockGetResponses([
      {
        id: 30,
        description: 'Adestrador',
        date: '2026-08-10',
        value: 292.4,
        valuePerPerson: 146.2,
        payerName: 'Naum',
        expenseType: 'IN_INSTALLMENTS',
        installmentNumber: 3,
        installmentsTotal: 6,
        totalValue: 1754.4,
        isFixed: false,
        userPayerId: CURRENT_USER_ID,
        participants: ['Naum', 'Isac'],
        participantDetails: [
          { id: CURRENT_USER_ID, name: 'Naum', avatarUrl: null },
          { id: 501, name: 'Isac', avatarUrl: null },
        ],
      },
    ]);

    const dialog = await openDetails();

    expect(dialog.getByText('Parcelada 3/6')).toBeInTheDocument();
    expect(dialog.getByText(/Total da despesa: R\$ 1\.754,40 em 6x/)).toBeInTheDocument();
    // Valor em destaque continua sendo o da parcela do mês, não o da compra.
    expect(dialog.getByText('R$ 292,40')).toBeInTheDocument();

    // Uma linha por pagador, cada uma com o valor individual; o credor é marcado.
    expect(dialog.getAllByText('R$ 146,20')).toHaveLength(2);
    expect(dialog.getByText('Naum')).toBeInTheDocument();
    expect(dialog.getByText('Isac')).toBeInTheDocument();
    expect(dialog.getByText('(credor)')).toBeInTheDocument();
  });

  it('labels IN_CASH as "À Vista" and hides the installment total', async () => {
    mockGetResponses([
      {
        id: 31,
        description: 'Mercado',
        date: '2026-08-10',
        value: 100,
        valuePerPerson: 100,
        payerName: 'Isac',
        expenseType: 'IN_CASH',
        installmentsTotal: 1,
        totalValue: 100,
        isFixed: false,
        participants: ['Isac'],
        participantDetails: [{ id: CURRENT_USER_ID, name: 'Isac', avatarUrl: null }],
      },
    ]);

    const dialog = await openDetails();

    expect(dialog.getByText('À Vista')).toBeInTheDocument();
    expect(dialog.queryByText(/Total da despesa/)).not.toBeInTheDocument();
  });

  it('falls back to the old label and plain names when the sealed-cycle payload lacks the new fields', async () => {
    mockGetResponses([
      {
        id: 32,
        description: 'Internet',
        date: '2026-08-10',
        value: 80,
        valuePerPerson: 40,
        payerName: 'Isac',
        isFixed: true,
        participants: ['Isac', 'Naum'],
      },
    ]);

    const dialog = await openDetails();

    expect(dialog.getByText('Fixa')).toBeInTheDocument();
    expect(dialog.queryByText(/Total da despesa/)).not.toBeInTheDocument();
    // Sem participantDetails ainda dá pra listar os nomes com o valor de cada um.
    expect(dialog.getByText('Isac')).toBeInTheDocument();
    expect(dialog.getByText('Naum')).toBeInTheDocument();
    expect(dialog.getAllByText('R$ 40,00')).toHaveLength(2);
    expect(dialog.queryByText('(credor)')).not.toBeInTheDocument();
  });
});

describe('ExpenseManager - campos completos e ações condicionais', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
  });

  it('shows status chip and credor with an initials avatar', async () => {
    mockGetResponses([
      {
        id: 20,
        description: 'Mercado',
        value: 300,
        date: '2026-08-10',
        payerName: 'Isac',
        participants: ['Isac', 'Maria'],
        paid: true,
        isFixed: false,
      },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    expect(await screen.findByText('Paga')).toBeInTheDocument();
    // Coluna "Credor" já dá o rótulo — a célula só tem o avatar de iniciais
    // e o valor, sem prefixo. "Pagadores" não é mais coluna da tabela (fica
    // acessível pelo modal de detalhes, TASK-007).
    expect(screen.getByText('Isac')).toBeInTheDocument();
    expect(screen.getByText('I')).toBeInTheDocument();
  });

  it('shows edit, delete and pay icons when the user owns and is the creditor of a pending variable expense', async () => {
    mockGetResponses([
      {
        id: 21,
        description: 'Mercado',
        value: 300,
        date: '2026-08-10',
        payerName: 'Isac',
        paid: false,
        isFixed: false,
        userPayerId: CURRENT_USER_ID,
        userCreatorId: CURRENT_USER_ID,
      },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Mercado');

    expect(screen.getByRole('link', { name: 'Editar despesa' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Excluir despesa' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Marcar como paga' })).toBeInTheDocument();
  });

  it('hides delete and pay icons when the expense is already paid', async () => {
    mockGetResponses([
      {
        id: 22,
        description: 'Mercado',
        value: 300,
        date: '2026-08-10',
        payerName: 'Isac',
        paid: true,
        isFixed: false,
        userPayerId: CURRENT_USER_ID,
        userCreatorId: CURRENT_USER_ID,
      },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Mercado');

    expect(screen.getByRole('link', { name: 'Editar despesa' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Excluir despesa' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Marcar como paga' })).not.toBeInTheDocument();
  });

  it('hides delete icon for FIXED expenses (uses "Remover despesa fixa" instead)', async () => {
    mockGetResponses([
      {
        id: 23,
        description: 'Aluguel',
        value: 1200,
        date: '2026-08-01',
        payerName: 'Isac',
        paid: false,
        isFixed: true,
        userPayerId: CURRENT_USER_ID,
        userCreatorId: CURRENT_USER_ID,
      },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');

    expect(screen.getByRole('button', { name: 'Remover despesa fixa' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Excluir despesa' })).not.toBeInTheDocument();
  });

  it('hides the pay icon when the current user is not the creditor', async () => {
    mockGetResponses([
      {
        id: 24,
        description: 'Mercado',
        value: 300,
        date: '2026-08-10',
        payerName: 'Outra Pessoa',
        paid: false,
        isFixed: false,
        userPayerId: CURRENT_USER_ID + 1,
        userCreatorId: CURRENT_USER_ID,
      },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Mercado');

    expect(screen.getByRole('link', { name: 'Editar despesa' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Marcar como paga' })).not.toBeInTheDocument();
  });

  it('hides edit/delete but keeps "marcar como paga" for the creditor when the cycle is closed', async () => {
    mockGetResponses(
      [
        {
          id: 25,
          description: 'Mercado',
          value: 300,
          date: '2026-07-10',
          payerName: 'Isac',
          paid: false,
          isFixed: false,
          userPayerId: CURRENT_USER_ID,
          userCreatorId: CURRENT_USER_ID,
        },
      ],
      { status: 'closed' }
    );

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Mercado');

    expect(screen.queryByRole('link', { name: 'Editar despesa' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Excluir despesa' })).not.toBeInTheDocument();
    // Pagar/despagar não seguem mais o estado do ciclo (só `future`): o credor
    // marca como paga mesmo com o ciclo fechado — ver usePaymentActions.
    expect(screen.getByRole('button', { name: 'Marcar como paga' })).toBeInTheDocument();
  });
});

describe('ExpenseManager - navegação por competência', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    mockGetResponses([], { start: '2026-08-01', end: '2026-08-31', status: 'open' });
  });

  it('shows the current cycle range in the header', async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    expect(await screen.findByText(/01 de ago.*31 de ago/)).toBeInTheDocument();
  });

  it('requests the previous cycle when the back arrow is clicked', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText(/01 de ago/);
    await user.click(screen.getByLabelText('Competência anterior'));

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
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText(/01 de ago/);
    await user.click(screen.getByLabelText('Próxima competência'));

    await waitFor(() => {
      const lastCall = vi.mocked(axios.get).mock.calls.at(-1);
      expect(lastCall?.[0]).toContain('/expenses/summary');
      expect((lastCall?.[1] as { params: { cycles_ago: number } }).params.cycles_ago).toBe(-1);
    });
  });
});

describe('ExpenseManager - remover despesa Fixa', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    mockGetResponses(
      [{ id: 9, description: 'Aluguel', value: 1200, date: '2026-08-01', payerName: 'Isac', isFixed: true }],
      { start: '2026-08-01', end: '2026-08-31', status: 'open' }
    );
  });

  it('shows the expense description in the confirmation dialog and a success toast after removing', async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    const removeButton = await screen.findByRole('button', { name: 'Remover despesa fixa' });
    await userEvent.click(removeButton);

    expect(await screen.findByText('"Aluguel" deve deixar de aparecer?', { exact: false })).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'A partir deste mês' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    expect(await screen.findByText('Despesa fixa removida com sucesso.')).toBeInTheDocument();
  });

  it('sends the currently viewed cycle\'s month when removing "a partir deste mês"', async () => {
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
    expect(payload).toEqual({ year: 2026, month: 8 });
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
    expect(payload).toEqual({ year: 2026, month: 9 });
  });
});

describe('ExpenseManager - excluir despesa variável', () => {
  const deletableExpense: SummaryExpenseFixture = {
    id: 30,
    description: 'Mercado',
    value: 300,
    date: '2026-08-10',
    payerName: 'Isac',
    paid: false,
    isFixed: false,
    userPayerId: CURRENT_USER_ID,
    userCreatorId: CURRENT_USER_ID,
  };

  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.delete).mockReset();
    mockGetResponses([deletableExpense]);
  });

  it('shows the expense description in the confirmation dialog', async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Excluir despesa' }));

    expect(await screen.findByText('"Mercado"', { exact: false })).toBeInTheDocument();
  });

  it('does not call DELETE when cancelled', async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Excluir despesa' }));
    await screen.findByText('Excluir despesa');
    await userEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

    expect(axios.delete).not.toHaveBeenCalled();
    await waitFor(() => expect(screen.queryByText('Excluir despesa')).not.toBeInTheDocument());
  });

  it('calls DELETE and shows a success toast when confirmed', async () => {
    vi.mocked(axios.delete).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Excluir despesa' }));
    await screen.findByText('Excluir despesa');
    await userEvent.click(screen.getByRole('button', { name: 'Excluir' }));

    await waitFor(() => expect(axios.delete).toHaveBeenCalled());
    const [url] = vi.mocked(axios.delete).mock.calls[0];
    expect(url).toContain('/api/expenses/30');
    expect(await screen.findByText('Despesa excluída com sucesso.')).toBeInTheDocument();
  });

  it('shows the error message returned by the API when deletion fails', async () => {
    vi.mocked(axios.delete).mockRejectedValue({
      response: { data: { error: 'Não é possível excluir uma despesa já paga. Desfaça o pagamento primeiro.' } },
    });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Excluir despesa' }));
    await screen.findByText('Excluir despesa');
    await userEvent.click(screen.getByRole('button', { name: 'Excluir' }));

    expect(
      await screen.findByText('Não é possível excluir uma despesa já paga. Desfaça o pagamento primeiro.')
    ).toBeInTheDocument();
    expect(screen.getByText('Excluir despesa')).toBeInTheDocument();
  });
});

describe('ExpenseManager - fechar/reabrir mês', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
  });

  it('shows "Fechar mês" when the cycle is open and calls POST .../close', async () => {
    mockGetResponses([], { status: 'open' });
    vi.mocked(axios.post).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Fechar mês' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [url] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/groups/1/expenses/close');
    expect(await screen.findByText('Competência fechada com sucesso.')).toBeInTheDocument();
  });

  it('shows "Reabrir mês" when the cycle is closed_manually and calls POST .../reopen', async () => {
    mockGetResponses([], { status: 'closed_manually' });
    vi.mocked(axios.post).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Reabrir mês' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [url] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/groups/1/expenses/reopen');
    expect(await screen.findByText('Competência reaberta com sucesso.')).toBeInTheDocument();
  });

  it('does not show either button when the cycle is automatically closed', async () => {
    mockGetResponses([], { status: 'closed' });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Nenhuma despesa encontrada para esta competência.');
    expect(screen.queryByRole('button', { name: 'Fechar mês' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reabrir mês' })).not.toBeInTheDocument();
  });

  it('does not show either button when the cycle is future', async () => {
    mockGetResponses([], { status: 'future' });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Nenhuma despesa encontrada para esta competência.');
    expect(screen.queryByRole('button', { name: 'Fechar mês' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reabrir mês' })).not.toBeInTheDocument();
  });

  it('hides the buttons after navigating away from the current cycle', async () => {
    const user = userEvent.setup();
    vi.mocked(axios.get).mockImplementation((url: string, config?: { params?: { cycles_ago?: number } }) => {
      if (url.includes('/expenses/summary')) {
        const cyclesAgo = config?.params?.cycles_ago ?? 0;
        const status = cyclesAgo === 0 ? 'open' : 'closed';
        return Promise.resolve({ data: summaryResponse([], { status }) });
      }
      if (url.includes('/api/me')) {
        return Promise.resolve({ data: { id: CURRENT_USER_ID } });
      }
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByRole('button', { name: 'Fechar mês' });

    await user.click(screen.getByLabelText('Competência anterior'));

    await waitFor(() => {
      expect(screen.queryByRole('button', { name: 'Fechar mês' })).not.toBeInTheDocument();
    });
    expect(screen.queryByRole('button', { name: 'Reabrir mês' })).not.toBeInTheDocument();
  });

  it('shows the error message returned by the API when closing fails', async () => {
    mockGetResponses([], { status: 'open' });
    vi.mocked(axios.post).mockRejectedValue({ response: { data: { error: 'Falha genérica de teste.' } } });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Fechar mês' }));

    expect(await screen.findByText('Falha genérica de teste.')).toBeInTheDocument();
  });
});

describe('ExpenseManager - marcar como paga / desfazer pagamento', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
  });

  it('calls POST .../pay and shows a success toast when marking as paid', async () => {
    mockGetResponses([
      {
        id: 40,
        description: 'Mercado',
        value: 300,
        date: '2026-08-10',
        payerName: 'Isac',
        paid: false,
        isFixed: false,
        userPayerId: CURRENT_USER_ID,
        userCreatorId: CURRENT_USER_ID,
      },
    ]);
    vi.mocked(axios.post).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await payThroughDialog(userEvent.setup());

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [url, body] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/expenses/40/pay');
    expect(body).toEqual({ cycles_ago: 0, descricao: 'Pago em dinheiro' });
    expect(await screen.findByText('Despesa marcada como paga.')).toBeInTheDocument();
  });

  it('calls POST .../unpay and shows a success toast when undoing payment', async () => {
    mockGetResponses([
      {
        id: 41,
        description: 'Mercado',
        value: 300,
        date: '2026-08-10',
        payerName: 'Isac',
        paid: true,
        isFixed: false,
        userPayerId: CURRENT_USER_ID,
        userCreatorId: CURRENT_USER_ID,
      },
    ]);
    vi.mocked(axios.post).mockResolvedValue({ data: {} });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByRole('button', { name: 'Desfazer pagamento' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [url] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/expenses/41/unpay');
    expect(await screen.findByText('Pagamento desfeito.')).toBeInTheDocument();
  });

  it('does not show "Desfazer pagamento" for someone who is not the creditor', async () => {
    mockGetResponses([
      {
        id: 42,
        description: 'Mercado',
        value: 300,
        date: '2026-08-10',
        payerName: 'Outra Pessoa',
        paid: true,
        isFixed: false,
        userPayerId: CURRENT_USER_ID + 1,
        userCreatorId: CURRENT_USER_ID,
      },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Mercado');
    expect(screen.queryByRole('button', { name: 'Desfazer pagamento' })).not.toBeInTheDocument();
  });

  it('shows the error message returned by the API when marking as paid fails', async () => {
    mockGetResponses([
      {
        id: 43,
        description: 'Mercado',
        value: 300,
        date: '2026-08-10',
        payerName: 'Isac',
        paid: false,
        isFixed: false,
        userPayerId: CURRENT_USER_ID,
        userCreatorId: CURRENT_USER_ID,
      },
    ]);
    vi.mocked(axios.post).mockRejectedValue({
      response: { data: { error: 'Não é possível alterar dados de uma competência já fechada.' } },
    });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await payThroughDialog(userEvent.setup());

    expect(await screen.findByText('Não é possível alterar dados de uma competência já fechada.')).toBeInTheDocument();
  });
});

// Compra parcelada registrada já paga no cadastro: a lista só mostra o que o servidor manda
// (docs/feature/20261027-pagamento-no-cadastro-despesa, CA-F13).
describe('ExpenseManager - compra paga no cadastro: chip e filtro de Status', () => {
  const paidPurchase: SummaryExpenseFixture = {
    id: 80,
    description: 'Geladeira',
    value: 100,
    totalValue: 300,
    date: '2026-08-10',
    payerName: 'Isac',
    paid: true,
    isFixed: false,
    expenseType: 'IN_INSTALLMENTS',
    installmentNumber: 1,
    installmentsTotal: 3,
    paymentNote: 'Cartão de crédito',
    userPayerId: CURRENT_USER_ID,
    userCreatorId: CURRENT_USER_ID,
  };

  const pendingExpense: SummaryExpenseFixture = {
    id: 81,
    description: 'Mercado',
    value: 200,
    date: '2026-08-12',
    payerName: 'Isac',
    paid: false,
    isFixed: false,
    userPayerId: CURRENT_USER_ID,
    userCreatorId: CURRENT_USER_ID,
  };

  const shown = () => ['Geladeira', 'Mercado'].filter(description => screen.queryByText(description) !== null);
  const statusButton = (name: 'Todas' | 'Pagas' | 'Pendentes') =>
    within(screen.getByRole('group', { name: 'Status' })).getByRole('button', { name });

  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    mockGetResponses([paidPurchase, pendingExpense]);
  });

  it('shows the chip Paga for the purchase and Pendente for the other expense', async () => {
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );
    await screen.findByText('Geladeira');

    expect(screen.getAllByText('Paga')).toHaveLength(1);
    expect(screen.getAllByText('Pendente')).toHaveLength(1);
    expect(screen.getByText('Paga').closest('tr')).toHaveTextContent('Geladeira');
    expect(screen.getByText('Pendente').closest('tr')).toHaveTextContent('Mercado');
  });

  it('puts the purchase under Pagas and not under Pendentes, and brings it back with Todas', async () => {
    const user = userEvent.setup();
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );
    await screen.findByText('Geladeira');

    await user.click(statusButton('Pagas'));
    expect(shown()).toEqual(['Geladeira']);

    await user.click(statusButton('Pendentes'));
    expect(shown()).toEqual(['Mercado']);

    await user.click(statusButton('Todas'));
    expect(shown()).toEqual(['Geladeira', 'Mercado']);
  });
});

// Desfazer de uma vez o pagamento de uma compra parcelada registrada no cadastro
// (docs/feature/20261027-pagamento-no-cadastro-despesa, plan §7, R19, CA-F12).
describe('ExpenseManager - desfazer pagamento da compra', () => {
  const purchase: SummaryExpenseFixture = {
    id: 70,
    description: 'Geladeira',
    value: 100,
    totalValue: 300,
    date: '2026-08-10',
    payerName: 'Isac',
    paid: true,
    isFixed: false,
    expenseType: 'IN_INSTALLMENTS',
    installmentNumber: 1,
    installmentsTotal: 3,
    userPayerId: CURRENT_USER_ID,
    userCreatorId: CURRENT_USER_ID,
    canUndoPurchase: true,
  };

  const undoAction = () => screen.findByRole('button', { name: 'Desfazer pagamento da compra' });

  const renderManager = () =>
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
  });

  it('shows the undo-purchase action only when the server says so', async () => {
    mockGetResponses([purchase]);
    renderManager();

    expect(await undoAction()).toBeInTheDocument();
    // Ao lado do "Desfazer pagamento" da parcela, que segue como sempre.
    expect(screen.getByRole('button', { name: 'Desfazer pagamento' })).toBeInTheDocument();
  });

  it('does not show it when the server did not send canUndoPurchase', async () => {
    mockGetResponses([{ ...purchase, canUndoPurchase: undefined }]);
    renderManager();

    await screen.findByText('Geladeira');
    expect(screen.queryByRole('button', { name: 'Desfazer pagamento da compra' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Desfazer pagamento' })).toBeInTheDocument();
  });

  it('hides it for other users', async () => {
    mockGetResponses([{ ...purchase, userPayerId: CURRENT_USER_ID + 1 }]);
    renderManager();

    await screen.findByText('Geladeira');
    expect(screen.queryByRole('button', { name: 'Desfazer pagamento da compra' })).not.toBeInTheDocument();
  });

  it('asks for confirmation, saying what will happen, before calling the route', async () => {
    mockGetResponses([purchase]);
    renderManager();

    await userEvent.click(await undoAction());

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('Desfazer pagamento da compra')).toBeInTheDocument();
    expect(dialog).toHaveTextContent('Todas as parcelas de "Geladeira" voltarão a ficar pendentes');
    expect(dialog).toHaveTextContent('o comprovante e a descrição do pagamento serão removidos');
    expect(dialog).toHaveTextContent('Depois que o ciclo da compra encerrar, isto não poderá mais ser feito.');
    expect(axios.post).not.toHaveBeenCalled();

    await userEvent.click(within(dialog).getByRole('button', { name: 'Cancelar' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(axios.post).not.toHaveBeenCalled();
  });

  it('calls POST .../undo-purchase after confirming, reloads the list and the chip goes back to Pendente (CA-F12)', async () => {
    mockGetResponses([purchase]);
    vi.mocked(axios.post).mockImplementation(async () => {
      // O servidor já limpou as parcelas: o recarregamento traz a compra pendente e sem a ação.
      mockGetResponses([{ ...purchase, paid: false, canUndoPurchase: undefined }]);
      return { data: {} };
    });
    renderManager();

    expect(await screen.findByText('Paga')).toBeInTheDocument();

    await userEvent.click(await undoAction());
    await userEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Desfazer' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
    expect(vi.mocked(axios.post).mock.calls[0][0]).toMatch(/\/api\/expenses\/70\/undo-purchase$/);
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(await screen.findByText('Pagamento da compra desfeito.')).toBeInTheDocument();
    expect(await screen.findByText('Pendente')).toBeInTheDocument();
    expect(screen.queryByText('Paga')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Desfazer pagamento da compra' })).not.toBeInTheDocument();
  });

  it('shows the reason the server gave when the undo is refused, and keeps the list as it was', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    mockGetResponses([purchase]);
    vi.mocked(axios.post).mockRejectedValue({
      response: { data: { error: 'O ciclo desta compra já foi encerrado; o pagamento não pode mais ser desfeito.' } },
    });
    renderManager();

    await userEvent.click(await undoAction());
    await userEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Desfazer' }));

    expect(
      await screen.findByText('O ciclo desta compra já foi encerrado; o pagamento não pode mais ser desfeito.')
    ).toBeInTheDocument();
    expect(screen.queryByText('Pagamento da compra desfeito.')).not.toBeInTheDocument();
    expect(screen.getByText('Paga')).toBeInTheDocument();
  });
});

describe('ExpenseManager - popup de comprovante ao marcar como paga', () => {
  const unpaid: SummaryExpenseFixture = {
    id: 60,
    description: 'Mercado',
    value: 300,
    date: '2026-08-10',
    payerName: 'Isac',
    paid: false,
    isFixed: false,
    userPayerId: CURRENT_USER_ID,
    userCreatorId: CURRENT_USER_ID,
  };

  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    mockGetResponses([unpaid]);
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
  });

  const renderManager = () =>
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

  it('opens the proof popup on the ✓ click and sends NOTHING until it is confirmed', async () => {
    const user = userEvent.setup();
    renderManager();

    await user.click(await screen.findByRole('button', { name: 'Marcar como paga' }));

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('Confirmar pagamento')).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: 'Confirmar' })).toBeDisabled();
    expect(axios.post).not.toHaveBeenCalled();
  });

  it('enables Confirmar with only a note and sends it as JSON', async () => {
    const user = userEvent.setup();
    renderManager();

    await payThroughDialog(user, 'Pago em dinheiro, recibo 123');

    await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
    const [url, body] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/expenses/60/pay');
    expect(body).toEqual({ cycles_ago: 0, descricao: 'Pago em dinheiro, recibo 123' });
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  });

  it('enables Confirmar with only a photo and sends it as multipart', async () => {
    const user = userEvent.setup();
    renderManager();

    await user.click(await screen.findByRole('button', { name: 'Marcar como paga' }));
    const dialog = await screen.findByRole('dialog');
    const file = new File(['x'], 'comprovante.jpg', { type: 'image/jpeg' });
    await user.upload(dialog.querySelector('input[type="file"]') as HTMLInputElement, file);
    await user.click(within(dialog).getByRole('button', { name: 'Confirmar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
    const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
    expect(body).toBeInstanceOf(FormData);
    expect(body.get('comprovante')).toBe(file);
    expect(body.get('cycles_ago')).toBe('0');
    expect(body.has('descricao')).toBe(false);
  });

  it('sends the photo and the note together', async () => {
    const user = userEvent.setup();
    renderManager();

    await user.click(await screen.findByRole('button', { name: 'Marcar como paga' }));
    const dialog = await screen.findByRole('dialog');
    await user.upload(
      dialog.querySelector('input[type="file"]') as HTMLInputElement,
      new File(['x'], 'comprovante.jpg', { type: 'image/jpeg' })
    );
    await user.type(within(dialog).getByLabelText('Descrição'), 'Pix agendado para sexta');
    await user.click(within(dialog).getByRole('button', { name: 'Confirmar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
    const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
    expect(body.get('descricao')).toBe('Pix agendado para sexta');
    expect(body.get('comprovante')).toBeInstanceOf(File);
  });

  it('closes without sending anything when the popup is cancelled', async () => {
    const user = userEvent.setup();
    renderManager();

    await user.click(await screen.findByRole('button', { name: 'Marcar como paga' }));
    const dialog = await screen.findByRole('dialog');
    await user.type(within(dialog).getByLabelText('Descrição'), 'vou desistir');
    await user.click(within(dialog).getByRole('button', { name: 'Cancelar' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(axios.post).not.toHaveBeenCalled();
  });

  it('pays the expense whose ✓ was clicked when there are several', async () => {
    const user = userEvent.setup();
    mockGetResponses([
      { ...unpaid, id: 61, description: 'Aluguel' },
      { ...unpaid, id: 62, description: 'Internet' },
    ]);
    renderManager();

    await screen.findByText('Aluguel');
    const internetRow = screen.getByRole('row', { name: /Internet/ });

    await user.click(within(internetRow).getByRole('button', { name: 'Marcar como paga' }));
    const dialog = await screen.findByRole('dialog');
    await user.type(within(dialog).getByLabelText('Descrição'), 'ok');
    await user.click(within(dialog).getByRole('button', { name: 'Confirmar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
    expect(vi.mocked(axios.post).mock.calls[0][0]).toMatch(/\/api\/expenses\/62\/pay$/);
  });
});

describe('ExpenseManager - editar comprovante depois de paga', () => {
  const PROOF_URL = 'https://api.exemplo.com/api/proofs/9?signature=abc';
  const paid: SummaryExpenseFixture = {
    id: 70,
    description: 'Mercado',
    value: 300,
    date: '2026-08-10',
    payerName: 'Isac',
    paid: true,
    isFixed: false,
    userPayerId: CURRENT_USER_ID,
    userCreatorId: CURRENT_USER_ID,
    paymentProofUrl: PROOF_URL,
    paymentNote: 'Pix agendado',
  };

  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    mockGetResponses([paid]);
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
  });

  const renderManager = () =>
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

  const editButton = () => screen.getByRole('button', { name: 'Editar comprovante' });
  const openEditDialog = async (user: ReturnType<typeof userEvent.setup>) => {
    await user.click(await screen.findByRole('button', { name: 'Editar comprovante' }));
    return screen.findByRole('dialog');
  };

  describe('visibilidade do ícone', () => {
    it('shows "Editar comprovante" for the creditor of a paid expense, before "Desfazer pagamento"', async () => {
      renderManager();

      const edit = await screen.findByRole('button', { name: 'Editar comprovante' });
      const undo = screen.getByRole('button', { name: 'Desfazer pagamento' });
      expect(edit.compareDocumentPosition(undo) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('also shows it on a paid expense that has neither photo nor description', async () => {
      mockGetResponses([{ ...paid, paymentProofUrl: null, paymentNote: null }]);
      renderManager();

      expect(await screen.findByRole('button', { name: 'Editar comprovante' })).toBeInTheDocument();
    });

    it('does not show it on a pending expense', async () => {
      mockGetResponses([{ ...paid, paid: false }]);
      renderManager();

      await screen.findByText('Mercado');
      expect(screen.queryByRole('button', { name: 'Editar comprovante' })).not.toBeInTheDocument();
    });

    it('does not show it for someone who is not the creditor', async () => {
      mockGetResponses([{ ...paid, userPayerId: CURRENT_USER_ID + 1 }]);
      renderManager();

      await screen.findByText('Mercado');
      expect(screen.queryByRole('button', { name: 'Editar comprovante' })).not.toBeInTheDocument();
    });

    it('does not show it in a sealed (settled) cycle', async () => {
      mockGetResponses([paid], { status: 'closed', settled: true });
      renderManager();

      await screen.findByText('Mercado');
      expect(screen.queryByRole('button', { name: 'Editar comprovante' })).not.toBeInTheDocument();
      // O desfazer segue disponível: é o caminho para corrigir um ciclo selado.
      expect(screen.getByRole('button', { name: 'Desfazer pagamento' })).toBeInTheDocument();
    });

    it('shows it in a closed cycle that is not sealed yet', async () => {
      mockGetResponses([paid], { status: 'closed', settled: false });
      renderManager();

      expect(await screen.findByRole('button', { name: 'Editar comprovante' })).toBeInTheDocument();
    });

    it('does not show it in a future cycle', async () => {
      mockGetResponses([paid], { status: 'future' });
      renderManager();

      await screen.findByText('Mercado');
      expect(screen.queryByRole('button', { name: 'Editar comprovante' })).not.toBeInTheDocument();
    });
  });

  describe('popup', () => {
    it('opens "Editar comprovante" already filled with the current description and photo, sending nothing yet', async () => {
      const user = userEvent.setup();
      renderManager();

      const dialog = await openEditDialog(user);

      expect(within(dialog).getByText('Editar comprovante')).toBeInTheDocument();
      expect(within(dialog).getByText(/O pagamento continua marcado como pago/)).toBeInTheDocument();
      expect(within(dialog).getByLabelText('Descrição')).toHaveValue('Pix agendado');
      expect(within(dialog).getByRole('link', { name: 'Ver foto atual' })).toHaveAttribute('href', PROOF_URL);
      expect(within(dialog).getByRole('checkbox', { name: 'Remover a foto atual' })).not.toBeChecked();
      expect(within(dialog).getByRole('button', { name: 'Salvar' })).toBeDisabled();
      expect(axios.post).not.toHaveBeenCalled();
    });

    it('opens empty, with no photo link, for a paid expense that had neither', async () => {
      mockGetResponses([{ ...paid, paymentProofUrl: null, paymentNote: null }]);
      const user = userEvent.setup();
      renderManager();

      const dialog = await openEditDialog(user);

      expect(within(dialog).getByLabelText('Descrição')).toHaveValue('');
      expect(within(dialog).queryByRole('link', { name: 'Ver foto atual' })).not.toBeInTheDocument();
      expect(within(dialog).queryByRole('checkbox')).not.toBeInTheDocument();
      expect(within(dialog).getByRole('button', { name: 'Salvar' })).toBeDisabled();
    });

    it('offers "Trocar a foto" when there is a photo and "Selecionar foto" when there is none', async () => {
      const user = userEvent.setup();
      const first = renderManager();

      let dialog = await openEditDialog(user);
      expect(within(dialog).getByText('Trocar a foto')).toBeInTheDocument();
      first.unmount();

      mockGetResponses([{ ...paid, paymentProofUrl: null }]);
      renderManager();
      dialog = await openEditDialog(user);
      expect(within(dialog).getByText('Selecionar foto')).toBeInTheDocument();
      expect(within(dialog).queryByText('Trocar a foto')).not.toBeInTheDocument();
    });

    it('does not interfere with the "Marcar como paga" popup, which still opens blank', async () => {
      mockGetResponses([paid, { ...paid, id: 71, description: 'Aluguel', paid: false, paymentProofUrl: null, paymentNote: null }]);
      const user = userEvent.setup();
      renderManager();

      const dialog = await openEditDialog(user);
      await user.click(within(dialog).getByRole('button', { name: 'Cancelar' }));
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());

      await user.click(screen.getByRole('button', { name: 'Marcar como paga' }));
      const payDialog = await screen.findByRole('dialog');

      expect(within(payDialog).getByText('Confirmar pagamento')).toBeInTheDocument();
      expect(within(payDialog).getByLabelText('Descrição')).toHaveValue('');
      expect(within(payDialog).queryByRole('checkbox')).not.toBeInTheDocument();
      expect(within(payDialog).getByRole('button', { name: 'Confirmar' })).toBeDisabled();
    });

    it('closes without sending anything when it is cancelled', async () => {
      const user = userEvent.setup();
      renderManager();

      const dialog = await openEditDialog(user);
      await user.type(within(dialog).getByLabelText('Descrição'), ' vou desistir');
      await user.click(within(dialog).getByRole('button', { name: 'Cancelar' }));

      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
      expect(axios.post).not.toHaveBeenCalled();
    });
  });

  describe('salvar', () => {
    it('sends only the description as JSON to .../payment-proof when no photo is involved', async () => {
      const user = userEvent.setup();
      renderManager();

      const dialog = await openEditDialog(user);
      await user.type(within(dialog).getByLabelText('Descrição'), ' sexta');
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      const [url, body] = vi.mocked(axios.post).mock.calls[0];
      expect(url).toMatch(/\/api\/expenses\/70\/payment-proof$/);
      expect(body).toEqual({ cycles_ago: 0, descricao: 'Pix agendado sexta' });
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    });

    it('sends the new photo as multipart, keeping the description', async () => {
      const user = userEvent.setup();
      renderManager();

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
      renderManager();

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

    it('sends an empty description when it is cleared and the photo stays', async () => {
      const user = userEvent.setup();
      renderManager();

      const dialog = await openEditDialog(user);
      await user.clear(within(dialog).getByLabelText('Descrição'));
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      expect(vi.mocked(axios.post).mock.calls[0][1]).toEqual({ cycles_ago: 0, descricao: '' });
    });

    it('attaches a first photo to an expense that was paid with neither photo nor description', async () => {
      mockGetResponses([{ ...paid, paymentProofUrl: null, paymentNote: null }]);
      const user = userEvent.setup();
      renderManager();

      const dialog = await openEditDialog(user);
      const file = new File(['x'], 'primeira.jpg', { type: 'image/jpeg' });
      await user.upload(dialog.querySelector('input[type="file"]') as HTMLInputElement, file);
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
      expect(body.get('comprovante')).toBe(file);
      expect(body.get('descricao')).toBe('');
    });

    it('edits the competence being shown (cycles_ago), not always the current one', async () => {
      const user = userEvent.setup();
      renderManager();

      await screen.findByText('Mercado');
      await user.click(screen.getByLabelText('Competência anterior'));
      const dialog = await openEditDialog(user);
      await user.type(within(dialog).getByLabelText('Descrição'), '!');
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      expect((vi.mocked(axios.post).mock.calls[0][1] as { cycles_ago: number }).cycles_ago).toBe(1);
    });

    it('shows "Comprovante atualizado." and reloads the summary on success', async () => {
      const user = userEvent.setup();
      renderManager();

      const dialog = await openEditDialog(user);
      await user.type(within(dialog).getByLabelText('Descrição'), '!');
      const getsBefore = vi.mocked(axios.get).mock.calls.filter(([u]) => String(u).includes('/expenses/summary')).length;
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      expect(await screen.findByText('Comprovante atualizado.')).toBeInTheDocument();
      await waitFor(() =>
        expect(
          vi.mocked(axios.get).mock.calls.filter(([u]) => String(u).includes('/expenses/summary')).length
        ).toBeGreaterThan(getsBefore)
      );
      expect(screen.queryByText('Despesa marcada como paga.')).not.toBeInTheDocument();
    });

    it('shows the API error in the Snackbar and no success message', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {});
      vi.mocked(axios.post).mockRejectedValue({
        response: { data: { error: 'Esta competência já foi encerrada. Desfaça o pagamento para corrigir o comprovante.' } },
      });
      const user = userEvent.setup();
      renderManager();

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
      renderManager();

      const dialog = await openEditDialog(user);
      await user.type(within(dialog).getByLabelText('Descrição'), '!');
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      expect(await screen.findByText('Falha ao atualizar o comprovante.')).toBeInTheDocument();
    });

    it('disables the icon of that expense while the request is in flight', async () => {
      let resolve!: (value: unknown) => void;
      vi.mocked(axios.post).mockReturnValue(new Promise(r => { resolve = r; }));
      const user = userEvent.setup();
      renderManager();

      const dialog = await openEditDialog(user);
      await user.type(within(dialog).getByLabelText('Descrição'), '!');
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      await waitFor(() => expect(editButton()).toBeDisabled());
      resolve({ data: {} });
      await waitFor(() => expect(editButton()).toBeEnabled());
    });
  });

  describe('alvo do popup', () => {
    it('edits the expense whose icon was clicked when there are several', async () => {
      mockGetResponses([
        { ...paid, id: 72, description: 'Aluguel', paymentNote: 'nota do aluguel' },
        { ...paid, id: 73, description: 'Internet', paymentNote: 'nota da internet' },
      ]);
      const user = userEvent.setup();
      renderManager();

      await screen.findByText('Aluguel');
      const internetRow = screen.getByRole('row', { name: /Internet/ });
      await user.click(within(internetRow).getByRole('button', { name: 'Editar comprovante' }));
      const dialog = await screen.findByRole('dialog');

      expect(within(dialog).getByLabelText('Descrição')).toHaveValue('nota da internet');
      await user.type(within(dialog).getByLabelText('Descrição'), '!');
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      expect(vi.mocked(axios.post).mock.calls[0][0]).toMatch(/\/api\/expenses\/73\/payment-proof$/);
    });

    it('switches to the next expense when another icon is clicked after closing the popup', async () => {
      mockGetResponses([
        { ...paid, id: 72, description: 'Aluguel', paymentNote: 'nota do aluguel' },
        { ...paid, id: 73, description: 'Internet', paymentNote: 'nota da internet' },
      ]);
      const user = userEvent.setup();
      renderManager();

      await screen.findByText('Aluguel');
      await user.click(within(screen.getByRole('row', { name: /Aluguel/ })).getByRole('button', { name: 'Editar comprovante' }));
      let dialog = await screen.findByRole('dialog');
      expect(within(dialog).getByLabelText('Descrição')).toHaveValue('nota do aluguel');
      await user.click(within(dialog).getByRole('button', { name: 'Cancelar' }));
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());

      await user.click(within(screen.getByRole('row', { name: /Internet/ })).getByRole('button', { name: 'Editar comprovante' }));
      dialog = await screen.findByRole('dialog');
      expect(within(dialog).getByLabelText('Descrição')).toHaveValue('nota da internet');
      await user.type(within(dialog).getByLabelText('Descrição'), '!');
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      expect(vi.mocked(axios.post).mock.calls[0][0]).toMatch(/\/api\/expenses\/73\/payment-proof$/);
    });

    it('reads the target from the current list: after a reload the popup shows the fresh description', async () => {
      const user = userEvent.setup();
      let current = { ...paid, paymentNote: 'versão antiga' };
      vi.mocked(axios.get).mockImplementation((url: string) => {
        if (url.includes('/expenses/summary')) return Promise.resolve({ data: summaryResponse([current]) });
        if (url.includes('/api/me')) return Promise.resolve({ data: { id: CURRENT_USER_ID } });
        return Promise.reject(new Error(`unexpected GET ${url}`));
      });
      renderManager();

      let dialog = await openEditDialog(user);
      expect(within(dialog).getByLabelText('Descrição')).toHaveValue('versão antiga');
      current = { ...paid, paymentNote: 'versão nova' };
      await user.type(within(dialog).getByLabelText('Descrição'), '!');
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
      await screen.findByText('Comprovante atualizado.');
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
        return Promise.reject(new Error(`unexpected GET ${url}`));
      });
      renderManager();

      await openEditDialog(user);
      list = [];
      await user.click(screen.getByLabelText('Competência anterior'));

      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    });
  });

  describe('texto puro', () => {
    it('shows a description with markup as literal text, never as HTML', async () => {
      mockGetResponses([{ ...paid, paymentNote: '<img src=x onerror="alert(1)"><b>negrito</b>' }]);
      const user = userEvent.setup();
      renderManager();

      const dialog = await openEditDialog(user);

      expect(within(dialog).getByLabelText('Descrição')).toHaveValue('<img src=x onerror="alert(1)"><b>negrito</b>');
      expect(dialog.querySelector('img')).toBeNull();
      expect(dialog.querySelector('b')).toBeNull();
    });
  });
});

describe('ExpenseManager - editar comprovante nos cartões (viewport estreito)', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.stubGlobal(
      'matchMedia',
      vi.fn().mockReturnValue({
        matches: true,
        media: '',
        onchange: null,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        addListener: vi.fn(),
        removeListener: vi.fn(),
        dispatchEvent: vi.fn(),
      })
    );
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  const paidCard: SummaryExpenseFixture = {
    id: 80,
    description: 'Internet',
    value: 120,
    date: '2026-08-05',
    payerName: 'Isac',
    paid: true,
    isFixed: false,
    paymentProofUrl: 'https://api.exemplo.com/api/proofs/3?signature=zzz',
    paymentNote: 'Pix',
  };

  it('shows "Editar comprovante" on the card, before "Desfazer pagamento", and edits from it', async () => {
    mockGetResponses([paidCard]);
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Internet');
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
    const edit = screen.getByRole('button', { name: 'Editar comprovante' });
    const undo = screen.getByRole('button', { name: 'Desfazer pagamento' });
    expect(edit.compareDocumentPosition(undo) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

    await user.click(edit);
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByLabelText('Descrição')).toHaveValue('Pix');
    await user.type(within(dialog).getByLabelText('Descrição'), ' ok');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
    const [url, body] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toMatch(/\/api\/expenses\/80\/payment-proof$/);
    expect(body).toEqual({ cycles_ago: 0, descricao: 'Pix ok' });
    expect(await screen.findByText('Comprovante atualizado.')).toBeInTheDocument();
  });

  it('does not show it on a card in a sealed cycle', async () => {
    mockGetResponses([paidCard], { status: 'closed', settled: true });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Internet');
    expect(screen.queryByRole('button', { name: 'Editar comprovante' })).not.toBeInTheDocument();
  });
});

describe('ExpenseManager - fluxo completo (grid, pagar, despagar, excluir)', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.delete).mockReset();
  });

  it('renders the grid with balances, transitions action icons through pay/unpay, then deletes the expense', async () => {
    const user = userEvent.setup();

    let paid = false;
    let deleted = false;

    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/expenses/summary')) {
        const expensesList: SummaryExpenseFixture[] = deleted
          ? []
          : [
              {
                id: 50,
                description: 'Mercado',
                value: 300,
                date: '2026-08-10',
                payerName: 'Isac',
                paid,
                isFixed: false,
                userPayerId: CURRENT_USER_ID,
                userCreatorId: CURRENT_USER_ID,
              },
            ];
        return Promise.resolve({ data: summaryResponse(expensesList) });
      }
      if (url.includes('/api/me')) {
        return Promise.resolve({ data: { id: CURRENT_USER_ID } });
      }
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });

    vi.mocked(axios.post).mockImplementation((url: string) => {
      if (url.includes('/pay')) {
        paid = true;
        return Promise.resolve({ data: {} });
      }
      if (url.includes('/unpay')) {
        paid = false;
        return Promise.resolve({ data: {} });
      }
      return Promise.reject(new Error(`unexpected POST ${url}`));
    });

    vi.mocked(axios.delete).mockImplementation(() => {
      deleted = true;
      return Promise.resolve({ data: {} });
    });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    // Grid: listagem e o painel lateral (SummarySidePanel) juntos na mesma tela.
    expect(await screen.findByText('Mercado')).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Saldo' })).toBeInTheDocument();

    // Pendente: pode marcar como paga; "Desfazer pagamento" ainda não existe.
    expect(screen.getByRole('button', { name: 'Marcar como paga' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Desfazer pagamento' })).not.toBeInTheDocument();

    await payThroughDialog(user);
    await waitFor(() => expect(screen.getByText('Paga')).toBeInTheDocument());

    // Paga: "Excluir"/"Marcar como paga" somem, "Desfazer pagamento" aparece.
    expect(screen.queryByRole('button', { name: 'Excluir despesa' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Marcar como paga' })).not.toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'Desfazer pagamento' })).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Desfazer pagamento' }));
    await waitFor(() => expect(screen.getByText('Pendente')).toBeInTheDocument());

    // Pendente de novo: "Excluir despesa" volta a existir.
    expect(await screen.findByRole('button', { name: 'Excluir despesa' })).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Excluir despesa' }));
    await screen.findByText('Excluir despesa');
    await user.click(screen.getByRole('button', { name: 'Excluir' }));

    await waitFor(() => {
      expect(screen.getByText('Nenhuma despesa encontrada para esta competência.')).toBeInTheDocument();
    });
  });
});

describe('ExpenseManager - aba "À pagar" do painel lateral', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
  });

  it('shows real settlement data from this page when switching to the "À pagar" tab', async () => {
    const user = userEvent.setup();

    mockGetResponses([], {}, {
      balances: [
        { user_id: 533, name: 'QA Despesas', balance: 550 },
        { user_id: 534, name: 'QA Membro 2', balance: -550 },
      ],
      settlements: [{ from_user_id: 534, to_user_id: 533, amount: 550 }],
    });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByRole('tab', { name: 'Saldo' });

    await user.click(screen.getByRole('tab', { name: 'À pagar' }));

    expect(screen.getByText('QA Membro 2')).toBeInTheDocument();
    expect(screen.getByText('QA Despesas')).toBeInTheDocument();
    expect(screen.getByText('R$ 550,00')).toBeInTheDocument();
    expect(screen.getByText(/deve pagar/)).toBeInTheDocument();
  });
});

describe('ExpenseManager - modal de detalhes', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.delete).mockReset();
  });

  it('shows description, type, status, value, date, credor and pagadores, and closes without navigating or changing data', async () => {
    mockGetResponses([
      {
        id: 60,
        description: 'Internet',
        value: 120,
        date: '2026-08-05',
        payerName: 'Isac',
        participants: ['Isac', 'Maria'],
        paid: false,
        isFixed: true,
      },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Internet');
    await userEvent.click(screen.getByRole('button', { name: 'Ver detalhes' }));

    expect(await screen.findByText('Detalhes da despesa')).toBeInTheDocument();
    const modal = within(screen.getByRole('dialog'));
    expect(modal.getByText('R$ 120,00')).toBeInTheDocument();
    expect(modal.getByText(/05\/08\/2026/)).toBeInTheDocument();
    expect(modal.getByText('Credor: Isac')).toBeInTheDocument();
    // Pagadores agora saem em uma linha por pessoa (antes: nomes concatenados).
    // Este fixture não traz valuePerPerson — como um snapshot selado antigo —,
    // então a lista aparece só com os nomes, sem quebrar o modal.
    expect(modal.getByText('Maria')).toBeInTheDocument();
    expect(modal.getAllByText('Isac').length).toBeGreaterThan(0);
    expect(modal.queryByText('Isac, Maria')).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Fechar' }));

    await waitFor(() => {
      expect(screen.queryByText('Detalhes da despesa')).not.toBeInTheDocument();
    });
    expect(navigateMock).not.toHaveBeenCalled();
    expect(axios.post).not.toHaveBeenCalled();
    expect(axios.delete).not.toHaveBeenCalled();
  });
});

// Viewport < sm: a tabela de 6 colunas dá lugar a uma lista de cartões (F1 /
// docs/feature/concluidas/202609/20260901-usabilidade-mobile). Os testes acima já são
// agnósticos de layout (consultam por texto/role acessível) e cobrem o ramo
// tabela via o default matches:false do polyfill de setupTests.ts.
describe('ExpenseManager - viewport estreito (cartões)', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    vi.stubGlobal(
      'matchMedia',
      vi.fn().mockReturnValue({
        matches: true,
        media: '',
        onchange: null,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        addListener: vi.fn(),
        removeListener: vi.fn(),
        dispatchEvent: vi.fn(),
      })
    );
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('renders expense cards instead of a table', async () => {
    mockGetResponses([
      { id: 9, description: 'Aluguel', value: 1200, date: '2026-08-01', payerName: 'Isac', isFixed: true },
      { id: 10, description: 'Mercado', value: 150, date: '2026-08-05', payerName: 'João', isFixed: false },
    ]);

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    expect(await screen.findByText('Aluguel')).toBeInTheDocument();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Aluguel/ })).toHaveAttribute('href', '/groups/1/expenses/9');
    expect(screen.getByText('Valor: R$ 1.200,00')).toBeInTheDocument();
    expect(screen.getByText('João')).toBeInTheDocument();
  });

  it('keeps the row actions working from the card', async () => {
    mockGetResponses([
      { id: 20, description: 'Internet', value: 120, date: '2026-08-05', payerName: 'Isac', isFixed: false },
    ]);
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Internet');

    // despesa própria em ciclo aberto -> editar disponível, apontando para a rota certa
    expect(screen.getByRole('link', { name: 'Editar despesa' })).toHaveAttribute('href', '/groups/1/expenses/20');

    await user.click(screen.getByRole('button', { name: 'Ver detalhes' }));
    expect(await screen.findByText('Detalhes da despesa')).toBeInTheDocument();
  });

  it('opens the proof popup from the card ✓ too and sends nothing until it is confirmed', async () => {
    mockGetResponses([
      { id: 21, description: 'Internet', value: 120, date: '2026-08-05', payerName: 'Isac', isFixed: false },
    ]);
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Internet');
    expect(screen.queryByRole('table')).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Marcar como paga' }));
    const dialog = await screen.findByRole('dialog');
    expect(axios.post).not.toHaveBeenCalled();

    await user.type(within(dialog).getByLabelText('Descrição'), 'Pago no cartão');
    await user.click(within(dialog).getByRole('button', { name: 'Confirmar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
    const [url, body] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/expenses/21/pay');
    expect(body).toEqual({ cycles_ago: 0, descricao: 'Pago no cartão' });
  });
});

describe('ExpenseManager - aviso de fechamento depois de pagar em ciclo fechado', () => {
  // docs/bugfix/20261024-alerta-ciclo-fechado-apos-pagamento: o aviso lista quem tem acerto sem
  // confirmação e acompanha o resumo recarregado da competência que está na tela.
  const unpaid: SummaryExpenseFixture = {
    id: 70,
    description: 'Aluguel',
    value: 300,
    date: '2026-07-10',
    payerName: 'Carla',
    userPayerId: CURRENT_USER_ID,
    userCreatorId: CURRENT_USER_ID,
  };
  const closedCycle = { start: '2026-07-01', end: '2026-07-31', closes_at: '2026-08-05', status: 'closed' };
  const balances = [
    { user_id: 1, name: 'Ana', balance: -100 },
    { user_id: 2, name: 'Bruno', balance: -100 },
    { user_id: 3, name: 'Carla', balance: 200 },
  ];
  const settlement = (from: number, confirmed: boolean) => ({
    from_user_id: from,
    to_user_id: 3,
    amount: 100,
    confirmedProofUrl: confirmed ? 'https://example.test/comprovante.jpg' : null,
    confirmedAt: confirmed ? '2026-10-20T10:00:00-03:00' : null,
  });

  const summaryCalls = () =>
    vi.mocked(axios.get).mock.calls.filter(([url]) => String(url).includes('/expenses/summary'));

  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
  });

  it('refetches the same cycle after paying and drops the debtor confirmed in the new summary', async () => {
    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/expenses/focus-cycle')) {
        return Promise.resolve({ data: { cycles_ago: 1 } });
      }
      if (url.includes('/expenses/summary')) {
        // 1ª leitura: Ana e Bruno devem; na 2ª (depois do pagamento) o acerto da Ana já está confirmado.
        const settlements =
          summaryCalls().length === 1
            ? [settlement(1, false), settlement(2, false)]
            : [settlement(1, true), settlement(2, false)];
        return Promise.resolve({
          data: summaryResponse([unpaid], closedCycle, { balances, settlements, totals: { total: 300, paid: 0, pending: 300 } }),
        });
      }
      if (url.includes('/api/me')) {
        return Promise.resolve({ data: { id: CURRENT_USER_ID } });
      }
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    expect(await screen.findByText(/falta acertar: Ana, Bruno\./)).toBeInTheDocument();

    await payThroughDialog(user);

    await waitFor(() => expect(screen.getByText(/falta acertar: Bruno\./)).toBeInTheDocument());
    expect(screen.queryByText(/falta acertar: Ana/)).not.toBeInTheDocument();

    // O pagamento e o refetch miram a competência navegada (cycles_ago 1), não a vigente.
    expect((vi.mocked(axios.post).mock.calls[0][1] as { cycles_ago: number }).cycles_ago).toBe(1);
    const calls = summaryCalls();
    expect(calls).toHaveLength(2);
    expect((calls[1][1] as { params: { cycles_ago: number } }).params.cycles_ago).toBe(1);
  });

  it('shows no closing alert when every settlement is confirmed, even with expenses still pending', async () => {
    mockGetResponses([unpaid], closedCycle, {
      balances,
      settlements: [settlement(1, true), settlement(2, true)],
      totals: { total: 300, paid: 0, pending: 300 },
    });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText('Aluguel');
    expect(screen.queryByText(/falta acertar/)).not.toBeInTheDocument();
  });
});

describe('ExpenseManager - ordem e data das despesas', () => {
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
  const cycle = { start: '2026-09-06', end: '2026-10-05' };
  const NAMES = /^(Sofá 3\/6|Mercado|Luz|Internet)$/;

  const renderManager = () =>
    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

  // Descrições na ordem em que aparecem no DOM (cada despesa tem um link com o nome dela).
  const shownOrder = () => screen.getAllByRole('link', { name: NAMES }).map(link => link.textContent);

  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
  });

  describe('tabela (desktop)', () => {
    it('ordena por data da ocorrência decrescente, desempata pelo id e não prioriza pendentes', async () => {
      mockGetResponses(doBackend, cycle);
      renderManager();

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
      mockGetResponses([...doBackend].sort((a, b) => a.date.localeCompare(b.date) || a.id - b.id), cycle);
      renderManager();

      await screen.findByText('Mercado');

      expect(shownOrder()).toEqual(['Mercado', 'Internet', 'Sofá 3/6', 'Luz']);
    });

    it('não altera a lista recebida da API (o resumo é estado do hook)', async () => {
      vi.mocked(axios.get).mockImplementation((url: string) => {
        if (url.includes('/expenses/summary')) {
          const data = summaryResponse(doBackend, cycle);
          Object.freeze(data.expenses);
          return Promise.resolve({ data });
        }
        if (url.includes('/api/me')) return Promise.resolve({ data: { id: CURRENT_USER_ID } });
        return Promise.reject(new Error(`unexpected GET ${url}`));
      });
      renderManager();

      await screen.findByText('Mercado');

      expect(shownOrder()).toEqual(['Mercado', 'Internet', 'Sofá 3/6', 'Luz']);
    });

    it('mantém a ordem com um filtro ativo', async () => {
      const user = userEvent.setup();
      mockGetResponses(doBackend, cycle);
      renderManager();

      await screen.findByText('Mercado');
      await user.click(within(screen.getByRole('group', { name: 'Status' })).getByRole('button', { name: 'Pendentes' }));

      expect(shownOrder()).toEqual(['Mercado', 'Sofá 3/6']);
    });

    it('tem a coluna "Data" como primeira, com o dia e o mês de cada despesa', async () => {
      mockGetResponses(doBackend, cycle);
      renderManager();

      await screen.findByText('Mercado');

      expect(screen.getAllByRole('columnheader')[0]).toHaveTextContent('Data');
      const mercadoRow = screen.getByRole('link', { name: 'Mercado' }).closest('tr') as HTMLElement;
      expect(within(mercadoRow).getByText(/^03\b.*out/i)).toBeInTheDocument();
      const luzRow = screen.getByRole('link', { name: 'Luz' }).closest('tr') as HTMLElement;
      expect(within(luzRow).getByText(/^12\b.*set/i)).toBeInTheDocument();
    });

    it('renderiza duas parcelas da mesma despesa sem aviso de chave duplicada', async () => {
      const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
      mockGetResponses(
        [
          {
            id: 40,
            description: 'Geladeira',
            value: 200,
            date: '2026-10-03',
            payerName: 'Isac',
            expenseType: 'IN_INSTALLMENTS',
            installmentNumber: 1,
            installmentsTotal: 6,
          },
          {
            id: 40,
            description: 'Geladeira',
            value: 200,
            date: '2026-10-03',
            payerName: 'Isac',
            expenseType: 'IN_INSTALLMENTS',
            installmentNumber: 2,
            installmentsTotal: 6,
          },
        ],
        cycle
      );
      renderManager();

      await screen.findAllByText('Geladeira');

      expect(screen.getAllByText('Geladeira')).toHaveLength(2);
      expect(consoleError.mock.calls.flat().join(' ')).not.toMatch(/same key/i);
      consoleError.mockRestore();
    });
  });

  describe('cards (viewport estreito)', () => {
    beforeEach(() => {
      vi.stubGlobal(
        'matchMedia',
        vi.fn().mockReturnValue({
          matches: true,
          media: '',
          onchange: null,
          addEventListener: vi.fn(),
          removeEventListener: vi.fn(),
          addListener: vi.fn(),
          removeListener: vi.fn(),
          dispatchEvent: vi.fn(),
        })
      );
    });

    afterEach(() => {
      vi.unstubAllGlobals();
    });

    it('ordena os cards pela mesma regra da tabela', async () => {
      mockGetResponses(doBackend, cycle);
      renderManager();

      await screen.findByText('Mercado');

      expect(screen.queryByRole('table')).not.toBeInTheDocument();
      expect(shownOrder()).toEqual(['Mercado', 'Internet', 'Sofá 3/6', 'Luz']);
    });

    it('mostra a linha "Data:" em cada card', async () => {
      mockGetResponses(doBackend, cycle);
      renderManager();

      await screen.findByText('Mercado');

      const mercadoCard = screen.getByRole('link', { name: 'Mercado' }).closest('[class*="MuiCard-root"]') as HTMLElement;
      expect(within(mercadoCard).getByText(/Data:\s*03\b.*out/i)).toBeInTheDocument();
      const sofaCard = screen.getByRole('link', { name: 'Sofá 3/6' }).closest('[class*="MuiCard-root"]') as HTMLElement;
      expect(within(sofaCard).getByText(/Data:\s*20\b.*set/i)).toBeInTheDocument();
    });
  });
});

describe('ExpenseManager - aba Saldo com o usuário logado primeiro', () => {
  // Isac (500) é o usuário logado do mock de /api/me e tem o menor saldo.
  const balances = [
    { user_id: 501, name: 'Bia', balance: 100 },
    { user_id: 502, name: 'Caio', balance: 60 },
    { user_id: 500, name: 'Isac', balance: -160 },
  ];

  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
  });

  it('abre o Saldo com o usuário logado primeiro e os demais por saldo decrescente', async () => {
    mockGetResponses([], {}, { balances });

    render(
      <MemoryRouter>
        <ExpenseManager />
      </MemoryRouter>
    );

    await screen.findByText(/R\$ 100,00 a receber/);
    await waitFor(() =>
      expect(screen.getAllByText(/^(Bia|Caio|Isac)$/).map(el => el.textContent)).toEqual(['Isac', 'Bia', 'Caio'])
    );
  });
});
