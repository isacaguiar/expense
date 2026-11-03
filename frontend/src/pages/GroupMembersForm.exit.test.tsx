import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GroupMembersForm from './GroupMembersForm';

/**
 * Item 086 (CA-12 e CA-13): sair do grupo e remover membro em `GroupMembersForm`.
 * Fica num arquivo próprio porque aqui o `useNavigate` precisa de um espião estável
 * (o de `GroupMembersForm.test.tsx` devolve um `vi.fn()` novo a cada chamada), e os
 * testes daquele arquivo não são editados.
 */

vi.mock('axios');

const navigateMock = vi.fn();
vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => navigateMock, useParams: () => ({ id: '1' }) };
});

const ANA = { id: 1, name: 'Ana', email: 'ana@example.com', avatar_url: null };
const BRUNO = { id: 2, name: 'Bruno', email: 'bruno@example.com', avatar_url: null };
const CARLA = { id: 3, name: 'Carla', email: 'carla@example.com', avatar_url: null };

const groupBy = (createdBy: number | null) => ({
  id: 1,
  name: 'Casa dos Amigos',
  description: 'Contas da casa',
  created_by: createdBy,
  creator: createdBy === null ? null : { id: createdBy, email: 'ana@example.com' }
});

type Opts = { me?: number | 'fails'; createdBy?: number | null; members?: unknown[] };

/** Responde `/api/me`, `/members` e o grupo; `members` pode ser trocado para simular a lista depois da remoção. */
function mockApi({ me = 1, createdBy = 1, members = [ANA, BRUNO, CARLA] }: Opts = {}) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.endsWith('/api/me')) {
      return me === 'fails' ? Promise.reject(new Error('boom')) : Promise.resolve({ data: { id: me } });
    }
    if (url.includes('/members')) return Promise.resolve({ data: members });
    return Promise.resolve({ data: groupBy(createdBy) });
  });
}

function renderForm() {
  render(
    <MemoryRouter>
      <GroupMembersForm />
    </MemoryRouter>
  );
}

const rowOf = (email: string): HTMLElement => screen.getByText(email).closest('li') as HTMLElement;

const apiError = (status: number, data: Record<string, unknown>) => ({ response: { status, data } });

