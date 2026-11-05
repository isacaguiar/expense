import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GroupMembersForm from './GroupMembersForm';

/**
 * Feature 20261103-convite-pendente-no-grupo (CA-15, TASK-579): a lista de convites pendentes
 * de `GroupMembersForm`, com **Cancelar** para quem convidou e para o criador. Num arquivo
 * próprio, como `GroupMembersForm.exit.test.tsx`, porque os testes dos outros dois arquivos
 * do formulário não são editados.
 */

vi.mock('axios');

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => vi.fn(), useParams: () => ({ id: '1' }) };
});

const ANA = { id: 1, name: 'Ana', email: 'ana@example.com', avatar_url: null };
const BRUNO = { id: 2, name: 'Bruno', email: 'bruno@example.com', avatar_url: null };
const CARLA = { id: 3, name: 'Carla', email: 'carla@example.com', avatar_url: null };

const group = (createdBy: number) => ({
  id: 1,
  name: 'Casa dos Amigos',
  description: 'Contas da casa',
  created_by: createdBy,
  creator: { id: createdBy, email: 'ana@example.com' }
});

const invitation = (id: number, email: string, invitedBy: { id: number; name: string } | null) => ({
  id,
  email,
  invited_by: invitedBy,
  expires_at: '2026-11-10T12:00:00.000000Z'
});

const BY_ANA = invitation(10, 'dani@example.com', { id: 1, name: 'Ana' });
const BY_BRUNO = invitation(11, 'eva@example.com', { id: 2, name: 'Bruno' });
const ORPHAN = invitation(12, 'fabio@example.com', null);

const GENERIC_ERROR = 'Não foi possível cancelar o convite agora. Tente novamente.';

type Server = { invitations: unknown; me: number | 'fails'; createdBy: number; invitationsFail: boolean };

/** O que o servidor "tem": os testes mudam `server.invitations` para simular o estado depois de uma ação. */
function mockApi(overrides: Partial<Server> = {}): Server {
  const server: Server = { invitations: [], me: 1, createdBy: 1, invitationsFail: false, ...overrides };

  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.endsWith('/api/me')) {
      return server.me === 'fails' ? Promise.reject(new Error('boom')) : Promise.resolve({ data: { id: server.me } });
    }
    if (url.endsWith('/invitations')) {
      return server.invitationsFail ? Promise.reject(new Error('boom')) : Promise.resolve({ data: server.invitations });
    }
    if (url.includes('/members')) return Promise.resolve({ data: [ANA, BRUNO, CARLA] });
    return Promise.resolve({ data: group(server.createdBy) });
  });

  return server;
}

function renderForm() {
  render(
    <MemoryRouter>
      <GroupMembersForm />
    </MemoryRouter>
  );
}

const invitationRequests = () =>
  vi.mocked(axios.get).mock.calls.filter(([url]) => String(url).endsWith('/api/groups/1/invitations'));

const cancelButton = (email: string) => screen.getByRole('button', { name: `Cancelar convite para ${email}` });
const cancelButtons = () => screen.queryAllByRole('button', { name: /^Cancelar convite/ });

