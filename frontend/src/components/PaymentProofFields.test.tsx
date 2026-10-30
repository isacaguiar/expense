import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import PaymentProofFields, { NOTE_MAX_LENGTH } from './PaymentProofFields';

type Props = React.ComponentProps<typeof PaymentProofFields>;

const baseProps: Props = {
  file: null,
  onFileChange: () => {},
  note: '',
  onNoteChange: () => {},
  photoLabel: 'Selecionar foto',
  noteLabel: 'Descrição'
};

const renderFields = (props: Partial<Props> = {}) => render(<PaymentProofFields {...baseProps} {...props} />);

const fileInput = () => document.querySelector('input[type="file"]') as HTMLInputElement;
const noteField = () => screen.getByLabelText(/Descrição/) as HTMLTextAreaElement;
const photo = () => new File(['x'], 'comprovante.jpg', { type: 'image/jpeg' });

describe('PaymentProofFields', () => {
  it('offers an image picker that has no capture attribute, so the phone library and files stay available', () => {
    renderFields();

    expect(fileInput()).toHaveAttribute('accept', 'image/*');
    expect(fileInput()).not.toHaveAttribute('capture');
  });

  it('shows the label on the photo button until a photo is chosen, then its name', () => {
    const { rerender } = renderFields();

    expect(screen.getByRole('button', { name: /Selecionar foto/ })).toBeInTheDocument();

    rerender(<PaymentProofFields {...baseProps} file={photo()} />);

    expect(screen.getByRole('button', { name: /comprovante\.jpg/ })).toBeInTheDocument();
    expect(screen.queryByText('Selecionar foto')).not.toBeInTheDocument();
  });

  it('labels the note field with the label it was given', () => {
    renderFields({ noteLabel: 'Descrição do pagamento (opcional)' });

    expect(screen.getByLabelText('Descrição do pagamento (opcional)')).toBeInTheDocument();
  });

  it('counts the note up to 500 characters and stops the input there', () => {
    renderFields({ note: 'Pago' });

    expect(NOTE_MAX_LENGTH).toBe(500);
    expect(screen.getByText('4/500')).toBeInTheDocument();
    expect(noteField()).toHaveAttribute('maxlength', '500');
  });

  it('hands the chosen photo to onFileChange, and null when the choice is emptied', async () => {
    const onFileChange = vi.fn();
    renderFields({ onFileChange });

    await userEvent.upload(fileInput(), photo());

    expect(onFileChange).toHaveBeenLastCalledWith(expect.objectContaining({ name: 'comprovante.jpg' }));

    fireEvent.change(fileInput(), { target: { files: [] } });

    expect(onFileChange).toHaveBeenLastCalledWith(null);
  });

  it('hands every typed note to onNoteChange', async () => {
    const onNoteChange = vi.fn();
    renderFields({ onNoteChange });

    await userEvent.type(noteField(), 'Pix');

    // O campo é controlado e `note` fica em '' nesta renderização: cada tecla chega sozinha.
    expect(onNoteChange.mock.calls).toEqual([['P'], ['i'], ['x']]);
  });

  it('disables the photo button and the note field when disabled', () => {
    renderFields({ disabled: true });

    expect(fileInput()).toBeDisabled();
    expect(noteField()).toBeDisabled();
  });

  it('recreates the native input when inputKey changes, so the same file can be picked again', () => {
    const { rerender } = renderFields({ inputKey: 0 });
    const before = fileInput();

    rerender(<PaymentProofFields {...baseProps} inputKey={1} />);

    expect(fileInput()).not.toBe(before);
  });
});
