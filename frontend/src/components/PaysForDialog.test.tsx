import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { GroupMember, MemberResponsible } from '../types/group';
import PaysForDialog from './PaysForDialog';

/**
 * Feature 20261110-quem-paga-se-declara-e-credor-recusa (TASK-607, CA-10): o diálogo "Eu pago por…".
 * Um único `PUT .../me/pays-for` com a lista inteira de devedores; esvaziar o seletor e salvar larga todos.
 */

vi.mock('axios');

const member = (id: number, name: string): GroupMember => ({
  id,
  name,
  email: `${name.toLowerCase()}@example.com`,
  avatar_url: null,
  responsibles: []
});

const ANA = member(1, 'Ana');
const BRUNO = member(2, 'Bruno');
const CARLA = member(3, 'Carla');

const asPerson = ({ id, name }: GroupMember): MemberResponsible => ({ id, name });

const TITLE = 'Por quem você paga';
const SELECTOR = 'Eu pago por';
const INTRO =
  'Você poderá enviar o comprovante dos pagamentos das pessoas escolhidas neste grupo, mesmo que elas não usem o aplicativo. Elas são avisadas e podem desfazer isso em "Quem paga por mim".';
const CREDITOR_NOTE = 'O credor continua decidindo: ele precisa aceitar o comprovante que você enviar por elas.';
const SAVE_ERROR = 'Não foi possível salvar agora. Tente novamente.';

type Props = Partial<React.ComponentProps<typeof PaysForDialog>>;

function renderDialog(props: Props = {}) {
  const onClose = vi.fn();
  const onChanged = vi.fn();
  const view = render(
    <PaysForDialog
      open
      groupId="7"
      currentUserId={1}
      members={[ANA, BRUNO, CARLA]}
      current={[]}
      onClose={onClose}
      onChanged={onChanged}
      {...props}
    />
  );
  return { onClose, onChanged, ...view };
}

/** Abre o seletor, escolhe as pessoas (a lista fica aberta entre escolhas) e a fecha. */
async function pick(user: ReturnType<typeof userEvent.setup>, ...names: string[]) {
  await user.click(screen.getByRole('combobox', { name: SELECTOR }));
  for (const name of names) {
    await user.click(await screen.findByRole('option', { name: new RegExp(`^${name}`) }));
  }
  await user.keyboard('{Escape}');
}

const chips = () =>
  Array.from(
    screen.getByRole('combobox', { name: SELECTOR }).closest('.MuiAutocomplete-root')!.querySelectorAll('.MuiAutocomplete-tag')
  ).map(chip => chip.textContent);

