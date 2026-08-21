import { render, screen, waitFor } from '@testing-library/react';
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

type Group = {
  id: number;
  name: string;
  description: string;
  create_date: string;
  created_by: number | null;
  creator?: { id: number; email: string } | null;
};

const groups: Group[] = [
  { id: 1, name: 'Viagem SP', description: 'Grupo da viagem', create_date: '2026-01-01', created_by: 10, creator: { id: 10, email: 'dono@example.com' } },
  { id: 2, name: 'Casa', description: 'Contas da casa', create_date: '2026-01-01', created_by: 99, creator: { id: 99, email: 'outro@example.com' } },
];

function mockGroupsAndMe(groupsData: Group[], meId = 10) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/api/me')) return Promise.resolve({ data: { id: meId } });
    return Promise.resolve({ data: groupsData });
  });
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

  it('renders a card per group and filters by search', async () => {
    mockGroupsAndMe(groups);
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    expect(await screen.findByText('Viagem SP')).toBeInTheDocument();
    expect(screen.getByText('Casa')).toBeInTheDocument();

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

  it('navigates to group edit/members/expenses from the card actions', async () => {
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

  it('links the group card to its summary page', async () => {
    mockGroupsAndMe(groups);

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    expect(await screen.findByRole('link', { name: /Viagem SP/ })).toHaveAttribute('href', '/groups/1/summary');
  });

  it('shows the responsible person on each card', async () => {
    mockGroupsAndMe(groups);

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    expect(await screen.findByText('Responsável: dono@example.com')).toBeInTheDocument();
    expect(screen.getByText('Responsável: outro@example.com')).toBeInTheDocument();
  });

  it('falls back to a dash when the group has no creator recorded', async () => {
    mockGroupsAndMe([{ id: 3, name: 'Grupo antigo', description: '', create_date: '2026-01-01', created_by: null, creator: null }]);

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    expect(await screen.findByText('Responsável: —')).toBeInTheDocument();
  });

  it('disables "Novo grupo" when the current user already created 3 groups', async () => {
    const threeOwnGroups = [
      { id: 1, name: 'Grupo 1', description: '', create_date: '2026-01-01', created_by: 10, creator: { id: 10, email: 'eu@example.com' } },
      { id: 2, name: 'Grupo 2', description: '', create_date: '2026-01-01', created_by: 10, creator: { id: 10, email: 'eu@example.com' } },
      { id: 3, name: 'Grupo 3', description: '', create_date: '2026-01-01', created_by: 10, creator: { id: 10, email: 'eu@example.com' } },
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
});
