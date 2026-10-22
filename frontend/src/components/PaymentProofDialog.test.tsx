import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import PaymentProofDialog, { canConfirmPayment, NOTE_MAX_LENGTH } from './PaymentProofDialog';

type Props = React.ComponentProps<typeof PaymentProofDialog>;

const baseProps: Props = {
  open: true,
  title: 'Confirmar pagamento',
  description: 'Anexe uma foto do comprovante ou descreva o pagamento.',
  photoLabel: 'Selecionar foto',
  requirePhoto: false,
  onClose: () => {},
  onConfirm: () => {}
};

const renderDialog = (props: Partial<Props> = {}) =>
  render(<PaymentProofDialog {...baseProps} {...props} />);

const confirmButton = () => screen.getByRole('button', { name: 'Confirmar' });
const noteField = () => screen.getByLabelText(/Descrição/) as HTMLTextAreaElement;
const fileInput = () => document.querySelector('input[type="file"]') as HTMLInputElement;
const photo = () => new File(['x'], 'comprovante.jpg', { type: 'image/jpeg' });

describe('canConfirmPayment', () => {
  it('requires a photo OR a non-blank note when the photo is optional', () => {
    expect(canConfirmPayment({ requirePhoto: false, hasFile: false, note: '', busy: false })).toBe(false);
    expect(canConfirmPayment({ requirePhoto: false, hasFile: false, note: '   \n\t', busy: false })).toBe(false);
    expect(canConfirmPayment({ requirePhoto: false, hasFile: false, note: 'Pago em dinheiro', busy: false })).toBe(true);
    expect(canConfirmPayment({ requirePhoto: false, hasFile: true, note: '', busy: false })).toBe(true);
    expect(canConfirmPayment({ requirePhoto: false, hasFile: true, note: 'x', busy: false })).toBe(true);
  });

  it('requires the photo when it is mandatory, whatever the note says', () => {
    expect(canConfirmPayment({ requirePhoto: true, hasFile: false, note: 'texto longo', busy: false })).toBe(false);
    expect(canConfirmPayment({ requirePhoto: true, hasFile: true, note: '', busy: false })).toBe(true);
  });

  it('never confirms while busy', () => {
    expect(canConfirmPayment({ requirePhoto: false, hasFile: true, note: 'x', busy: true })).toBe(false);
  });
});

describe('PaymentProofDialog', () => {
  it('renders the title and the help text', () => {
    renderDialog();
    expect(screen.getByText('Confirmar pagamento')).toBeInTheDocument();
    expect(screen.getByText('Anexe uma foto do comprovante ou descreva o pagamento.')).toBeInTheDocument();
  });

  it('keeps Confirmar disabled with no photo and no text', () => {
    renderDialog();
    expect(confirmButton()).toBeDisabled();
  });

  it('enables Confirmar with only a note when the photo is optional', async () => {
    const user = userEvent.setup();
    renderDialog({ requirePhoto: false });

    await user.type(noteField(), 'Pago em dinheiro');

    expect(confirmButton()).toBeEnabled();
  });

  it('enables Confirmar with only a photo when the photo is optional', async () => {
    const user = userEvent.setup();
    renderDialog({ requirePhoto: false });

    await user.upload(fileInput(), photo());

    expect(confirmButton()).toBeEnabled();
    expect(screen.getByText('comprovante.jpg')).toBeInTheDocument();
  });

  it('does not enable Confirmar with a whitespace-only note', async () => {
    const user = userEvent.setup();
    renderDialog({ requirePhoto: false });

    await user.type(noteField(), '   ');

    expect(confirmButton()).toBeDisabled();
  });

  it('does NOT enable Confirmar with text alone when the photo is mandatory', async () => {
    const user = userEvent.setup();
    renderDialog({ requirePhoto: true });

    await user.type(noteField(), 'Pix agendado para sexta');

    expect(confirmButton()).toBeDisabled();
  });

  it('enables Confirmar with the photo when it is mandatory, with no note at all', async () => {
    const user = userEvent.setup();
    renderDialog({ requirePhoto: true });

    await user.upload(fileInput(), photo());

    expect(confirmButton()).toBeEnabled();
  });

  it('keeps Confirmar disabled while busy even with a photo and a note', async () => {
    const user = userEvent.setup();
    renderDialog({ busy: true });

    await user.upload(fileInput(), photo());
    await user.type(noteField(), 'x');

    expect(confirmButton()).toBeDisabled();
  });

  it('calls onConfirm with the file and the trimmed note', async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    renderDialog({ onConfirm });
    const file = photo();

    await user.upload(fileInput(), file);
    await user.type(noteField(), '  ref. 12345  ');
    await user.click(confirmButton());

    expect(onConfirm).toHaveBeenCalledTimes(1);
    expect(onConfirm).toHaveBeenCalledWith({ file, note: 'ref. 12345' });
  });

  it('calls onConfirm with file null when only a note was given', async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    renderDialog({ onConfirm });

    await user.type(noteField(), 'Pago em dinheiro');
    await user.click(confirmButton());

    expect(onConfirm).toHaveBeenCalledWith({ file: null, note: 'Pago em dinheiro' });
  });

  it('limits the note to 500 characters and shows the counter', async () => {
    const user = userEvent.setup();
    renderDialog();

    expect(NOTE_MAX_LENGTH).toBe(500);
    expect(noteField()).toHaveAttribute('maxLength', '500');
    expect(screen.getByText('0/500')).toBeInTheDocument();

    await user.type(noteField(), 'abc');

    expect(screen.getByText('3/500')).toBeInTheDocument();
  });

  it('calls onClose when Cancelar is clicked, without confirming', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    const onConfirm = vi.fn();
    renderDialog({ onClose, onConfirm });

    await user.click(screen.getByRole('button', { name: 'Cancelar' }));

    expect(onClose).toHaveBeenCalledTimes(1);
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it('clears the photo and the note when it is reopened', async () => {
    const user = userEvent.setup();
    const { rerender } = renderDialog();

    await user.upload(fileInput(), photo());
    await user.type(noteField(), 'Pago em dinheiro');
    expect(confirmButton()).toBeEnabled();

    rerender(<PaymentProofDialog {...baseProps} open={false} />);
    rerender(<PaymentProofDialog {...baseProps} open />);

    expect(noteField()).toHaveValue('');
    expect(screen.queryByText('comprovante.jpg')).not.toBeInTheDocument();
    expect(screen.getByText('Selecionar foto')).toBeInTheDocument();
    expect(confirmButton()).toBeDisabled();
  });

  it('shows the error it receives inside the dialog', () => {
    renderDialog({ error: 'Falha ao enviar o comprovante.' });
    expect(screen.getByRole('alert')).toHaveTextContent('Falha ao enviar o comprovante.');
  });

  it('shows no alert when there is no error', () => {
    renderDialog({ error: null });
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('labels the note as optional when the photo is mandatory', () => {
    renderDialog({ requirePhoto: true });
    expect(screen.getByLabelText('Descrição (opcional)')).toBeInTheDocument();
  });

  it('does not render anything when closed', () => {
    renderDialog({ open: false });
    expect(screen.queryByText('Confirmar pagamento')).not.toBeInTheDocument();
  });
});
