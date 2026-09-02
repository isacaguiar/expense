import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Dashboard from './Dashboard';

const navigateMock = vi.fn();

vi.mock('axios');

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => navigateMock,
  };
});

type Member = { id: number; name: string; email: string };

type Group = {
  id: number;
  name: string;
  description: string;
  create_date: string;
  created_by: number | null;
  creator?: { id: number; email: string } | null;
  members: Member[];
  cycle_snapshots_exists: boolean;
};

const groups: Group[] = [
  {
    id: 1,
    name: 'Viagem SP',
    description: 'Grupo da viagem',
    create_date: '2026-01-01',
    created_by: 10,
    creator: { id: 10, email: 'dono@example.com' },
    members: [{ id: 10, name: 'Dono', email: 'dono@example.com' }, { id: 11, name: 'Ana Silva', email: 'ana@example.com' }],
    cycle_snapshots_exists: false,
  },
  {
    id: 2,
    name: 'Casa',
    description: 'Contas da casa',
    create_date: '2026-01-01',
    created_by: 99,
    creator: { id: 99, email: 'outro@example.com' },
    members: [{ id: 99, name: 'Outro', email: 'outro@example.com' }],
    cycle_snapshots_exists: false,
  },
];

type GrossDebtsByGroup = Record<number, { cycle: { start: string; end: string; status: string }; creditors: unknown[] }>;

function mockGroupsAndMe(groupsData: Group[], meId = 10, grossDebtsByGroup: GrossDebtsByGroup = {}) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/api/me')) return Promise.resolve({ data: { id: meId } });
    const grossDebtsMatch = url.match(/\/groups\/(\d+)\/expenses\/gross-debts/);
    if (grossDebtsMatch) {
      const groupId = Number(grossDebtsMatch[1]);
      return Promise.resolve({
        data: grossDebtsByGroup[groupId] ?? { cycle: { start: '2026-08-01', end: '2026-08-31', status: 'open' }, creditors: [] }
      });
    }
    return Promise.resolve({ data: groupsData });
  });
}

function rowFor(groupName: string): HTMLElement {
  return screen.getByText(groupName).closest('tr') as HTMLElement;
}

