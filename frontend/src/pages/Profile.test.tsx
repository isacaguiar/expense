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

const me = {
  name: 'Ana Silva',
  email: 'ana@example.com',
  pix: 'ana@pix.com',
  whatsapp: '(71) 99999-9999',
  notify_whatsapp: true,
};

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
    expect(screen.getByDisplayValue('(71) 99999-9999')).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: 'Receber notificações pelo WhatsApp' })).toBeChecked();
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
      {
        name: 'Ana Souza',
        email: 'ana@example.com',
        pix: 'ana@pix.com',
        whatsapp: '(71) 99999-9999',
        notify_whatsapp: true,
      },
      expect.anything()
    );
    expect(await screen.findByText('Perfil atualizado com sucesso.')).toBeInTheDocument();
  });

  it('masks the WhatsApp field progressively as the user types digits', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <Profile />
      </MemoryRouter>
    );

    await screen.findByDisplayValue('Ana Silva');

    const whatsappField = screen.getByLabelText(/^WhatsApp/);
    await user.clear(whatsappField);
    await user.type(whatsappField, '71999998888');

    expect(whatsappField).toHaveValue('(71) 99999-8888');
  });

  it('toggles the WhatsApp notification checkbox', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <Profile />
      </MemoryRouter>
    );

    await screen.findByDisplayValue('Ana Silva');

    const checkbox = screen.getByRole('checkbox', { name: 'Receber notificações pelo WhatsApp' });
    expect(checkbox).toBeChecked();

    await user.click(checkbox);

    expect(checkbox).not.toBeChecked();
  });

  it('navigates to the Google consent URL when clicking "Vincular conta Google"', async () => {
    const assignSpy = vi.fn();
    vi.stubGlobal('location', { ...window.location, assign: assignSpy, set href(value: string) {
      assignSpy(value);
    } });

    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <Profile />
      </MemoryRouter>
    );

    await screen.findByDisplayValue('Ana Silva');

    vi.mocked(axios.get).mockResolvedValueOnce({ data: { url: 'https://accounts.google.com/o/oauth2/auth?state=xyz' } });
    await user.click(screen.getByRole('button', { name: 'Vincular conta Google' }));

    await waitFor(() => {
      expect(assignSpy).toHaveBeenCalledWith('https://accounts.google.com/o/oauth2/auth?state=xyz');
    });
    expect(axios.get).toHaveBeenLastCalledWith(expect.stringContaining('/api/user/google/redirect-url'), expect.anything());

    vi.unstubAllGlobals();
  });

  it('shows a success snackbar when returning from Google with ?linked=success', async () => {
    render(
      <MemoryRouter initialEntries={['/profile?linked=success']}>
        <Profile />
      </MemoryRouter>
    );

    await screen.findByDisplayValue('Ana Silva');
    expect(await screen.findByText('Conta Google vinculada com sucesso.')).toBeInTheDocument();
  });

  it('shows an error snackbar when returning from Google with ?linked=error', async () => {
    render(
      <MemoryRouter initialEntries={['/profile?linked=error']}>
        <Profile />
      </MemoryRouter>
    );

    await screen.findByDisplayValue('Ana Silva');
    expect(await screen.findByText('Não foi possível vincular sua conta Google.')).toBeInTheDocument();
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
