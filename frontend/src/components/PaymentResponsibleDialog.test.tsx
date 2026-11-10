import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { GroupMember, MemberResponsible } from '../types/group';
import PaymentResponsibleDialog from './PaymentResponsibleDialog';

/**
 * Feature 20261109-responsavel-do-devedor (TASK-591, CA-14): o diálogo "Quem pode pagar por você".
 * Um único `PUT .../responsibles` com a lista inteira; esvaziar o seletor e salvar desfaz tudo.
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

const asResponsible = ({ id, name }: GroupMember): MemberResponsible => ({ id, name });

const SELECTOR = 'Quem paga por você';
const SAVE_ERROR = 'Não foi possível salvar agora. Tente novamente.';

type Props = Partial<React.ComponentProps<typeof PaymentResponsibleDialog>>;

function renderDialog(props: Props = {}) {
  const onClose = vi.fn();
  const onChanged = vi.fn();
  const view = render(
    <PaymentResponsibleDialog
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

describe('PaymentResponsibleDialog', () => {
  beforeEach(() => {
    vi.mocked(axios.put).mockReset();
    vi.mocked(axios.put).mockResolvedValue({ data: {} });
  });

  it('explains what the choice allows, and shows the selector with the "Quem pode pagar por você" title', () => {
    renderDialog();

    expect(screen.getByRole('dialog', { name: 'Quem pode pagar por você' })).toBeInTheDocument();
    expect(
      screen.getByText(
        'As pessoas escolhidas poderão enviar o comprovante dos seus pagamentos neste grupo, além de você. Elas não passam a ver nada que não vejam hoje como membros.'
      )
    ).toBeInTheDocument();
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
    expect(url).toMatch(/\/api\/groups\/7\/members\/1\/responsibles$/);
    expect(body).toEqual({ responsible_user_ids: [2, 3] });
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('opens with the current responsibles as chips', () => {
    renderDialog({ current: [asResponsible(BRUNO), asResponsible(CARLA)] });

    expect(chips()).toEqual(['Bruno', 'Carla']);
  });

  it('empties the selector and saves [] to undo everything, reporting "Escolha desfeita."', async () => {
    const user = userEvent.setup();
    const { onChanged } = renderDialog({ current: [asResponsible(BRUNO), asResponsible(CARLA)] });

    // O botão de limpar só fica visível no hover (visibility: hidden no CSS) e, escondido, perde o nome
    // acessível; por isso a busca é pelo título, não por role e nome.
    await user.click(screen.getByTitle('Limpar'));
    expect(chips()).toEqual([]);
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(onChanged).toHaveBeenCalledWith('Escolha desfeita.'));
    expect(vi.mocked(axios.put).mock.calls[0][1]).toEqual({ responsible_user_ids: [] });
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
    const { rerender, onClose, onChanged } = renderDialog({ current: [asResponsible(CARLA)] });

    await pick(user, 'Bruno');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));
    await screen.findByText(SAVE_ERROR);
    expect(chips()).toEqual(['Carla', 'Bruno']);

    const props = { groupId: '7', currentUserId: 1, members: [ANA, BRUNO, CARLA], current: [asResponsible(CARLA)], onClose, onChanged };
    rerender(<PaymentResponsibleDialog {...props} open={false} />);
    rerender(<PaymentResponsibleDialog {...props} open />);

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).queryByText(SAVE_ERROR)).not.toBeInTheDocument();
    expect(chips()).toEqual(['Carla']);
  });
});
