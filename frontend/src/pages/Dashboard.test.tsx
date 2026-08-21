import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
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
  },
  {
    id: 2,
    name: 'Casa',
    description: 'Contas da casa',
    create_date: '2026-01-01',
    created_by: 99,
    creator: { id: 99, email: 'outro@example.com' },
    members: [{ id: 99, name: 'Outro', email: 'outro@example.com' }],
  },
];

function mockGroupsAndMe(groupsData: Group[], meId = 10) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/api/me')) return Promise.resolve({ data: { id: meId } });
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
    mockGroupsAndMe([{ id: 3, name: 'Grupo antigo', description: '', create_date: '2026-01-01', created_by: null, creator: null, members: [] }]);

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
      { id: 1, name: 'Grupo 1', description: '', create_date: '2026-01-01', created_by: 10, creator: { id: 10, email: 'eu@example.com' }, members: [] },
      { id: 2, name: 'Grupo 2', description: '', create_date: '2026-01-01', created_by: 10, creator: { id: 10, email: 'eu@example.com' }, members: [] },
      { id: 3, name: 'Grupo 3', description: '', create_date: '2026-01-01', created_by: 10, creator: { id: 10, email: 'eu@example.com' }, members: [] },
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

  it('deletes a group after confirming in the dialog', async () => {
    mockGroupsAndMe(groups);
    vi.mocked(axios.delete).mockResolvedValue({ data: { message: 'ok' } });
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
    expect(await screen.findByText('Grupo excluído com sucesso.')).toBeInTheDocument();
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
});