describe('GroupMembersForm: convites pendentes', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.delete).mockReset();
    vi.mocked(axios.delete).mockResolvedValue({ data: { message: 'ok' } });
    localStorage.setItem('accessToken', 't');
  });

  it('lists the pending invitations with the e-mail, who invited and until when it is valid', async () => {
    mockApi({ me: 3, invitations: [BY_ANA, BY_BRUNO, ORPHAN] });
    renderForm();

    expect(await screen.findByText('Convites pendentes')).toBeInTheDocument();
    expect(screen.getByText('dani@example.com')).toBeInTheDocument();
    expect(screen.getByText('eva@example.com')).toBeInTheDocument();
    expect(screen.getByText('fabio@example.com')).toBeInTheDocument();
    const rowOf = (email: string) => screen.getByText(email).closest('li') as HTMLElement;
    expect(rowOf('dani@example.com')).toHaveTextContent('Convidado por Ana');
    expect(rowOf('eva@example.com')).toHaveTextContent('Convidado por Bruno');
    // Quando a conta de quem convidou deixou de existir, o convite segue na lista.
    expect(rowOf('fabio@example.com')).toHaveTextContent('Convidado por alguém');
    expect(rowOf('dani@example.com')).toHaveTextContent('válido até 10/11/2026');
  });

  it('asks for the invitations of this group with the token, apart from the group and the members', async () => {
    mockApi({ invitations: [BY_ANA] });
    renderForm();
    await screen.findByText('Convites pendentes');

    expect(axios.get).toHaveBeenCalledWith(
      expect.stringContaining('/api/groups/1/invitations'),
      expect.objectContaining({ headers: { Authorization: 'Bearer t' } })
    );
  });

  it('shows Cancelar only on the invitations the member made', async () => {
    mockApi({ me: 2, createdBy: 1, invitations: [BY_ANA, BY_BRUNO, ORPHAN] });
    renderForm();

    await screen.findByText('Convites pendentes');

    expect(cancelButton('eva@example.com')).toBeInTheDocument();
    expect(cancelButtons()).toHaveLength(1);
  });

  it('shows Cancelar on every pending invitation to the creator of the group', async () => {
    mockApi({ me: 1, createdBy: 1, invitations: [BY_ANA, BY_BRUNO, ORPHAN] });
    renderForm();

    await screen.findByText('Convites pendentes');

    // Inclusive o convite cuja conta de quem convidou já não existe.
    await waitFor(() => expect(cancelButtons()).toHaveLength(3));
    expect(cancelButton('fabio@example.com')).toBeInTheDocument();
  });

  it('shows no Cancelar to a common member who invited nobody, but still lists the invitations', async () => {
    mockApi({ me: 3, createdBy: 1, invitations: [BY_ANA, BY_BRUNO] });
    renderForm();

    await screen.findByText('Convites pendentes');

    expect(screen.getByText('dani@example.com')).toBeInTheDocument();
    expect(cancelButtons()).toHaveLength(0);
  });

  it('shows no Cancelar while the current user is unknown', async () => {
    mockApi({ me: 'fails', createdBy: 1, invitations: [BY_ANA] });
    renderForm();

    await screen.findByText('Convites pendentes');

    expect(cancelButtons()).toHaveLength(0);
  });

  it('cancels with a DELETE to the invitation of the group, takes it off the list and reloads it', async () => {
    const server = mockApi({ me: 2, invitations: [BY_ANA, BY_BRUNO] });
    // O servidor cancela o convite e, entre uma chamada e outra, outra pessoa fez um convite novo.
    const NEW = invitation(13, 'gabi@example.com', { id: 3, name: 'Carla' });
    vi.mocked(axios.delete).mockImplementation(async () => {
      server.invitations = [BY_ANA, NEW];
      return { data: { message: 'Convite cancelado.' } };
    });
    renderForm();
    await screen.findByText('eva@example.com');
    expect(invitationRequests()).toHaveLength(1);

    await userEvent.click(cancelButton('eva@example.com'));

    await waitFor(() => expect(screen.queryByText('eva@example.com')).not.toBeInTheDocument());
    expect(axios.delete).toHaveBeenCalledWith(
      expect.stringContaining('/api/groups/1/invitations/11'),
      expect.objectContaining({ headers: { Authorization: 'Bearer t' } })
    );
    // A lista foi recarregada: trouxe o convite novo do servidor.
    expect(await screen.findByText('gabi@example.com')).toBeInTheDocument();
    expect(screen.getByText('dani@example.com')).toBeInTheDocument();
    expect(invitationRequests()).toHaveLength(2);
  });

  it('takes the cancelled invitation off the list even if reloading the list fails', async () => {
    const server = mockApi({ me: 2, invitations: [BY_ANA, BY_BRUNO] });
    vi.mocked(axios.delete).mockImplementation(async () => {
      server.invitationsFail = true; // a recarga que vem depois da ação falha
      return { data: { message: 'Convite cancelado.' } };
    });
    renderForm();
    await screen.findByText('eva@example.com');

    await userEvent.click(cancelButton('eva@example.com'));

    await waitFor(() => expect(invitationRequests()).toHaveLength(2));
    expect(screen.queryByText('eva@example.com')).not.toBeInTheDocument();
    // O que a recarga falha não trouxe fica como estava.
    expect(screen.getByText('dani@example.com')).toBeInTheDocument();
  });

  // Com dois convites, para o cartão seguir na tela: com um só, o cartão inteiro some ao cancelar e
  // leva o alerta junto, e o teste passaria mesmo sem limpar o erro.
  it('clears the error of a failed attempt when the next one goes through', async () => {
    const server = mockApi({ me: 1, createdBy: 1, invitations: [BY_ANA, BY_BRUNO] });
    vi.mocked(axios.delete).mockRejectedValueOnce({ response: { status: 500 } });
    renderForm();
    await screen.findByText('eva@example.com');
    await waitFor(() => expect(cancelButtons()).toHaveLength(2));
    await userEvent.click(cancelButton('eva@example.com'));
    expect(await screen.findByText(GENERIC_ERROR)).toBeInTheDocument();

    vi.mocked(axios.delete).mockImplementation(async () => {
      server.invitations = [BY_BRUNO];
      return { data: { message: 'Convite cancelado.' } };
    });
    await userEvent.click(cancelButton('dani@example.com'));

    await waitFor(() => expect(screen.queryByText('dani@example.com')).not.toBeInTheDocument());
    expect(screen.getByText('eva@example.com')).toBeInTheDocument();
    expect(screen.queryByText(GENERIC_ERROR)).not.toBeInTheDocument();
  });

  it('only reloads the list on a 404 (already answered, cancelled or expired) and shows no error', async () => {
    const server = mockApi({ me: 2, invitations: [BY_BRUNO] });
    vi.mocked(axios.delete).mockImplementation(async () => {
      server.invitations = [];
      throw { response: { status: 404 } };
    });
    renderForm();
    await screen.findByText('eva@example.com');

    await userEvent.click(cancelButton('eva@example.com'));

    await waitFor(() => expect(screen.queryByText('eva@example.com')).not.toBeInTheDocument());
    expect(screen.queryByText(GENERIC_ERROR)).not.toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(invitationRequests()).toHaveLength(2);
  });

  it('keeps the invitation, shows an alert and re-enables the button on any other error', async () => {
    mockApi({ me: 2, invitations: [BY_BRUNO] });
    vi.mocked(axios.delete).mockRejectedValue({ response: { status: 500 } });
    renderForm();
    await screen.findByText('eva@example.com');

    await userEvent.click(cancelButton('eva@example.com'));

    expect(await screen.findByText(GENERIC_ERROR)).toBeInTheDocument();
    expect(screen.getByText('eva@example.com')).toBeInTheDocument();
    expect(cancelButton('eva@example.com')).toBeEnabled();
    expect(invitationRequests()).toHaveLength(1);
  });

  it('disables Cancelar while its call is in flight, and only on that invitation', async () => {
    mockApi({ me: 1, createdBy: 1, invitations: [BY_ANA, BY_BRUNO] });
    vi.mocked(axios.delete).mockReturnValue(new Promise(() => {}));
    renderForm();
    await screen.findByText('Convites pendentes');
    await waitFor(() => expect(cancelButtons()).toHaveLength(2));

    await userEvent.click(cancelButton('eva@example.com'));

    await waitFor(() => expect(cancelButton('eva@example.com')).toBeDisabled());
    expect(cancelButton('dani@example.com')).toBeEnabled();
    expect(axios.delete).toHaveBeenCalledTimes(1);
  });

  // --- Convidar (TASK-580): o formulário fala em convite, e não em adicionar ---

  const typeEmailAndInvite = async (email: string) => {
    await userEvent.type(screen.getByLabelText(/^E-mail do membro/), email);
    await userEvent.click(screen.getByRole('button', { name: 'Convidar' }));
  };

  const membersReads = () => vi.mocked(axios.get).mock.calls.filter(([url]) => String(url).endsWith('/members')).length;

  it('talks about inviting, not adding: the card title and the button', async () => {
    mockApi();
    renderForm();

    expect(await screen.findByText('Convidar novo membro')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Convidar' })).toBeInTheDocument();
    expect(screen.queryByText('Adicionar novo membro')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Adicionar' })).not.toBeInTheDocument();
    // O campo e o rótulo continuam os mesmos.
    expect(screen.getByLabelText(/^E-mail do membro/)).toBeInTheDocument();
  });

  it('shows the new invitation, with Cancelar for who sent it, after inviting', async () => {
    const server = mockApi({ me: 1, createdBy: 1, invitations: [BY_BRUNO] });
    vi.mocked(axios.post).mockImplementation(async () => {
      server.invitations = [BY_BRUNO, invitation(13, 'gabi@example.com', { id: 1, name: 'Ana' })];
      return { data: { message: 'Convite enviado.', pending: true } };
    });
    renderForm();
    await screen.findByText('eva@example.com');

    await typeEmailAndInvite('gabi@example.com');

    expect(await screen.findByText('Convite enviado para gabi@example.com. A pessoa só entra no grupo depois de aceitar.')).toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'Cancelar convite para gabi@example.com' })).toBeInTheDocument();
    expect(screen.getByText('eva@example.com')).toBeInTheDocument();
  });

  it('still says the invitation was sent when only reloading the pending list fails afterwards', async () => {
    const server = mockApi();
    vi.mocked(axios.post).mockImplementation(async () => {
      server.invitationsFail = true; // o convite foi criado, mas a recarga da lista falha
      return { data: { message: 'Convite enviado.', pending: true } };
    });
    renderForm();
    await screen.findByText('Convidar novo membro');

    await typeEmailAndInvite('gabi@example.com');

    expect(await screen.findByText(/Convite enviado para gabi@example.com/)).toBeInTheDocument();
    expect(screen.queryByText('Erro ao enviar o convite.')).not.toBeInTheDocument();
  });

  it('says "Erro ao enviar o convite." when the invitation fails, and keeps the typed e-mail', async () => {
    mockApi();
    vi.mocked(axios.post).mockRejectedValue({ response: { status: 500 } });
    renderForm();
    await screen.findByText('Convidar novo membro');
    const readsBefore = membersReads();

    await typeEmailAndInvite('gabi@example.com');

    expect(await screen.findByText('Erro ao enviar o convite.')).toBeInTheDocument();
    expect(screen.getByLabelText(/^E-mail do membro/)).toHaveValue('gabi@example.com');
    expect(screen.queryByText(/Convite enviado para/)).not.toBeInTheDocument();
    expect(membersReads()).toBe(readsBefore);
    expect(screen.getByRole('button', { name: 'Convidar' })).toBeEnabled();
  });

  it('clears the previous error when the next invitation goes through', async () => {
    mockApi();
    vi.mocked(axios.post).mockRejectedValueOnce({ response: { status: 500 } }).mockResolvedValue({ data: { pending: true } });
    renderForm();
    await screen.findByText('Convidar novo membro');
    await typeEmailAndInvite('gabi@example.com');
    expect(await screen.findByText('Erro ao enviar o convite.')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Convidar' }));

    expect(await screen.findByText(/Convite enviado para gabi@example.com/)).toBeInTheDocument();
    expect(screen.queryByText('Erro ao enviar o convite.')).not.toBeInTheDocument();
  });

  it('removes the success message when the next invitation fails', async () => {
    mockApi();
    vi.mocked(axios.post).mockResolvedValueOnce({ data: { pending: true } }).mockRejectedValue({ response: { status: 409 } });
    renderForm();
    await screen.findByText('Convidar novo membro');
    await typeEmailAndInvite('gabi@example.com');
    expect(await screen.findByText(/Convite enviado para gabi@example.com/)).toBeInTheDocument();

    await typeEmailAndInvite('hugo@example.com');

    expect(await screen.findByText('Erro ao enviar o convite.')).toBeInTheDocument();
    expect(screen.queryByText(/Convite enviado para/)).not.toBeInTheDocument();
  });

  it('shows no card when there is no pending invitation', async () => {
    mockApi({ invitations: [] });
    renderForm();

    expect(await screen.findByText('ana@example.com')).toBeInTheDocument();
    await waitFor(() => expect(invitationRequests()).toHaveLength(1));
    expect(screen.queryByText('Convites pendentes')).not.toBeInTheDocument();
  });

  // Best effort: um problema na lista de convites não pode derrubar a tela de Participantes.
  it('does not break the screen when loading the invitations fails', async () => {
    mockApi({ invitationsFail: true });
    renderForm();

    expect(await screen.findByText('ana@example.com')).toBeInTheDocument();
    expect(screen.getByText('Casa dos Amigos')).toBeInTheDocument();
    await waitFor(() => expect(invitationRequests()).toHaveLength(1));
    expect(screen.queryByText('Convites pendentes')).not.toBeInTheDocument();
    expect(screen.queryByText('Erro ao carregar informações do grupo.')).not.toBeInTheDocument();
  });

  it('ignores an answer that is not a list', async () => {
    mockApi({ invitations: { id: 1, name: 'Casa dos Amigos' } });
    renderForm();

    expect(await screen.findByText('ana@example.com')).toBeInTheDocument();
    await waitFor(() => expect(invitationRequests()).toHaveLength(1));
    expect(screen.queryByText('Convites pendentes')).not.toBeInTheDocument();
  });

  it('ignores items that do not look like an invitation and keeps the valid ones', async () => {
    mockApi({ me: 1, createdBy: 1, invitations: [{ id: 1, name: 'um grupo' }, null, 'texto', BY_ANA] });
    renderForm();

    expect(await screen.findByText('dani@example.com')).toBeInTheDocument();
    const card = screen.getByText('Convites pendentes').closest('.MuiCard-root') as HTMLElement;
    expect(within(card).getAllByRole('listitem')).toHaveLength(1);
  });
});
