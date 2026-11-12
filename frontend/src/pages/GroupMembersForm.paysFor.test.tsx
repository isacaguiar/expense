import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { GroupMember, MemberResponsible } from '../types/group';
import GroupMembersForm from './GroupMembersForm';

/**
 * Feature 20261110-quem-paga-se-declara-e-credor-recusa (TASK-607, CA-10): em Participantes, o botão
 * "Eu pago por…" (só na linha do próprio usuário) e o texto "Você paga por: Ana, Davi", derivado dos
 * `responsibles` dos outros membros (a API não ganhou campo). Arquivo próprio: os testes de
 * `GroupMembersForm.test.tsx`, `.exit.test.tsx` e `.invitations.test.tsx` não são editados.
 */

vi.mock('axios');

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => vi.fn(), useParams: () => ({ id: '1' }) };
});

const person = (id: number, name: string, responsibles: MemberResponsible[] = []): GroupMember => ({
  id,
  name,
  email: `${name.toLowerCase()}@example.com`,
  avatar_url: null,
  responsibles
});

const who = (id: number, name: string): MemberResponsible => ({ id, name });
const ANA_PAYER = who(1, 'Ana');
const BRUNO_PAYER = who(2, 'Bruno');

const group = (createdBy: number) => ({
  id: 1,
  name: 'Casa dos Amigos',
  description: 'Contas da casa',
  created_by: createdBy,
  creator: { id: createdBy, email: 'ana@example.com' }
});

type Opts = { me?: number; createdBy?: number; members: () => GroupMember[]; membersFail?: () => boolean };

/** Responde `/api/me`, `/members` (lido a cada chamada, para simular a lista depois de salvar) e o grupo. */
function mockApi({ me = 1, createdBy = 1, members, membersFail = () => false }: Opts) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.endsWith('/api/me')) return Promise.resolve({ data: { id: me } });
    if (url.endsWith('/invitations')) return Promise.resolve({ data: [] });
    if (url.includes('/members')) {
      return membersFail() ? Promise.reject(new Error('boom')) : Promise.resolve({ data: members() });
    }
    return Promise.resolve({ data: group(createdBy) });
  });
}

const fixed = (...list: GroupMember[]) => () => list;

function renderForm() {
  render(
    <MemoryRouter>
      <GroupMembersForm />
    </MemoryRouter>
  );
}

const rowOf = (email: string): HTMLElement => screen.getByText(email).closest('li') as HTMLElement;

const PAYS_FOR = 'Eu pago por…';
const SET_BUTTON = 'Quem paga por mim';
const DIALOG = 'Por quem você paga';

