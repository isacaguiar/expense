import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import RefuseReasonDialog from './RefuseReasonDialog';

/**
 * TASK-630 (feature 20261114-historico-de-comprovantes-e-justificativa, plan §12): recusar o comprovante pede
 * um motivo opcional de até 500 caracteres; "Cancelar" não chama nada e "Recusar" entrega o texto aparado,
 * ou `undefined` quando vazio.
 */
const setup = (props: Partial<React.ComponentProps<typeof RefuseReasonDialog>> = {}) => {
  const onConfirm = vi.fn();
  const onCancel = vi.fn();
  render(<RefuseReasonDialog open busy={false} onConfirm={onConfirm} onCancel={onCancel} {...props} />);
  return { onConfirm, onCancel, user: userEvent.setup() };
};

const dialog = () => screen.getByRole('dialog', { name: 'Recusar comprovante?' });
const field = () => screen.getByLabelText('Motivo (opcional)');

describe('RefuseReasonDialog', () => {
  it('shows the title, the optional reason field and the 0/500 counter', () => {
    setup();

    expect(dialog()).toBeInTheDocument();
    expect(field()).toHaveAttribute('maxlength', '500');
    expect(screen.getByText('0/500')).toBeInTheDocument();
  });

  it('renders nothing when closed', () => {
    setup({ open: false });

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('Cancelar calls only onCancel', async () => {
    const { user, onConfirm, onCancel } = setup();

    await user.type(field(), 'foto cortada');
    await user.click(screen.getByRole('button', { name: 'Cancelar' }));

    expect(onCancel).toHaveBeenCalledTimes(1);
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it('Recusar hands over the trimmed reason and the counter follows the typing', async () => {
    const { user, onConfirm } = setup();

    await user.type(field(), '  foto cortada  ');
    expect(screen.getByText('16/500')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Recusar' }));

    expect(onConfirm).toHaveBeenCalledWith('foto cortada');
  });

  it.each(['', '   '])('Recusar with an empty reason (%j) hands over undefined', async typed => {
    const { user, onConfirm } = setup();

    if (typed) await user.type(field(), typed);
    await user.click(screen.getByRole('button', { name: 'Recusar' }));

    expect(onConfirm).toHaveBeenCalledWith(undefined);
  });

  it('disables both buttons while busy', () => {
    setup({ busy: true });

    expect(screen.getByRole('button', { name: 'Cancelar' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Recusar' })).toBeDisabled();
  });

  it('starts empty every time it opens', async () => {
    const onConfirm = vi.fn();
    const { rerender } = render(<RefuseReasonDialog open busy={false} onConfirm={onConfirm} onCancel={vi.fn()} />);
    await userEvent.type(field(), 'um motivo');

    rerender(<RefuseReasonDialog open={false} busy={false} onConfirm={onConfirm} onCancel={vi.fn()} />);
    rerender(<RefuseReasonDialog open busy={false} onConfirm={onConfirm} onCancel={vi.fn()} />);

    expect(field()).toHaveValue('');
  });
});