describe('GroupMembersForm: sair do grupo e remover membro', () => {
  beforeEach(() => {
    navigateMock.mockReset();
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.delete).mockReset();
  });

  it('shows "Sair do grupo" only on the own row and no "Remover" to a regular member', async () => {
    mockApi({ me: 2, createdBy: 1 });
    renderForm();

    await screen.findByText('bruno@example.com');

    const leave = await screen.findAllByRole('button', { name: 'Sair do grupo' });
    expect(leave).toHaveLength(1);
    expect(within(rowOf('bruno@example.com')).getByRole('button', { name: 'Sair do grupo' })).toBe(leave[0]);
    expect(screen.queryByRole('button', { name: 'Remover' })).not.toBeInTheDocument();
  });

  it('shows "Remover" on the other rows, and only "Sair do grupo" on the own row, to the creator', async () => {
    mockApi({ me: 1, createdBy: 1 });
    renderForm();

    await screen.findByText('bruno@example.com');
    await screen.findByRole('button', { name: 'Sair do grupo' });

    expect(within(rowOf('ana@example.com')).getByRole('button', { name: 'Sair do grupo' })).toBeInTheDocument();
    expect(within(rowOf('ana@example.com')).queryByRole('button', { name: 'Remover' })).not.toBeInTheDocument();
    expect(within(rowOf('bruno@example.com')).getByRole('button', { name: 'Remover' })).toBeInTheDocument();
    expect(within(rowOf('carla@example.com')).getByRole('button', { name: 'Remover' })).toBeInTheDocument();
    expect(within(rowOf('bruno@example.com')).queryByRole('button', { name: 'Sair do grupo' })).not.toBeInTheDocument();
  });

  it('gives nobody the "Remover" button in a group without a registered creator', async () => {
    mockApi({ me: 1, createdBy: null });
    renderForm();

    await screen.findByRole('button', { name: 'Sair do grupo' });

    expect(screen.queryByRole('button', { name: 'Remover' })).not.toBeInTheDocument();
  });

  it('shows no action at all while the current user is unknown', async () => {
    mockApi({ me: 'fails', createdBy: 1 });
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
    renderForm();

    await screen.findByText('bruno@example.com');

    expect(screen.queryByRole('button', { name: 'Sair do grupo' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Remover' })).not.toBeInTheDocument();
    consoleError.mockRestore();
  });

  it('asks for confirmation before leaving and "Cancelar" does not call the API', async () => {
    mockApi({ me: 2, createdBy: 1 });
    const user = userEvent.setup();
    renderForm();

    await user.click(await screen.findByRole('button', { name: 'Sair do grupo' }));

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/não apaga as suas despesas nem as suas dívidas neste grupo/)).toBeInTheDocument();
    expect(within(dialog).getByText(/continua vendo o que você deve/)).toBeInTheDocument();
    expect(axios.delete).not.toHaveBeenCalled();

    await user.click(within(dialog).getByRole('button', { name: 'Cancelar' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(axios.delete).not.toHaveBeenCalled();
    expect(navigateMock).not.toHaveBeenCalled();
  });

  it('confirms the exit with DELETE for the own id and goes to /meus-grupos', async () => {
    mockApi({ me: 2, createdBy: 1 });
    vi.mocked(axios.delete).mockResolvedValue({ data: { message: 'Você saiu do grupo.' } });
    const user = userEvent.setup();
    renderForm();

    await user.click(await screen.findByRole('button', { name: 'Sair do grupo' }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Confirmar saída' }));

    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/meus-grupos'));
    expect(axios.delete).toHaveBeenCalledTimes(1);
    expect(axios.delete).toHaveBeenCalledWith(expect.stringMatching(/\/api\/groups\/1\/members\/2$/), expect.anything());
  });

  it('asks for confirmation before removing, naming the person, and removal does not navigate away', async () => {
    mockApi({ me: 1, createdBy: 1 });
    vi.mocked(axios.delete).mockResolvedValue({ data: { message: 'Membro removido do grupo.' } });
    const user = userEvent.setup();
    renderForm();

    await screen.findByText('bruno@example.com');
    await user.click(await within(rowOf('bruno@example.com')).findByRole('button', { name: 'Remover' }));

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/bruno@example\.com/)).toBeInTheDocument();
    expect(within(dialog).getByText(/não apaga as despesas nem as dívidas dessa pessoa neste grupo/)).toBeInTheDocument();
    expect(axios.delete).not.toHaveBeenCalled();

    // Depois de remover, a lista volta sem ele.
    mockApi({ me: 1, createdBy: 1, members: [ANA, CARLA] });
    await user.click(within(dialog).getByRole('button', { name: 'Confirmar remoção' }));

    await waitFor(() => expect(screen.queryByText('bruno@example.com')).not.toBeInTheDocument());
    expect(axios.delete).toHaveBeenCalledWith(expect.stringMatching(/\/api\/groups\/1\/members\/2$/), expect.anything());
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(screen.getByText('carla@example.com')).toBeInTheDocument();
    expect(navigateMock).not.toHaveBeenCalled();
  });

  it('closes the dialog and warns when the list cannot be reloaded after a removal that went through', async () => {
    mockApi({ me: 1, createdBy: 1 });
    vi.mocked(axios.delete).mockResolvedValue({ data: { message: 'Membro removido do grupo.' } });
    const user = userEvent.setup();
    renderForm();

    await screen.findByText('bruno@example.com');
    await user.click(await within(rowOf('bruno@example.com')).findByRole('button', { name: 'Remover' }));
    const dialog = await screen.findByRole('dialog');

    vi.mocked(axios.get).mockRejectedValue(new Error('boom'));
    await user.click(within(dialog).getByRole('button', { name: 'Confirmar remoção' }));

    expect(await screen.findByText('Membro removido, mas não foi possível atualizar a lista. Recarregue a página.')).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(screen.queryByText('Não foi possível remover o membro agora. Tente novamente.')).not.toBeInTheDocument();
  });

  it('keeps the dialog open on 422 with the API message and the expenses that block, without navigating', async () => {
    mockApi({ me: 2, createdBy: 1 });
    vi.mocked(axios.delete).mockRejectedValue(
      apiError(422, {
        message: 'Você ainda tem despesas neste grupo que impedem a sua saída.',
        blocking: [
          { expense_id: 9, description: 'Mercado', reason: 'creditor_open_quotas' },
          { expense_id: 10, description: 'Internet', reason: 'active_fixed' }
        ]
      })
    );
    const user = userEvent.setup();
    renderForm();

    await user.click(await screen.findByRole('button', { name: 'Sair do grupo' }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Confirmar saída' }));

    const dialog = screen.getByRole('dialog');
    expect(await within(dialog).findByText('Você ainda tem despesas neste grupo que impedem a sua saída.')).toBeInTheDocument();
    expect(within(dialog).getByText(/Mercado/)).toBeInTheDocument();
    expect(within(dialog).getByText(/parcelas a receber em aberto/)).toBeInTheDocument();
    expect(within(dialog).getByText(/Internet/)).toBeInTheDocument();
    expect(within(dialog).getByText(/despesa fixa ativa/)).toBeInTheDocument();
    expect(navigateMock).not.toHaveBeenCalled();

    // O botão de confirmar volta a funcionar e "Cancelar" fecha sem chamar a API de novo.
    expect(within(dialog).getByRole('button', { name: 'Confirmar saída' })).toBeEnabled();
    await user.click(within(dialog).getByRole('button', { name: 'Cancelar' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(axios.delete).toHaveBeenCalledTimes(1);
  });

  it('keeps the list as it was when a removal is refused with 422', async () => {
    mockApi({ me: 1, createdBy: 1 });
    vi.mocked(axios.delete).mockRejectedValue(
      apiError(422, {
        message: 'Este membro ainda tem despesas neste grupo que impedem a remoção.',
        blocking: [{ expense_id: 9, description: 'Mercado', reason: 'creditor_open_quotas' }]
      })
    );
    const user = userEvent.setup();
    renderForm();

    await screen.findByText('bruno@example.com');
    await user.click(await within(rowOf('bruno@example.com')).findByRole('button', { name: 'Remover' }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Confirmar remoção' }));

    expect(await screen.findByText('Este membro ainda tem despesas neste grupo que impedem a remoção.')).toBeInTheDocument();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getAllByText('bruno@example.com').length).toBeGreaterThan(0);
  });

  it('shows a generic message, not the server text, on any other error', async () => {
    mockApi({ me: 2, createdBy: 1 });
    vi.mocked(axios.delete).mockRejectedValue(apiError(500, { message: 'SQLSTATE[HY000] detalhe interno' }));
    const user = userEvent.setup();
    renderForm();

    await user.click(await screen.findByRole('button', { name: 'Sair do grupo' }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Confirmar saída' }));

    const dialog = screen.getByRole('dialog');
    expect(await within(dialog).findByText('Não foi possível sair do grupo agora. Tente novamente.')).toBeInTheDocument();
    expect(screen.queryByText(/SQLSTATE/)).not.toBeInTheDocument();
    expect(navigateMock).not.toHaveBeenCalled();
  });

  it('blocks a second submit while the request is in flight', async () => {
    mockApi({ me: 2, createdBy: 1 });
    vi.mocked(axios.delete).mockReturnValue(new Promise(() => {}));
    const user = userEvent.setup();
    renderForm();

    await user.click(await screen.findByRole('button', { name: 'Sair do grupo' }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Confirmar saída' }));

    await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Confirmar saída' })).toBeDisabled());
    expect(within(dialog).getByRole('button', { name: 'Cancelar' })).toBeDisabled();
    // Um clique a mais, mesmo que chegue (o `userEvent` nem clica em botão desabilitado), não dispara outro DELETE.
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirmar saída' }));
    expect(axios.delete).toHaveBeenCalledTimes(1);
  });
});