describe('GroupMembersForm: eu pago por…', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.put).mockReset();
    vi.mocked(axios.put).mockResolvedValue({ data: {} });
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.delete).mockReset();
  });

  describe('botão "Eu pago por…"', () => {
    it('appears only on the own row, never on the other rows, not even to the group creator', async () => {
      mockApi({ me: 1, createdBy: 1, members: fixed(person(1, 'Ana'), person(2, 'Bruno'), person(3, 'Carla')) });
      renderForm();

      await screen.findByText('bruno@example.com');
      await screen.findByRole('button', { name: PAYS_FOR });

      expect(screen.getAllByRole('button', { name: PAYS_FOR })).toHaveLength(1);
      expect(within(rowOf('ana@example.com')).getByRole('button', { name: PAYS_FOR })).toBeInTheDocument();
      expect(within(rowOf('bruno@example.com')).queryByRole('button', { name: PAYS_FOR })).not.toBeInTheDocument();
      expect(within(rowOf('carla@example.com')).queryByRole('button', { name: PAYS_FOR })).not.toBeInTheDocument();
    });

    it('appears on the own row of a regular member, who is not the creator', async () => {
      mockApi({ me: 2, createdBy: 1, members: fixed(person(1, 'Ana'), person(2, 'Bruno'), person(3, 'Carla')) });
      renderForm();

      await screen.findByText('bruno@example.com');
      const button = await screen.findByRole('button', { name: PAYS_FOR });

      expect(screen.getAllByRole('button', { name: PAYS_FOR })).toHaveLength(1);
      expect(within(rowOf('bruno@example.com')).getByRole('button', { name: PAYS_FOR })).toBe(button);
    });

    it('sits between "Quem paga por mim" and "Sair do grupo", in the same group of buttons', async () => {
      mockApi({ me: 1, createdBy: 1, members: fixed(person(1, 'Ana'), person(2, 'Bruno')) });
      renderForm();

      await screen.findByText('bruno@example.com');
      await screen.findByRole('button', { name: PAYS_FOR });
      const buttons = within(rowOf('ana@example.com')).getAllByRole('button');

      expect(buttons.map(button => button.textContent)).toEqual([SET_BUTTON, PAYS_FOR, 'Sair do grupo']);
      expect(new Set(buttons.map(button => button.parentElement)).size).toBe(1);
    });
  });

  describe('texto "Você paga por"', () => {
    it('lists, on the own row, the people the user pays for, derived from the other members', async () => {
      mockApi({
        members: fixed(person(1, 'Ana'), person(2, 'Bruno', [ANA_PAYER]), person(3, 'Carla', [ANA_PAYER]), person(4, 'Davi'))
      });
      renderForm();

      await screen.findByText('bruno@example.com');

      expect(within(rowOf('ana@example.com')).getByText('Você paga por: Bruno, Carla')).toBeInTheDocument();
    });

    it('does not appear when the user pays for nobody', async () => {
      mockApi({ members: fixed(person(1, 'Ana'), person(2, 'Bruno', [who(3, 'Carla')]), person(3, 'Carla')) });
      renderForm();

      await screen.findByText('bruno@example.com');

      expect(screen.queryByText(/Você paga por/)).not.toBeInTheDocument();
    });

    it('does not appear when someone pays for the user but the user pays for nobody', async () => {
      mockApi({ members: fixed(person(1, 'Ana', [who(3, 'Carla')]), person(2, 'Bruno'), person(3, 'Carla')) });
      renderForm();

      await screen.findByText('bruno@example.com');

      expect(within(rowOf('ana@example.com')).getByText('Quem paga por você: Carla')).toBeInTheDocument();
      expect(screen.queryByText(/Você paga por/)).not.toBeInTheDocument();
    });

    it('does not appear on the other rows, even for someone who pays for people', async () => {
      mockApi({
        members: fixed(person(1, 'Ana'), person(2, 'Bruno'), person(3, 'Carla', [BRUNO_PAYER]))
      });
      renderForm();

      await screen.findByText('bruno@example.com');

      expect(screen.queryByText(/Você paga por/)).not.toBeInTheDocument();
      // O selo de quem Bruno paga segue na linha dele, como antes.
      expect(within(rowOf('bruno@example.com')).getByText('Paga por Carla')).toBeInTheDocument();
    });
  });

  describe('declarar por quem paga', () => {
    const pickBruno = async (user: ReturnType<typeof userEvent.setup>) => {
      await user.click(await screen.findByRole('button', { name: PAYS_FOR }));
      const dialog = await screen.findByRole('dialog', { name: DIALOG });
      await user.click(within(dialog).getByRole('combobox', { name: 'Eu pago por' }));
      await user.click(await screen.findByRole('option', { name: /^Bruno/ }));
      await user.keyboard('{Escape}');
      return dialog;
    };

    it('saves, reloads the members, shows "Escolha salva." and the new text, and closes the dialog', async () => {
      const user = userEvent.setup();
      let saved = false;
      mockApi({
        members: () => [person(1, 'Ana'), person(2, 'Bruno', saved ? [ANA_PAYER] : []), person(3, 'Carla')]
      });
      vi.mocked(axios.put).mockImplementation(async () => {
        saved = true;
        return { data: {} };
      });
      renderForm();

      await screen.findByText('bruno@example.com');
      const loadsBefore = vi.mocked(axios.get).mock.calls.filter(([url]) => String(url).endsWith('/api/groups/1/members')).length;
      const dialog = await pickBruno(user);
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      expect(await screen.findByText('Escolha salva.')).toBeInTheDocument();
      expect(await within(rowOf('ana@example.com')).findByText('Você paga por: Bruno')).toBeInTheDocument();
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());

      const [url, body] = vi.mocked(axios.put).mock.calls[0];
      expect(url).toMatch(/\/api\/groups\/1\/me\/pays-for$/);
      expect(body).toEqual({ debtor_user_ids: [2] });
      const loadsAfter = vi.mocked(axios.get).mock.calls.filter(([url]) => String(url).endsWith('/api/groups/1/members')).length;
      expect(loadsAfter).toBe(loadsBefore + 1);
    });

    it('opens with the people already paid for and stops paying for everyone by saving an empty list', async () => {
      const user = userEvent.setup();
      let saved = false;
      mockApi({
        members: () => [person(1, 'Ana'), person(2, 'Bruno', saved ? [] : [ANA_PAYER]), person(3, 'Carla')]
      });
      vi.mocked(axios.put).mockImplementation(async () => {
        saved = true;
        return { data: {} };
      });
      renderForm();

      await screen.findByText('bruno@example.com');
      await within(rowOf('ana@example.com')).findByText('Você paga por: Bruno');
      await user.click(screen.getByRole('button', { name: PAYS_FOR }));

      const dialog = within(await screen.findByRole('dialog', { name: DIALOG }));
      const selector = dialog.getByRole('combobox', { name: 'Eu pago por' });
      expect(selector.closest('.MuiAutocomplete-root')!.querySelectorAll('.MuiAutocomplete-tag')).toHaveLength(1);

      await user.click(dialog.getByTitle('Limpar'));
      await user.click(dialog.getByRole('button', { name: 'Salvar' }));

      expect(await screen.findByText('Escolha salva.')).toBeInTheDocument();
      expect(vi.mocked(axios.put).mock.calls[0][1]).toEqual({ debtor_user_ids: [] });
      await waitFor(() => expect(within(rowOf('ana@example.com')).queryByText(/Você paga por/)).not.toBeInTheDocument());
    });

    it('opens with only the people the user pays for, not everyone who has someone paying for them', async () => {
      const user = userEvent.setup();
      mockApi({
        members: fixed(person(1, 'Ana'), person(2, 'Bruno', [ANA_PAYER]), person(3, 'Carla'), person(4, 'Davi', [who(3, 'Carla')]))
      });
      renderForm();

      await screen.findByText('bruno@example.com');
      await user.click(screen.getByRole('button', { name: PAYS_FOR }));

      const dialog = await screen.findByRole('dialog', { name: DIALOG });
      const tags = within(dialog).getByRole('combobox', { name: 'Eu pago por' }).closest('.MuiAutocomplete-root')!.querySelectorAll('.MuiAutocomplete-tag');
      expect(Array.from(tags).map(tag => tag.textContent)).toEqual(['Bruno']);
    });

    it('says so when the choice was saved but the members could not be reloaded', async () => {
      const user = userEvent.setup();
      let reloadFails = false;
      mockApi({
        members: () => [person(1, 'Ana'), person(2, 'Bruno'), person(3, 'Carla')],
        membersFail: () => reloadFails
      });
      vi.mocked(axios.put).mockImplementation(async () => {
        reloadFails = true;
        return { data: {} };
      });
      renderForm();

      await screen.findByText('bruno@example.com');
      const dialog = await pickBruno(user);
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      expect(await screen.findByText('Escolha salva, mas não foi possível atualizar a lista. Recarregue a página.')).toBeInTheDocument();
      expect(screen.queryByText('Escolha salva.')).not.toBeInTheDocument();
    });

    it('shows the error inside the dialog, keeps it open and shows no success notice when the PUT fails', async () => {
      const user = userEvent.setup();
      mockApi({ members: fixed(person(1, 'Ana'), person(2, 'Bruno'), person(3, 'Carla')) });
      vi.mocked(axios.put).mockRejectedValue({ response: { status: 500, data: {} } });
      renderForm();

      await screen.findByText('bruno@example.com');
      const dialog = await pickBruno(user);
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      expect(await within(dialog).findByText('Não foi possível salvar agora. Tente novamente.')).toBeInTheDocument();
      expect(screen.getByRole('dialog', { name: DIALOG })).toBeInTheDocument();
      expect(screen.queryByText('Escolha salva.')).not.toBeInTheDocument();
    });

    it('opens only the "Eu pago por…" dialog, and the other button only the other one', async () => {
      const user = userEvent.setup();
      mockApi({ members: fixed(person(1, 'Ana'), person(2, 'Bruno')) });
      renderForm();

      await screen.findByText('bruno@example.com');
      await user.click(screen.getByRole('button', { name: PAYS_FOR }));
      expect(await screen.findByRole('dialog', { name: DIALOG })).toBeInTheDocument();
      expect(screen.queryByRole('dialog', { name: 'Quem pode pagar por você' })).not.toBeInTheDocument();
      await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Cancelar' }));
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());

      await user.click(screen.getByRole('button', { name: SET_BUTTON }));
      expect(await screen.findByRole('dialog', { name: 'Quem pode pagar por você' })).toBeInTheDocument();
      expect(screen.queryByRole('dialog', { name: DIALOG })).not.toBeInTheDocument();
    });

    it('clears the previous success notice when the dialog is opened again', async () => {
      const user = userEvent.setup();
      mockApi({ members: fixed(person(1, 'Ana'), person(2, 'Bruno'), person(3, 'Carla')) });
      renderForm();

      await screen.findByText('bruno@example.com');
      const dialog = await pickBruno(user);
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
      expect(await screen.findByText('Escolha salva.')).toBeInTheDocument();
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());

      await user.click(screen.getByRole('button', { name: PAYS_FOR }));

      await waitFor(() => expect(screen.queryByText('Escolha salva.')).not.toBeInTheDocument());
    });
  });
});
