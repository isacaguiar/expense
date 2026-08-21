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

const groups = [
  { id: 1, name: 'Viagem SP', description: 'Grupo da viagem', create_date: '2026-01-01' },
  { id: 2, name: 'Casa', description: 'Contas da casa', create_date: '2026-01-01' },
];

describe('Dashboard', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
  });

  it('redirects to login when the groups request returns 401', async () => {
    vi.mocked(axios.get).mockRejectedValueOnce({
      response: { status: 401 },
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
    vi.mocked(axios.get).mockResolvedValueOnce({ data: groups });
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
    vi.mocked(axios.get).mockResolvedValueOnce({ data: [] });

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    expect(await screen.findByText('Você ainda não participa de nenhum grupo.')).toBeInTheDocument();
  });

  it('navigates to group edit/members/expenses from the card actions', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({ data: groups });
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
    vi.mocked(axios.get).mockResolvedValueOnce({ data: groups });

    render(
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    );

    expect(await screen.findByRole('link', { name: /Viagem SP/ })).toHaveAttribute('href', '/groups/1/summary');
  });
});