describe('Dashboard', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
  });

  it('redirects to login when the groups request returns 401', async () => {
    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/api/me')) return Promise.resolve({ data: { id: 1 } });
      return Promise.reject({ response: { status: 401 } });
    });

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(navigateMock).toHaveBeenCalledWith('/', { replace: true });
    });
  });

  it('renders a table row per group and filters by search', async () => {
    mockGroupsAndMe(groups);
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    expect(await screen.findByText('Viagem SP')).toBeInTheDocument();
    expect(screen.getByText('Casa')).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: 'Nome' })).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: 'Responsável' })).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: 'Integrantes' })).toBeInTheDocument();

    await user.type(screen.getByLabelText('Buscar grupo'), 'via');

    expect(screen.getByText('Viagem SP')).toBeInTheDocument();
    expect(screen.queryByText('Casa')).not.toBeInTheDocument();
  });

  it('shows the empty state when the user has no groups', async () => {
    mockGroupsAndMe([]);

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    expect(await screen.findByText('Você ainda não participa de nenhum grupo.')).toBeInTheDocument();
  });

  it('navigates to group edit/members/expenses from the row actions', async () => {
    mockGroupsAndMe(groups);
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    await screen.findByText('Viagem SP');

    await user.click(screen.getAllByLabelText('Editar grupo')[0]);
    expect(navigateMock).toHaveBeenCalledWith('/groups/1/edit');

    await user.click(screen.getAllByLabelText('Participantes')[0]);
    expect(navigateMock).toHaveBeenCalledWith('/groups/1/members');

    await user.click(screen.getAllByLabelText('Despesas')[0]);
    expect(navigateMock).toHaveBeenCalledWith('/groups/1/expenses');
  });

  it('links the group name to its summary page', async () => {
    mockGroupsAndMe(groups);

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    expect(await screen.findByRole('link', { name: 'Viagem SP' })).toHaveAttribute('href', '/groups/1/summary');
  });

  it('shows the responsible person for each group', async () => {
    mockGroupsAndMe(groups);

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    await screen.findByText('Viagem SP');

    expect(within(rowFor('Viagem SP')).getByText('dono@example.com')).toBeInTheDocument();
    expect(within(rowFor('Casa')).getByText('outro@example.com')).toBeInTheDocument();
  });

  it('shows an avatar with initials per member', async () => {
    mockGroupsAndMe(groups);

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    await screen.findByText('Viagem SP');

    const row = rowFor('Viagem SP');
    expect(within(row).getByText('D')).toBeInTheDocument();
    expect(within(row).getByText('A')).toBeInTheDocument();
  });

  it('falls back to a dash when the group has no creator recorded', async () => {
    mockGroupsAndMe([{ id: 3, name: 'Grupo antigo', description: '', create_date: '2026-01-01', created_by: null, creator: null, members: [], cycle_snapshots_exists: false }]);

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    await screen.findByText('Grupo antigo');

    expect(within(rowFor('Grupo antigo')).getByText('—')).toBeInTheDocument();
  });

  it('disables "Novo grupo" when the current user already created 3 groups', async () => {
    const threeOwnGroups: Group[] = [
      { id: 1, name: 'Grupo 1', description: '', create_date: '2026-01-01', created_by: 10, creator: { id: 10, email: 'eu@example.com' }, members: [], cycle_snapshots_exists: false },
      { id: 2, name: 'Grupo 2', description: '', create_date: '2026-01-01', created_by: 10, creator: { id: 10, email: 'eu@example.com' }, members: [], cycle_snapshots_exists: false },
      { id: 3, name: 'Grupo 3', description: '', create_date: '2026-01-01', created_by: 10, creator: { id: 10, email: 'eu@example.com' }, members: [], cycle_snapshots_exists: false },
    ];
    mockGroupsAndMe(threeOwnGroups, 10);

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    await screen.findByText('Grupo 1');

    expect(screen.getByRole('link', { name: 'Novo grupo' })).toHaveAttribute('aria-disabled', 'true');
  });

  it('keeps "Novo grupo" enabled when the user created fewer than 3 groups', async () => {
    mockGroupsAndMe(groups, 10);

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    await screen.findByText('Viagem SP');

    expect(screen.getByRole('link', { name: 'Novo grupo' })).not.toHaveAttribute('aria-disabled', 'true');
  });

  it('deletes a group after confirming in the dialog, showing the backend message', async () => {
    mockGroupsAndMe(groups);
    vi.mocked(axios.delete).mockResolvedValue({ data: { message: 'Grupo excluído permanentemente.' } });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    await screen.findByText('Viagem SP');

    await user.click(screen.getAllByLabelText('Excluir grupo')[0]);

    expect(await screen.findByText('Excluir grupo')).toBeInTheDocument();
    expect(screen.getByText('Tem certeza que deseja excluir o grupo "Viagem SP"?')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Excluir' }));

    expect(axios.delete).toHaveBeenCalledWith(expect.stringContaining('/api/groups/1'), expect.anything());
    await waitFor(() => {
      expect(screen.queryByText('Viagem SP')).not.toBeInTheDocument();
    });
    expect(await screen.findByText('Grupo excluído permanentemente.')).toBeInTheDocument();
  });

  it('warns about irreversible physical deletion when the group has no closed cycle', async () => {
    mockGroupsAndMe(groups);
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    await screen.findByText('Viagem SP');

    await user.click(screen.getAllByLabelText('Excluir grupo')[0]);

    expect(
      await screen.findByText(/Esta ação é irreversível: o grupo e todas as despesas, participações e fechamentos associados serão apagados permanentemente\./)
    ).toBeInTheDocument();
  });

  it('warns that history is preserved when the group has a closed cycle', async () => {
    mockGroupsAndMe([{ ...groups[0], cycle_snapshots_exists: true }, groups[1]]);
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    await screen.findByText('Viagem SP');

    await user.click(screen.getAllByLabelText('Excluir grupo')[0]);

    expect(
      await screen.findByText(/O histórico deste grupo \(despesas, participantes e fechamentos\) será preservado\./)
    ).toBeInTheDocument();
  });

  it('cancels the delete dialog without calling the API', async () => {
    mockGroupsAndMe(groups);
    vi.mocked(axios.delete).mockReset();
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    await screen.findByText('Viagem SP');

    await user.click(screen.getAllByLabelText('Excluir grupo')[0]);
    await user.click(screen.getByRole('button', { name: 'Cancelar' }));

    expect(axios.delete).not.toHaveBeenCalled();
    expect(screen.getByText('Viagem SP')).toBeInTheDocument();
  });

  it('expands a row to show the gross debts panel, and collapses it back', async () => {
    mockGroupsAndMe(groups, 10, {
      1: {
        cycle: { start: '2026-08-01', end: '2026-08-31', status: 'open' },
        creditors: [{ creditor: { id: 10, name: 'Dono', email: 'dono@example.com', pix: null }, debtors: [{ id: 11, name: 'Ana Silva', amount: 50 }] }],
      },
    });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    await screen.findByText('Viagem SP');
    expect(screen.queryByText('Ana Silva')).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Ver pendências de Viagem SP' }));

    expect(await screen.findByText('Ana Silva')).toBeInTheDocument();
    expect(screen.getByText('R$ 50,00')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Recolher pendências de Viagem SP' }));

    await waitFor(() => {
      expect(screen.queryByText('Ana Silva')).not.toBeInTheDocument();
    });
  });

  it('keeps two expanded rows independent from each other', async () => {
    mockGroupsAndMe(groups, 10, {
      1: {
        cycle: { start: '2026-08-01', end: '2026-08-31', status: 'open' },
        creditors: [{ creditor: { id: 10, name: 'Dono', email: 'dono@example.com', pix: null }, debtors: [{ id: 11, name: 'Ana Silva', amount: 50 }] }],
      },
      2: {
        cycle: { start: '2026-08-01', end: '2026-08-31', status: 'open' },
        creditors: [{ creditor: { id: 99, name: 'Outro', email: 'outro@example.com', pix: null }, debtors: [{ id: 12, name: 'Beto Souza', amount: 75 }] }],
      },
    });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    await screen.findByText('Viagem SP');

    await user.click(screen.getByRole('button', { name: 'Ver pendências de Viagem SP' }));
    await user.click(screen.getByRole('button', { name: 'Ver pendências de Casa' }));

    expect(await screen.findByText('Ana Silva')).toBeInTheDocument();
    expect(await screen.findByText('Beto Souza')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Recolher pendências de Viagem SP' }));

    await waitFor(() => {
      expect(screen.queryByText('Ana Silva')).not.toBeInTheDocument();
    });
    expect(screen.getByText('Beto Souza')).toBeInTheDocument();
  });
});

// Viewport < sm: a tabela dá lugar a uma lista de cartões (F1).
function stubNarrowViewport() {
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
}

describe('Dashboard (mobile / compact)', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    stubNarrowViewport();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('renders groups as cards instead of a table below the sm breakpoint', async () => {
    mockGroupsAndMe(groups);

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    expect(await screen.findByText('Viagem SP')).toBeInTheDocument();
    expect(screen.getByText('Casa')).toBeInTheDocument();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
    expect(screen.queryByRole('columnheader', { name: 'Nome' })).not.toBeInTheDocument();
    expect(screen.getByText('Responsável: dono@example.com')).toBeInTheDocument();
  });

  it('keeps the row actions working from the card footer', async () => {
    mockGroupsAndMe(groups);
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    await screen.findByText('Viagem SP');

    expect(screen.getByRole('link', { name: 'Viagem SP' })).toHaveAttribute('href', '/groups/1/summary');

    await user.click(screen.getAllByLabelText('Editar grupo')[0]);
    expect(navigateMock).toHaveBeenCalledWith('/groups/1/edit');

    await user.click(screen.getAllByLabelText('Excluir grupo')[0]);
    expect(await screen.findByText('Tem certeza que deseja excluir o grupo "Viagem SP"?')).toBeInTheDocument();
  });

  it('expands a card to show the gross debts panel', async () => {
    mockGroupsAndMe(groups, 10, {
      1: {
        cycle: { start: '2026-08-01', end: '2026-08-31', status: 'open' },
        creditors: [{ creditor: { id: 10, name: 'Dono', email: 'dono@example.com', pix: null }, debtors: [{ id: 11, name: 'Ana Silva', amount: 50 }] }],
      },
    });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    await screen.findByText('Viagem SP');
    expect(screen.queryByText('Ana Silva')).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Ver pendências de Viagem SP' }));

    expect(await screen.findByText('Ana Silva')).toBeInTheDocument();
  });
});