describe('PaysForDialog', () => {
  beforeEach(() => {
    vi.mocked(axios.put).mockReset();
    vi.mocked(axios.put).mockResolvedValue({ data: {} });
  });

  it('explains what the declaration allows and that the creditor still decides', () => {
    renderDialog();

    expect(screen.getByRole('dialog', { name: TITLE })).toBeInTheDocument();
    expect(screen.getByText(INTRO)).toBeInTheDocument();
    expect(screen.getByText(CREDITOR_NOTE)).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: SELECTOR })).toBeInTheDocument();
  });

  it('saves two people with one PUT carrying the whole list, then reports "Escolha salva." and closes', async () => {
    const user = userEvent.setup();
    const { onChanged, onClose } = renderDialog();

    await pick(user, 'Bruno', 'Carla');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(onChanged).toHaveBeenCalledWith('Escolha salva.'));
    expect(axios.put).toHaveBeenCalledTimes(1);
    const [url, body] = vi.mocked(axios.put).mock.calls[0];
    expect(url).toMatch(/\/api\/groups\/7\/me\/pays-for$/);
    expect(body).toEqual({ debtor_user_ids: [2, 3] });
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('sends the bearer token with the PUT', async () => {
    const user = userEvent.setup();
    localStorage.setItem('accessToken', 'abc');
    renderDialog();

    await pick(user, 'Bruno');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.put).toHaveBeenCalled());
    expect(vi.mocked(axios.put).mock.calls[0][2]).toEqual({ headers: { Authorization: 'Bearer abc' } });
    localStorage.removeItem('accessToken');
  });

  it('opens with the people the user already pays for as chips', () => {
    renderDialog({ current: [asPerson(BRUNO), asPerson(CARLA)] });

    expect(chips()).toEqual(['Bruno', 'Carla']);
  });

  it('empties the selector and saves [] to stop paying for everyone, which is allowed', async () => {
    const user = userEvent.setup();
    const { onChanged } = renderDialog({ current: [asPerson(BRUNO), asPerson(CARLA)] });

    // O botão de limpar só fica visível no hover e, escondido, perde o nome acessível: busca pelo título.
    await user.click(screen.getByTitle('Limpar'));
    expect(chips()).toEqual([]);
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(onChanged).toHaveBeenCalledWith('Escolha salva.'));
    expect(vi.mocked(axios.put).mock.calls[0][1]).toEqual({ debtor_user_ids: [] });
  });

  it('never offers the user as an option, but offers the other members with name and e-mail', async () => {
    const user = userEvent.setup();
    renderDialog();

    await user.click(screen.getByRole('combobox', { name: SELECTOR }));
    const options = await screen.findAllByRole('option');

    expect(options.map(option => option.textContent)).toEqual(['Brunobruno@example.com', 'Carlacarla@example.com']);
    expect(screen.queryByRole('option', { name: /ana@example.com/ })).not.toBeInTheDocument();
  });

  it('with no other member, explains how to proceed and offers no selector nor save', () => {
    renderDialog({ members: [ANA] });

    expect(
      screen.getByText('Não há outros membros neste grupo ainda. Convide alguém para poder escolher.')
    ).toBeInTheDocument();
    expect(screen.queryByRole('combobox', { name: SELECTOR })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Salvar' })).toBeDisabled();
  });

  it('shows the error, keeps the dialog open and the choice, and reports nothing when the PUT fails', async () => {
    const user = userEvent.setup();
    vi.mocked(axios.put).mockRejectedValue({ response: { status: 500, data: {} } });
    const { onChanged, onClose } = renderDialog();

    await pick(user, 'Bruno');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(await screen.findByText(SAVE_ERROR)).toBeInTheDocument();
    expect(onChanged).not.toHaveBeenCalled();
    expect(onClose).not.toHaveBeenCalled();
    expect(chips()).toEqual(['Bruno']);
    expect(screen.getByRole('button', { name: 'Salvar' })).toBeEnabled();
  });

  it('sends a single PUT while the first one is still in flight', async () => {
    const user = userEvent.setup();
    let finish: (value: unknown) => void = () => undefined;
    vi.mocked(axios.put).mockReturnValue(new Promise(resolve => (finish = resolve)));
    const { onChanged } = renderDialog();

    await pick(user, 'Bruno');
    const save = screen.getByRole('button', { name: 'Salvar' });
    await user.click(save);
    await waitFor(() => expect(save).toBeDisabled());
    fireEvent.click(save);

    expect(axios.put).toHaveBeenCalledTimes(1);
    finish({ data: {} });
    await waitFor(() => expect(onChanged).toHaveBeenCalledTimes(1));
  });

  it('"Cancelar" closes without sending anything', async () => {
    const user = userEvent.setup();
    const { onChanged, onClose } = renderDialog();

    await pick(user, 'Bruno');
    await user.click(screen.getByRole('button', { name: 'Cancelar' }));

    expect(onClose).toHaveBeenCalledTimes(1);
    expect(axios.put).not.toHaveBeenCalled();
    expect(onChanged).not.toHaveBeenCalled();
  });

  it('discards an unsaved choice and a previous error when it is closed and opened again', async () => {
    const user = userEvent.setup();
    vi.mocked(axios.put).mockRejectedValue(new Error('boom'));
    const { rerender, onClose, onChanged } = renderDialog({ current: [asPerson(CARLA)] });

    await pick(user, 'Bruno');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));
    await screen.findByText(SAVE_ERROR);
    expect(chips()).toEqual(['Carla', 'Bruno']);

    const props = { groupId: '7', currentUserId: 1, members: [ANA, BRUNO, CARLA], current: [asPerson(CARLA)], onClose, onChanged };
    rerender(<PaysForDialog {...props} open={false} />);
    rerender(<PaysForDialog {...props} open />);

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).queryByText(SAVE_ERROR)).not.toBeInTheDocument();
    expect(chips()).toEqual(['Carla']);
  });
});
