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

describe('canConfirmPayment in edit mode', () => {
  const base = { requirePhoto: false, hasFile: false, note: '', busy: false };

  it('keeps the current photo as the final photo unless it is being removed', () => {
    expect(canConfirmPayment({ ...base, hasCurrentProof: true })).toBe(true);
    expect(canConfirmPayment({ ...base, hasCurrentProof: true, removeProof: true })).toBe(false);
  });

  it('accepts removing the photo only when a note remains', () => {
    expect(canConfirmPayment({ ...base, hasCurrentProof: true, removeProof: true, note: 'Pago em dinheiro' })).toBe(true);
    expect(canConfirmPayment({ ...base, hasCurrentProof: true, removeProof: true, note: '   ' })).toBe(false);
  });

  it('accepts a new photo even when the current one is marked for removal', () => {
    expect(canConfirmPayment({ ...base, hasFile: true, hasCurrentProof: true, removeProof: true })).toBe(true);
  });

  it('requires a final photo when the photo is mandatory', () => {
    expect(canConfirmPayment({ ...base, requirePhoto: true, hasCurrentProof: true, note: 'x' })).toBe(true);
    expect(canConfirmPayment({ ...base, requirePhoto: true, hasCurrentProof: true, removeProof: true, note: 'x' })).toBe(false);
  });

  it('refuses to confirm when nothing changed, whatever else is true', () => {
    expect(canConfirmPayment({ ...base, hasCurrentProof: true, note: 'x', changed: false })).toBe(false);
    expect(canConfirmPayment({ ...base, hasFile: true, changed: false })).toBe(false);
  });

  it('does not alter the base rule when changed is true or absent', () => {
    expect(canConfirmPayment({ ...base, changed: true })).toBe(false);
    expect(canConfirmPayment({ ...base, note: 'x', changed: true })).toBe(true);
    expect(canConfirmPayment({ ...base, note: 'x' })).toBe(true);
  });

  it('never confirms while busy, even with a change', () => {
    expect(canConfirmPayment({ ...base, hasFile: true, changed: true, busy: true })).toBe(false);
  });
});

