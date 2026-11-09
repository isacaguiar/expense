import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ReceivedInvitations from './ReceivedInvitations';

vi.mock('axios');

const navigateMock = vi.fn();
vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => navigateMock };
});

const invitations = [
  { id: 5, group: { id: 7, name: 'Casa' }, invited_by: { id: 2, name: 'Ana' }, expires_at: '2026-11-10T12:00:00.000000Z' },
  { id: 6, group: { id: 8, name: 'Viagem' }, invited_by: null, expires_at: '2026-11-11T12:00:00.000000Z' }
];

const UNAVAILABLE = 'Este convite não está mais disponível.';
const GENERIC_ERROR = 'Não foi possível responder ao convite agora. Tente novamente.';

function renderSection() {
  return render(
    <MemoryRouter>
      <ReceivedInvitations />
    </MemoryRouter>
  );
}

const acceptButton = (groupName: string) => screen.getByRole('button', { name: `Aceitar convite do grupo ${groupName}` });
const declineButton = (groupName: string) => screen.getByRole('button', { name: `Recusar convite do grupo ${groupName}` });

describe('ReceivedInvitations', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: { message: 'ok' } });
    localStorage.setItem('accessToken', 't');
  });

  it('lists each invitation with who invited and the group, and asks for the token', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: invitations });

    renderSection();

    expect(await screen.findByText('Convites recebidos')).toBeInTheDocument();
    expect(screen.getByText(/Ana convidou você para o grupo/)).toHaveTextContent('Ana convidou você para o grupo "Casa"');
    // Quando a conta de quem convidou deixou de existir, o convite segue valendo.
    expect(screen.getByText(/Alguém convidou você para o grupo/)).toHaveTextContent('Alguém convidou você para o grupo "Viagem"');
    expect(axios.get).toHaveBeenCalledWith(
      expect.stringContaining('/api/me/invitations'),
      expect.objectContaining({ headers: { Authorization: 'Bearer t' } })
    );
  });

  // CA-14: aceitar leva ao grupo.
  it('accepts an invitation and goes to the group', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: invitations });
    renderSection();
    await screen.findByText('Convites recebidos');

    await userEvent.click(acceptButton('Casa'));

    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/groups/7/summary'));
    expect(axios.post).toHaveBeenCalledWith(
      expect.stringContaining('/api/me/invitations/5/accept'),
      null,
      expect.objectContaining({ headers: { Authorization: 'Bearer t' } })
    );
  });

  // CA-14: recusar tira o convite da lista.
  it('declines an invitation and removes it from the list', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: invitations });
    renderSection();
    await screen.findByText('Convites recebidos');

    await userEvent.click(declineButton('Casa'));

    await waitFor(() => expect(screen.queryByText(/Ana convidou você para o grupo/)).not.toBeInTheDocument());
    expect(axios.post).toHaveBeenCalledWith(
      expect.stringContaining('/api/me/invitations/5/decline'),
      null,
      expect.anything()
    );
    expect(screen.getByText(/Alguém convidou você para o grupo/)).toBeInTheDocument();
    expect(navigateMock).not.toHaveBeenCalled();
    expect(await screen.findByText('Convite recusado.')).toBeInTheDocument();
  });

  it('disables the buttons of an invitation while its call is in flight, and only those', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: invitations });
    vi.mocked(axios.post).mockReturnValue(new Promise(() => {}));
    renderSection();
    await screen.findByText('Convites recebidos');

    await userEvent.click(acceptButton('Casa'));

    await waitFor(() => expect(acceptButton('Casa')).toBeDisabled());
    expect(declineButton('Casa')).toBeDisabled();
    expect(acceptButton('Viagem')).toBeEnabled();
    expect(declineButton('Viagem')).toBeEnabled();
    expect(axios.post).toHaveBeenCalledTimes(1);
  });

  it('says the invitation is no longer available on a 404 when accepting, and removes it', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: invitations });
    vi.mocked(axios.post).mockRejectedValue({ response: { status: 404 } });
    renderSection();
    await screen.findByText('Convites recebidos');

    await userEvent.click(acceptButton('Casa'));

    expect(await screen.findByText(UNAVAILABLE)).toBeInTheDocument();
    expect(screen.queryByText(/Ana convidou você para o grupo/)).not.toBeInTheDocument();
    expect(navigateMock).not.toHaveBeenCalled();
  });

  it('says the invitation is no longer available on a 404 when declining, and removes it', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: invitations });
    vi.mocked(axios.post).mockRejectedValue({ response: { status: 404 } });
    renderSection();
    await screen.findByText('Convites recebidos');

    await userEvent.click(declineButton('Viagem'));

    expect(await screen.findByText(UNAVAILABLE)).toBeInTheDocument();
    expect(screen.queryByText(/Alguém convidou você para o grupo/)).not.toBeInTheDocument();
    expect(screen.getByText(/Ana convidou você para o grupo/)).toBeInTheDocument();
  });

  it('keeps the invitation and re-enables the buttons on any other error', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: invitations });
    vi.mocked(axios.post).mockRejectedValue({ response: { status: 500 } });
    renderSection();
    await screen.findByText('Convites recebidos');

    await userEvent.click(acceptButton('Casa'));

    expect(await screen.findByText(GENERIC_ERROR)).toBeInTheDocument();
    expect(screen.getByText(/Ana convidou você para o grupo/)).toBeInTheDocument();
    expect(acceptButton('Casa')).toBeEnabled();
    expect(declineButton('Casa')).toBeEnabled();
    expect(navigateMock).not.toHaveBeenCalled();
  });

  // Best effort: um problema aqui não pode quebrar Meus Grupos.
  it('renders nothing and does not throw when loading the invitations fails', async () => {
    vi.mocked(axios.get).mockRejectedValue(new Error('boom'));

    const { container } = renderSection();

    await waitFor(() => expect(axios.get).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });

  it('renders nothing when the answer is not an array', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: { id: 10, max_groups_per_user: 3 } });

    const { container } = renderSection();

    await waitFor(() => expect(axios.get).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });

  it('renders nothing when there are no invitations', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: [] });

    const { container } = renderSection();

    await waitFor(() => expect(axios.get).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });

  it('ignores items that do not look like an invitation and keeps the valid ones', async () => {
    vi.mocked(axios.get).mockResolvedValue({
      data: [{ id: 1, name: 'um grupo, não um convite' }, null, 'texto', invitations[0]]
    });

    renderSection();

    expect(await screen.findByText(/Ana convidou você para o grupo/)).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: /^Aceitar convite/ })).toHaveLength(1);
  });
});
