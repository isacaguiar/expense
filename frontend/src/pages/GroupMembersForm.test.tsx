import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GroupMembersForm from './GroupMembersForm';

vi.mock('axios');

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => vi.fn(),
    useParams: () => ({ id: '1' }),
  };
});

const group = { id: 1, name: 'Casa dos Amigos', description: 'Contas da casa', creator: { id: 5, email: 'dono@example.com' } };
const members = [
  { id: 1, email: 'ana@example.com' },
  { id: 2, email: 'bruno@example.com' },
];

describe('GroupMembersForm', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
  });

  it('renders the group name and a member list with an avatar per member', async () => {
    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/members')) return Promise.resolve({ data: members });
      return Promise.resolve({ data: group });
    });

    render(
      <MemoryRouter>
        <GroupMembersForm />
      </MemoryRouter>
    );

    expect(await screen.findByText('Casa dos Amigos')).toBeInTheDocument();
    expect(screen.getByText('ana@example.com')).toBeInTheDocument();
    expect(screen.getByText('bruno@example.com')).toBeInTheDocument();
    expect(screen.getByText('Responsável: dono@example.com')).toBeInTheDocument();
  });

  // Convite pendente (feature 20261103-convite-pendente-no-grupo): o POST cria um convite, não um
  // membro. A tela avisa que o convite foi enviado, mostra o convite novo em "Convites pendentes"
  // e não relê a lista de membros, que não mudou.
  it('invites a new member by email, says the invitation was sent and reloads only the pending invitations', async () => {
    let invitations: unknown[] = [];
    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.endsWith('/invitations')) return Promise.resolve({ data: invitations });
      if (url.includes('/members')) return Promise.resolve({ data: members });
      return Promise.resolve({ data: group });
    });
    vi.mocked(axios.post).mockImplementation(async () => {
      invitations = [
        { id: 9, email: 'carla@example.com', invited_by: { id: 5, name: 'Dono' }, expires_at: '2026-11-10T12:00:00.000000Z' }
      ];
      return { data: { message: 'Convite enviado.', pending: true } };
    });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <GroupMembersForm />
      </MemoryRouter>
    );

    await screen.findByText('ana@example.com');
    const readsOf = (suffix: string) =>
      vi.mocked(axios.get).mock.calls.filter(([url]) => String(url).endsWith(suffix)).length;
    const membersReadsBefore = readsOf('/members');

    await user.type(screen.getByLabelText(/^E-mail do membro/), 'carla@example.com');
    await user.click(screen.getByRole('button', { name: 'Convidar' }));

    expect(axios.post).toHaveBeenCalledWith(
      expect.stringContaining('/api/groups/1/members'),
      { email: 'carla@example.com' },
      expect.anything()
    );
    expect(
      await screen.findByText('Convite enviado para carla@example.com. A pessoa só entra no grupo depois de aceitar.')
    ).toBeInTheDocument();
    expect(screen.getByLabelText(/^E-mail do membro/)).toHaveValue('');
    // O convite novo aparece em "Convites pendentes" (recarregados), e a lista de membros não foi relida.
    expect(await screen.findByText('Convites pendentes')).toBeInTheDocument();
    expect(screen.getByText('carla@example.com')).toBeInTheDocument();
    expect(readsOf('/members')).toBe(membersReadsBefore);
    expect(readsOf('/invitations')).toBe(2);
  });
});