describe('PaymentProofDialog in edit mode', () => {
  const editProps: Partial<Props> = {
    title: 'Editar comprovante',
    confirmLabel: 'Salvar',
    photoLabel: 'Trocar a foto',
    initialNote: 'Pix agendado',
    currentProofUrl: 'https://api.exemplo.com/proofs/9?signature=abc'
  };
  const saveButton = () => screen.getByRole('button', { name: 'Salvar' });
  const removeCheckbox = () => screen.getByRole('checkbox', { name: 'Remover a foto atual' });

  it('opens with the description pre-filled from initialNote', () => {
    renderDialog(editProps);
    expect(noteField()).toHaveValue('Pix agendado');
    expect(screen.getByText('12/500')).toBeInTheDocument();
  });

  it('uses confirmLabel for the confirm button and falls back to Confirmar', () => {
    const { unmount } = renderDialog(editProps);
    expect(saveButton()).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Confirmar' })).not.toBeInTheDocument();
    unmount();

    renderDialog();
    expect(confirmButton()).toBeInTheDocument();
  });

  it('shows the link to the current photo and the remove checkbox when there is one', () => {
    renderDialog(editProps);

    const link = screen.getByRole('link', { name: 'Ver foto atual' });
    expect(link).toHaveAttribute('href', 'https://api.exemplo.com/proofs/9?signature=abc');
    expect(link).toHaveAttribute('target', '_blank');
    expect(link.getAttribute('rel')).toContain('noreferrer');
    expect(removeCheckbox()).not.toBeChecked();
  });

  it('shows neither the link nor the checkbox when the expense has no photo', () => {
    renderDialog({ ...editProps, currentProofUrl: null });

    expect(screen.queryByRole('link', { name: 'Ver foto atual' })).not.toBeInTheDocument();
    expect(screen.queryByRole('checkbox', { name: 'Remover a foto atual' })).not.toBeInTheDocument();
  });

  it('shows neither the link nor the checkbox outside edit mode', () => {
    renderDialog();

    expect(screen.queryByRole('link', { name: 'Ver foto atual' })).not.toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  });

  it('marking "Remover a foto atual" clears the chosen photo', async () => {
    const user = userEvent.setup();
    renderDialog(editProps);

    await user.upload(fileInput(), photo());
    expect(screen.getByText('comprovante.jpg')).toBeInTheDocument();

    await user.click(removeCheckbox());

    expect(removeCheckbox()).toBeChecked();
    expect(screen.queryByText('comprovante.jpg')).not.toBeInTheDocument();
    expect(screen.getByText('Trocar a foto')).toBeInTheDocument();
    // O <input> nativo é recriado: sem isso, escolher de novo o mesmo arquivo
    // não dispara `change` e a foto não voltaria a valer.
    expect(fileInput().files).toHaveLength(0);
  });

  it('choosing a photo unchecks "Remover a foto atual"', async () => {
    const user = userEvent.setup();
    renderDialog(editProps);

    await user.click(removeCheckbox());
    expect(removeCheckbox()).toBeChecked();

    await user.upload(fileInput(), photo());

    expect(removeCheckbox()).not.toBeChecked();
    expect(screen.getByText('comprovante.jpg')).toBeInTheDocument();
  });

  it('keeps Salvar disabled until something changes', () => {
    renderDialog(editProps);
    expect(saveButton()).toBeDisabled();
  });

  it('enables Salvar when only the description changes', async () => {
    const user = userEvent.setup();
    renderDialog(editProps);

    await user.type(noteField(), ' ajustado');

    expect(saveButton()).toBeEnabled();
  });

  it('does not count a whitespace-only edit of the description as a change', async () => {
    const user = userEvent.setup();
    renderDialog(editProps);

    await user.type(noteField(), '   ');

    expect(saveButton()).toBeDisabled();
  });

  it('enables Salvar again when the description is put back and then changed', async () => {
    const user = userEvent.setup();
    renderDialog(editProps);

    await user.type(noteField(), 'x');
    expect(saveButton()).toBeEnabled();
    await user.type(noteField(), '{Backspace}');

    expect(saveButton()).toBeDisabled();
  });

  it('enables Salvar when a new photo is chosen', async () => {
    const user = userEvent.setup();
    renderDialog(editProps);

    await user.upload(fileInput(), photo());

    expect(saveButton()).toBeEnabled();
  });

  it('removing the photo keeps Salvar enabled while a description remains', async () => {
    const user = userEvent.setup();
    renderDialog(editProps);

    await user.click(removeCheckbox());

    expect(saveButton()).toBeEnabled();
  });

  it('removing the photo disables Salvar when the description is empty', async () => {
    const user = userEvent.setup();
    renderDialog({ ...editProps, initialNote: '' });

    await user.click(removeCheckbox());

    expect(saveButton()).toBeDisabled();
  });

  it('removing the photo and then clearing the description disables Salvar', async () => {
    const user = userEvent.setup();
    renderDialog(editProps);

    await user.click(removeCheckbox());
    expect(saveButton()).toBeEnabled();
    await user.clear(noteField());

    expect(saveButton()).toBeDisabled();
  });

  it('clearing the description is fine while the current photo stays', async () => {
    const user = userEvent.setup();
    renderDialog(editProps);

    await user.clear(noteField());

    expect(saveButton()).toBeEnabled();
  });

  it('sends removeProof along with the file and the trimmed note on confirm', async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    renderDialog({ ...editProps, onConfirm });

    await user.click(removeCheckbox());
    await user.click(saveButton());

    expect(onConfirm).toHaveBeenCalledTimes(1);
    expect(onConfirm).toHaveBeenCalledWith({ file: null, note: 'Pix agendado', removeProof: true });
  });

  it('sends removeProof false when only the description changed', async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    renderDialog({ ...editProps, onConfirm });

    await user.type(noteField(), '  ');
    await user.type(noteField(), ' ok ');
    await user.click(saveButton());

    expect(onConfirm).toHaveBeenCalledWith({ file: null, note: 'Pix agendado   ok', removeProof: false });
  });

  it('sends the new photo with removeProof false', async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    renderDialog({ ...editProps, onConfirm });
    const file = photo();

    await user.upload(fileInput(), file);
    await user.click(saveButton());

    expect(onConfirm).toHaveBeenCalledWith({ file, note: 'Pix agendado', removeProof: false });
  });

  it('lets the creditor attach a first photo to an expense that has neither photo nor description', async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    renderDialog({ ...editProps, initialNote: '', currentProofUrl: null, onConfirm });
    expect(noteField()).toHaveValue('');
    expect(saveButton()).toBeDisabled();
    const file = photo();

    await user.upload(fileInput(), file);
    expect(saveButton()).toBeEnabled();
    await user.click(saveButton());

    expect(onConfirm).toHaveBeenCalledWith({ file, note: '', removeProof: false });
  });

  it('does not call onConfirm when Salvar is disabled', async () => {
    // O `userEvent` recusa clicar em botão desabilitado; desligar a checagem
    // de ponteiro deixa o clique acontecer e prova que nada é enviado.
    const user = userEvent.setup({ pointerEventsCheck: 0 });
    const onConfirm = vi.fn();
    renderDialog({ ...editProps, onConfirm });

    await user.click(saveButton());

    expect(onConfirm).not.toHaveBeenCalled();
  });

  it('does not put removeProof in the value outside edit mode', async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    renderDialog({ onConfirm });

    await user.type(noteField(), 'Pago em dinheiro');
    await user.click(confirmButton());

    expect(onConfirm.mock.calls[0][0]).not.toHaveProperty('removeProof');
  });

  it('starts every opening from the current state: description restored and chosen photo dropped', async () => {
    const user = userEvent.setup();
    const { rerender } = renderDialog(editProps);

    await user.type(noteField(), ' mudado');
    await user.upload(fileInput(), photo());
    expect(saveButton()).toBeEnabled();

    rerender(<PaymentProofDialog {...baseProps} {...editProps} open={false} />);
    rerender(<PaymentProofDialog {...baseProps} {...editProps} open />);

    expect(noteField()).toHaveValue('Pix agendado');
    expect(screen.queryByText('comprovante.jpg')).not.toBeInTheDocument();
    expect(saveButton()).toBeDisabled();
  });

  it('starts every opening with "Remover a foto atual" unchecked', async () => {
    const user = userEvent.setup();
    const { rerender } = renderDialog(editProps);

    // Este cenário termina com o checkbox marcado (escolher uma foto o
    // desmarcaria), para provar que é a reabertura que o zera.
    await user.click(removeCheckbox());
    expect(removeCheckbox()).toBeChecked();

    rerender(<PaymentProofDialog {...baseProps} {...editProps} open={false} />);
    rerender(<PaymentProofDialog {...baseProps} {...editProps} open />);

    expect(removeCheckbox()).not.toBeChecked();
    expect(saveButton()).toBeDisabled();
  });

  it('takes the description of the target it is reopened with, not the previous one', () => {
    const { rerender } = renderDialog(editProps);

    rerender(<PaymentProofDialog {...baseProps} {...editProps} open={false} />);
    rerender(<PaymentProofDialog {...baseProps} {...editProps} initialNote="Outra despesa" open />);

    expect(noteField()).toHaveValue('Outra despesa');
  });

  it('does not overwrite what the user is typing when the parent re-renders with a new initialNote while open', async () => {
    const user = userEvent.setup();
    const { rerender } = renderDialog(editProps);

    await user.type(noteField(), ' digitando');
    rerender(<PaymentProofDialog {...baseProps} {...editProps} initialNote="Recarregou" />);

    expect(noteField()).toHaveValue('Pix agendado digitando');
  });

  it('disables the remove checkbox while busy', () => {
    renderDialog({ ...editProps, busy: true });
    expect(removeCheckbox()).toBeDisabled();
  });

  it('renders the description as plain text, never as markup', () => {
    renderDialog({ ...editProps, initialNote: '<img src=x onerror="alert(1)"><b>negrito</b>' });

    expect(noteField()).toHaveValue('<img src=x onerror="alert(1)"><b>negrito</b>');
    expect(document.querySelector('img')).toBeNull();
    expect(document.querySelector('b')).toBeNull();
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
