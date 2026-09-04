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
  avatar_url: null as string | null,
};

describe('Profile', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.put).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.delete).mockReset();
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

  it('shows the initials avatar when there is no avatar_url', async () => {
    render(
      <MemoryRouter>
        <Profile />
      </MemoryRouter>
    );

    await screen.findByDisplayValue('Ana Silva');
    expect(screen.getByText('AS')).toBeInTheDocument();
  });

  it('shows the Google photo as avatar when avatar_url is present', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({
      data: { ...me, avatar_url: 'https://google.example/pic.jpg' },
    });

    const { container } = render(
      <MemoryRouter>
        <Profile />
      </MemoryRouter>
    );

    await screen.findByDisplayValue('Ana Silva');
    const avatarImg = container.querySelector('img');
    expect(avatarImg).toHaveAttribute('src', 'https://google.example/pic.jpg');
  });

  it('uploads a selected photo to POST /api/user/photo and shows it as the avatar', async () => {
    vi.mocked(axios.post).mockResolvedValue({ data: { avatar_url: 'https://signed.example/photo?sig=1' } });
    const user = userEvent.setup();

    const { container } = render(
      <MemoryRouter>
        <Profile />
      </MemoryRouter>
    );

    await screen.findByDisplayValue('Ana Silva');

    const input = container.querySelector('input[type="file"]') as HTMLInputElement;
    await user.upload(input, new File(['x'], 'foto.png', { type: 'image/png' }));

    await waitFor(() =>
      expect(axios.post).toHaveBeenCalledWith(
        expect.stringContaining('/api/user/photo'),
        expect.any(FormData),
        expect.anything()
      )
    );
    expect(container.querySelector('img')).toHaveAttribute('src', 'https://signed.example/photo?sig=1');
    expect(await screen.findByText('Foto atualizada com sucesso.')).toBeInTheDocument();
  });

  it('removes the photo via DELETE /api/user/photo and falls back to initials', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({ data: { ...me, avatar_url: 'https://google.example/pic.jpg' } });
    vi.mocked(axios.delete).mockResolvedValue({ data: { avatar_url: null } });
    const user = userEvent.setup();

    const { container } = render(
      <MemoryRouter>
        <Profile />
      </MemoryRouter>
    );

    await screen.findByDisplayValue('Ana Silva');
    expect(container.querySelector('img')).toHaveAttribute('src', 'https://google.example/pic.jpg');

    await user.click(screen.getByRole('button', { name: 'Remover foto' }));

    await waitFor(() =>
      expect(axios.delete).toHaveBeenCalledWith(expect.stringContaining('/api/user/photo'), expect.anything())
    );
    expect(container.querySelector('img')).not.toBeInTheDocument();
    expect(screen.getByText('AS')).toBeInTheDocument();
    expect(await screen.findByText('Foto removida.')).toBeInTheDocument();
  });

  it('shows an error message when the photo upload fails', async () => {
    vi.mocked(axios.post).mockRejectedValue(new Error('boom'));
    const user = userEvent.setup();

    const { container } = render(
      <MemoryRouter>
        <Profile />
      </MemoryRouter>
    );

    await screen.findByDisplayValue('Ana Silva');

    const input = container.querySelector('input[type="file"]') as HTMLInputElement;
    await user.upload(input, new File(['x'], 'foto.png', { type: 'image/png' }));

    expect(await screen.findByText('Não foi possível enviar a foto. Tente novamente.')).toBeInTheDocument();
  });

  it('does not offer "Remover foto" when there is no avatar', async () => {
    render(
      <MemoryRouter>
        <Profile />
      </MemoryRouter>
    );

    await screen.findByDisplayValue('Ana Silva');
    expect(screen.queryByRole('button', { name: 'Remover foto' })).not.toBeInTheDocument();
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
