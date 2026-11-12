import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { GroupMember, MemberResponsible } from '../types/group';
import GroupMembersForm from './GroupMembersForm';

/**
 * Feature 20261109-responsavel-do-devedor (TASK-591, CA-14): em Participantes, o botão "Quem paga por
 * mim" (só na linha do próprio usuário), os textos secundários, os selos "Paga por {nome}" e a frase
 * extra no diálogo de sair e remover. Arquivo próprio: os testes de `GroupMembersForm.test.tsx`,
 * `.exit.test.tsx` e `.invitations.test.tsx` não são editados.
 */

vi.mock('axios');

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => vi.fn(), useParams: () => ({ id: '1' }) };
});

type Pays = MemberResponsible[];

const person = (id: number, name: string, responsibles?: Pays): GroupMember => ({
  id,
  name,
  email: `${name.toLowerCase()}@example.com`,
  avatar_url: null,
  ...(responsibles === undefined ? {} : { responsibles })
});

const who = (id: number, name: string): MemberResponsible => ({ id, name });
const ANA_PAYER = who(1, 'Ana');
const BRUNO_PAYER = who(2, 'Bruno');
const CARLA_PAYER = who(3, 'Carla');

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

const SET_BUTTON = 'Quem paga por mim';

describe('GroupMembersForm: quem paga por quem', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.put).mockReset();
    vi.mocked(axios.put).mockResolvedValue({ data: {} });
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.delete).mockReset();
  });

  describe('botão "Quem paga por mim"', () => {
    it('appears only on the own row, never on the other rows, not even to the group creator', async () => {
      mockApi({ me: 1, createdBy: 1, members: fixed(person(1, 'Ana', []), person(2, 'Bruno', []), person(3, 'Carla', [])) });
      renderForm();

      await screen.findByText('bruno@example.com');
      await screen.findByRole('button', { name: SET_BUTTON });

      expect(screen.getAllByRole('button', { name: SET_BUTTON })).toHaveLength(1);
      expect(within(rowOf('ana@example.com')).getByRole('button', { name: SET_BUTTON })).toBeInTheDocument();
      expect(within(rowOf('bruno@example.com')).queryByRole('button', { name: SET_BUTTON })).not.toBeInTheDocument();
      expect(within(rowOf('carla@example.com')).queryByRole('button', { name: SET_BUTTON })).not.toBeInTheDocument();
    });

    it('appears on the own row of a regular member, who is not the creator', async () => {
      mockApi({ me: 2, createdBy: 1, members: fixed(person(1, 'Ana', []), person(2, 'Bruno', []), person(3, 'Carla', [])) });
      renderForm();

      await screen.findByText('bruno@example.com');
      const button = await screen.findByRole('button', { name: SET_BUTTON });

      expect(screen.getAllByRole('button', { name: SET_BUTTON })).toHaveLength(1);
      expect(within(rowOf('bruno@example.com')).getByRole('button', { name: SET_BUTTON })).toBe(button);
    });

    it('comes before "Sair do grupo" on the own row', async () => {
      mockApi({ me: 1, createdBy: 1, members: fixed(person(1, 'Ana', []), person(2, 'Bruno', [])) });
      renderForm();

      await screen.findByText('bruno@example.com');
      await screen.findByRole('button', { name: SET_BUTTON });
      const buttons = within(rowOf('ana@example.com')).getAllByRole('button');

      // A feature 20261110 (TASK-607) acrescentou "Eu pago por…" entre os dois; a ordem completa está em
      // `GroupMembersForm.paysFor.test.tsx`.
      expect(buttons.map(button => button.textContent)).toEqual([SET_BUTTON, 'Eu pago por…', 'Sair do grupo']);
    });
  });

  describe('textos e selos', () => {
    it('lists, on the own row, who pays for the user (two responsibles)', async () => {
      mockApi({
        members: fixed(person(1, 'Ana', [BRUNO_PAYER, CARLA_PAYER]), person(2, 'Bruno', []), person(3, 'Carla', []))
      });
      renderForm();

      await screen.findByText('bruno@example.com');

      expect(within(rowOf('ana@example.com')).getByText('Quem paga por você: Bruno, Carla')).toBeInTheDocument();
    });

    it('lists, on another member row, who pays for that member, by the member name', async () => {
      mockApi({
        members: fixed(person(1, 'Ana', []), person(2, 'Bruno', [ANA_PAYER, CARLA_PAYER]), person(3, 'Carla', []))
      });
      renderForm();

      await screen.findByText('bruno@example.com');

      expect(within(rowOf('bruno@example.com')).getByText('Quem paga por Bruno: Ana, Carla')).toBeInTheDocument();
      expect(within(rowOf('bruno@example.com')).queryByText(/Quem paga por você/)).not.toBeInTheDocument();
    });

    it('shows one "Paga por {dependente}" badge per dependent on the row of who pays for someone', async () => {
      mockApi({
        members: fixed(person(1, 'Ana', []), person(2, 'Bruno', [ANA_PAYER]), person(3, 'Carla', [ANA_PAYER]))
      });
      renderForm();

      await screen.findByText('bruno@example.com');

      const ana = within(rowOf('ana@example.com'));
      expect(ana.getByText('Paga por Bruno')).toBeInTheDocument();
      expect(ana.getByText('Paga por Carla')).toBeInTheDocument();
      expect(within(rowOf('bruno@example.com')).queryByText(/^Paga por/)).not.toBeInTheDocument();
      expect(within(rowOf('carla@example.com')).queryByText(/^Paga por/)).not.toBeInTheDocument();
    });

    it('shows no text and no badge when nobody pays for anybody, even when the field is missing', async () => {
      mockApi({ members: fixed(person(1, 'Ana'), person(2, 'Bruno', []), person(3, 'Carla')) });
      renderForm();

      await screen.findByText('bruno@example.com');

      expect(screen.queryByText(/Quem paga por (você|Ana|Bruno|Carla):/)).not.toBeInTheDocument();
      expect(screen.queryByText(/^Paga por/)).not.toBeInTheDocument();
    });
  });

  describe('frase extra ao sair ou remover', () => {
    it('warns, when removing someone who pays for others, that this choice will be undone', async () => {
      const user = userEvent.setup();
      mockApi({
        me: 1,
        createdBy: 1,
        members: fixed(person(1, 'Ana', [BRUNO_PAYER]), person(2, 'Bruno', []), person(3, 'Carla', [BRUNO_PAYER]))
      });
      renderForm();

      await screen.findByText('bruno@example.com');
      await user.click(within(rowOf('bruno@example.com')).getByRole('button', { name: 'Remover' }));

      const dialog = within(await screen.findByRole('dialog'));
      expect(dialog.getByText('bruno@example.com também paga por Ana, Carla; essa escolha será desfeita.')).toBeInTheDocument();
    });

    it('warns the same way when the user leaves the group while paying for someone', async () => {
      const user = userEvent.setup();
      mockApi({ me: 1, createdBy: 1, members: fixed(person(1, 'Ana', []), person(2, 'Bruno', [ANA_PAYER])) });
      renderForm();

      await screen.findByText('bruno@example.com');
      await user.click(await screen.findByRole('button', { name: 'Sair do grupo' }));

      const dialog = within(await screen.findByRole('dialog'));
      expect(dialog.getByText('ana@example.com também paga por Bruno; essa escolha será desfeita.')).toBeInTheDocument();
    });

    it('does not add the sentence when the target pays for nobody, even if someone pays for the target', async () => {
      const user = userEvent.setup();
      mockApi({
        me: 1,
        createdBy: 1,
        members: fixed(person(1, 'Ana', []), person(2, 'Bruno', [CARLA_PAYER]), person(3, 'Carla', []))
      });
      renderForm();

      await screen.findByText('bruno@example.com');
      await user.click(within(rowOf('bruno@example.com')).getByRole('button', { name: 'Remover' }));

      const dialog = within(await screen.findByRole('dialog'));
      expect(dialog.getByText(/não apaga as despesas nem as dívidas dessa pessoa/)).toBeInTheDocument();
      expect(dialog.queryByText(/também paga por/)).not.toBeInTheDocument();
    });

    it('does not add the sentence when the user leaves while paying for nobody', async () => {
      const user = userEvent.setup();
      mockApi({ me: 1, createdBy: 1, members: fixed(person(1, 'Ana', [BRUNO_PAYER]), person(2, 'Bruno', [])) });
      renderForm();

      await screen.findByText('bruno@example.com');
      await user.click(await screen.findByRole('button', { name: 'Sair do grupo' }));

      const dialog = within(await screen.findByRole('dialog'));
      expect(dialog.getByText(/Sair do grupo "Casa dos Amigos" não apaga/)).toBeInTheDocument();
      expect(dialog.queryByText(/também paga por/)).not.toBeInTheDocument();
    });
  });

  describe('escolher quem paga', () => {
    const pickBruno = async (user: ReturnType<typeof userEvent.setup>) => {
      await user.click(await screen.findByRole('button', { name: SET_BUTTON }));
      const dialog = await screen.findByRole('dialog', { name: 'Quem pode pagar por você' });
      await user.click(within(dialog).getByRole('combobox', { name: 'Quem paga por você' }));
      await user.click(await screen.findByRole('option', { name: /^Bruno/ }));
      await user.keyboard('{Escape}');
      return dialog;
    };

    it('saves, reloads the members, shows "Escolha salva." and the new text, and closes the dialog', async () => {
      const user = userEvent.setup();
      let saved = false;
      mockApi({
        members: () => [person(1, 'Ana', saved ? [BRUNO_PAYER] : []), person(2, 'Bruno', []), person(3, 'Carla', [])]
      });
      vi.mocked(axios.put).mockImplementation(async () => {
        saved = true;
        return { data: {} };
      });
      renderForm();

      await screen.findByText('bruno@example.com');
      const membersLoadsBefore = vi.mocked(axios.get).mock.calls.filter(([url]) => String(url).endsWith('/api/groups/1/members')).length;
      const dialog = await pickBruno(user);
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      expect(await screen.findByText('Escolha salva.')).toBeInTheDocument();
      expect(await within(rowOf('ana@example.com')).findByText('Quem paga por você: Bruno')).toBeInTheDocument();
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());

      const [url, body] = vi.mocked(axios.put).mock.calls[0];
      expect(url).toMatch(/\/api\/groups\/1\/members\/1\/responsibles$/);
      expect(body).toEqual({ responsible_user_ids: [2] });
      const membersLoadsAfter = vi.mocked(axios.get).mock.calls.filter(([url]) => String(url).endsWith('/api/groups/1/members')).length;
      expect(membersLoadsAfter).toBe(membersLoadsBefore + 1);
    });

    it('opens with the current responsibles and undoes them by saving an empty list', async () => {
      const user = userEvent.setup();
      let saved = false;
      mockApi({
        members: () => [person(1, 'Ana', saved ? [] : [BRUNO_PAYER]), person(2, 'Bruno', []), person(3, 'Carla', [])]
      });
      vi.mocked(axios.put).mockImplementation(async () => {
        saved = true;
        return { data: {} };
      });
      renderForm();

      await screen.findByText('bruno@example.com');
      await within(rowOf('ana@example.com')).findByText('Quem paga por você: Bruno');
      await user.click(screen.getByRole('button', { name: SET_BUTTON }));

      const dialog = within(await screen.findByRole('dialog', { name: 'Quem pode pagar por você' }));
      const selector = dialog.getByRole('combobox', { name: 'Quem paga por você' });
      expect(selector.closest('.MuiAutocomplete-root')!.querySelectorAll('.MuiAutocomplete-tag')).toHaveLength(1);

      await user.click(dialog.getByTitle('Limpar'));
      await user.click(dialog.getByRole('button', { name: 'Salvar' }));

      expect(await screen.findByText('Escolha desfeita.')).toBeInTheDocument();
      expect(vi.mocked(axios.put).mock.calls[0][1]).toEqual({ responsible_user_ids: [] });
      await waitFor(() => expect(within(rowOf('ana@example.com')).queryByText(/Quem paga por você/)).not.toBeInTheDocument());
    });

    it('says so when the choice was saved but the members could not be reloaded', async () => {
      const user = userEvent.setup();
      let reloadFails = false;
      mockApi({
        members: () => [person(1, 'Ana', []), person(2, 'Bruno', []), person(3, 'Carla', [])],
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

      expect(
        await screen.findByText('Escolha salva, mas não foi possível atualizar a lista. Recarregue a página.')
      ).toBeInTheDocument();
      // Só uma mensagem: a de sucesso não fica ao lado do aviso de que a lista não atualizou.
      expect(screen.queryByText('Escolha salva.')).not.toBeInTheDocument();
    });

    it('keeps the dialog open with an error, and the page unchanged, when the PUT fails', async () => {
      const user = userEvent.setup();
      mockApi({ members: fixed(person(1, 'Ana', []), person(2, 'Bruno', []), person(3, 'Carla', [])) });
      vi.mocked(axios.put).mockRejectedValue({ response: { status: 500, data: {} } });
      renderForm();

      await screen.findByText('bruno@example.com');
      const dialog = await pickBruno(user);
      await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

      expect(await within(dialog).findByText('Não foi possível salvar agora. Tente novamente.')).toBeInTheDocument();
      expect(screen.queryByText('Escolha salva.')).not.toBeInTheDocument();
      expect(screen.getByRole('dialog', { name: 'Quem pode pagar por você' })).toBeInTheDocument();
    });
  });

  describe('layout da linha própria (achado V1 da validação local)', () => {
    // O jsdom não mede layout. O que se guarda aqui é a estrutura que impede o aperto medido no
    // navegador (coluna do e-mail com 0 px a ~1024 px): os dois botões numa linha só deles, abaixo do
    // e-mail, em qualquer largura. A largura real foi conferida no navegador (implementation.md §7).
    const ownRow = async () => {
      mockApi({ me: 1, createdBy: 1, members: fixed(person(1, 'Ana', [BRUNO_PAYER]), person(2, 'Bruno', [])) });
      renderForm();
      await screen.findByText('bruno@example.com');
      const row = rowOf('ana@example.com');
      const actions = within(row).getByRole('button', { name: SET_BUTTON }).parentElement as HTMLElement;
      return { row, actions };
    };

    it('puts the two buttons on a line of their own: the row wraps and the buttons take the whole line', async () => {
      const { row, actions } = await ownRow();

      expect(within(actions).getByRole('button', { name: 'Sair do grupo' })).toBeInTheDocument();
      expect(row).toHaveStyle('flex-wrap: wrap');
      expect(actions).toHaveStyle('flex-basis: 100%');
    });

    it('places the buttons after the e-mail text, so that they land below it', async () => {
      const { row, actions } = await ownRow();
      const text = row.querySelector('.MuiListItemText-root') as HTMLElement;

      expect(text.compareDocumentPosition(actions) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
      expect(text).toHaveTextContent('ana@example.com');
    });

    it('leaves the rows of the other members as they were: no wrap, and the Remover button stays beside the e-mail', async () => {
      mockApi({ me: 1, createdBy: 1, members: fixed(person(1, 'Ana', []), person(2, 'Bruno', [])) });
      renderForm();
      await screen.findByText('bruno@example.com');

      const other = rowOf('bruno@example.com');
      const remove = within(other).getByRole('button', { name: 'Remover' });

      expect(other).not.toHaveStyle('flex-wrap: wrap');
      expect(remove.parentElement).toBe(other);
    });

    it('lets the two buttons wrap between themselves when the line is too narrow', async () => {
      const { actions } = await ownRow();

      expect(actions).toHaveStyle('display: flex');
      expect(actions).toHaveStyle('flex-wrap: wrap');
      // Continuam no canto direito, onde ficavam ao lado do e-mail.
      expect(actions).toHaveStyle('justify-content: flex-end');
    });
  });
});
