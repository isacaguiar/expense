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

const group = { id: 1, name: 'Casa dos Amigos', description: 'Contas da casa' };
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
  });

  it('adds a new member by email and refreshes the list', async () => {
    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/members')) return Promise.resolve({ data: members });
      return Promise.resolve({ data: group });
    });
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <GroupMembersForm />
      </MemoryRouter>
    );

    await screen.findByText('ana@example.com');

    await user.type(screen.getByLabelText(/^E-mail do membro/), 'carla@example.com');
    await user.click(screen.getByRole('button', { name: 'Adicionar' }));

    expect(axios.post).toHaveBeenCalledWith(
      expect.stringContaining('/api/groups/1/members'),
      { email: 'carla@example.com' },
      expect.anything()
    );
  });
});
