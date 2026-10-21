import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import ProofUploadDialog from './ProofUploadDialog';

type Props = Partial<React.ComponentProps<typeof ProofUploadDialog>>;

function renderDialog(props: Props = {}) {
  const onClose = vi.fn();
  const onConfirm = vi.fn();

  const utils = render(
    <ProofUploadDialog
      open
      title="Enviar comprovante do Pix"
      description="Anexe o comprovante do seu pagamento via Pix para confirmar."
      selectLabel="Selecionar comprovante"
      icon={<span data-testid="icone-de-teste" />}
      onClose={onClose}
      onConfirm={onConfirm}
      {...props}
    />
  );

  return { ...utils, onClose, onConfirm };
}

const fileInput = () => document.querySelector('input[type="file"]') as HTMLInputElement;

describe('ProofUploadDialog', () => {
  it('shows the title, the text, the select label and the icon it receives', () => {
    renderDialog();

    expect(screen.getByText('Enviar comprovante do Pix')).toBeInTheDocument();
    expect(screen.getByText('Anexe o comprovante do seu pagamento via Pix para confirmar.')).toBeInTheDocument();
    expect(screen.getByText('Selecionar comprovante')).toBeInTheDocument();
    expect(screen.getByTestId('icone-de-teste')).toBeInTheDocument();
  });

  it('only accepts images and opens the rear camera on phones', () => {
    renderDialog();

    expect(fileInput()).toHaveAttribute('accept', 'image/*');
    expect(fileInput()).toHaveAttribute('capture', 'environment');
  });

  it('keeps "Confirmar" disabled until a file is chosen, then confirms with that file', async () => {
    const user = userEvent.setup();
    const { onConfirm } = renderDialog();

    const confirmButton = screen.getByRole('button', { name: 'Confirmar' });
    expect(confirmButton).toBeDisabled();

    const file = new File(['foto'], 'pix.png', { type: 'image/png' });
    await user.upload(fileInput(), file);

    // O nome do arquivo escolhido substitui o rótulo do botão de seleção.
    expect(screen.getByText('pix.png')).toBeInTheDocument();
    expect(confirmButton).toBeEnabled();

    await user.click(confirmButton);

    expect(onConfirm).toHaveBeenCalledTimes(1);
    expect(onConfirm).toHaveBeenCalledWith(file);
  });

  it('calls onClose on "Cancelar"', async () => {
    const user = userEvent.setup();
    const { onClose, onConfirm } = renderDialog();

    await user.click(screen.getByRole('button', { name: 'Cancelar' }));

    expect(onClose).toHaveBeenCalledTimes(1);
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it('shows the error in an Alert', () => {
    renderDialog({ error: 'Falha ao enviar o comprovante.' });

    expect(screen.getByRole('alert')).toHaveTextContent('Falha ao enviar o comprovante.');
  });

  it('shows no Alert without an error', () => {
    renderDialog({ error: null });

    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('disables both buttons while busy, even with a file chosen', async () => {
    const user = userEvent.setup();
    const { rerender, onClose, onConfirm } = renderDialog();

    await user.upload(fileInput(), new File(['foto'], 'pix.png', { type: 'image/png' }));

    rerender(
      <ProofUploadDialog
        open
        busy
        title="Enviar comprovante do Pix"
        description="Anexe o comprovante do seu pagamento via Pix para confirmar."
        selectLabel="Selecionar comprovante"
        icon={<span />}
        onClose={onClose}
        onConfirm={onConfirm}
      />
    );

    expect(screen.getByRole('button', { name: 'Confirmar' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Cancelar' })).toBeDisabled();
  });

  it('starts empty again every time it is reopened', async () => {
    const user = userEvent.setup();
    const { rerender, onClose, onConfirm } = renderDialog();

    await user.upload(fileInput(), new File(['foto'], 'pix.png', { type: 'image/png' }));
    expect(screen.getByRole('button', { name: 'Confirmar' })).toBeEnabled();

    const dialog = (open: boolean) => (
      <ProofUploadDialog
        open={open}
        title="Enviar comprovante do Pix"
        description="Anexe o comprovante do seu pagamento via Pix para confirmar."
        selectLabel="Selecionar comprovante"
        icon={<span />}
        onClose={onClose}
        onConfirm={onConfirm}
      />
    );

    rerender(dialog(false));
    rerender(dialog(true));

    expect(await screen.findByText('Selecionar comprovante')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Confirmar' })).toBeDisabled();
  });
});
