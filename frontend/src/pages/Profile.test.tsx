import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Profile from './Profile';

const navigateMock = vi.fn();

vi.mock('axios');

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => navigateMock,
  };
});

const me = { name: 'Ana Silva', email: 'ana@example.com', pix: 'ana@pix.com' };

describe('Profile', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.put).mockReset();
    vi.mocked(axios.get).mockResolvedValue({ data: me });
  });

  it('pre-fills the form with data from GET /api/me', async () => {
    render(
      <MemoryRouter>
        <Profile />
      </MemoryRouter>
    );

    expect(await screen.findByDisplayValue('Ana Silva')).toBeInTheDocument();
    expect(screen.getByDisplayValue('ana@example.com')).toBeInTheDocument();
    expect(screen.getByDisplayValue('ana@pix.com')).toBeInTheDocument();
  });

  it('submits the updated fields to PUT /api/user/profile and shows a success message', async () => {
    vi.mocked(axios.put).mockResolvedValue({ data: { message: 'ok' } });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <Profile />
      </MemoryRouter>
    );

    await screen.findByDisplayValue('Ana Silva');

    await user.clear(screen.getByLabelText(/^Nome/));
    await user.type(screen.getByLabelText(/^Nome/), 'Ana Souza');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(axios.put).toHaveBeenCalledWith(
      expect.stringContaining('/api/user/profile'),
      { name: 'Ana Souza', email: 'ana@example.com', pix: 'ana@pix.com' },
      expect.anything()
    );
    expect(await screen.findByText('Perfil atualizado com sucesso.')).toBeInTheDocument();
  });

  it('shows the backend validation error on failure', async () => {
    vi.mocked(axios.put).mockRejectedValue({
      response: { data: { errors: { email: ['O e-mail já está em uso.'] } } },
    });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <Profile />
      </MemoryRouter>
    );

    await screen.findByDisplayValue('Ana Silva');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(await screen.findByText('O e-mail já está em uso.')).toBeInTheDocument();
  });

  it('redirects to login on 401 while loading the profile', async () => {
    vi.mocked(axios.get).mockRejectedValueOnce({ response: { status: 401 } });

    render(
      <MemoryRouter>
        <Profile />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(navigateMock).toHaveBeenCalledWith('/', { replace: true });
    });
  });
});
