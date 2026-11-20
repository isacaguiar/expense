import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import ProofViewerDialog from './ProofViewerDialog';

/**
 * TASK-653 (plan §1): o diálogo que mostra o comprovante sem sair da tela. Imagem com `alt`, carregamento até o
 * `onLoad`, erro com instrução no `onError`, "Abrir em nova aba" (D2-A) e "Fechar".
 */
const URL = 'https://api.exemplo.com/proofs/9?signature=abc';

const setup = (props: Partial<React.ComponentProps<typeof ProofViewerDialog>> = {}) => {
  const onClose = vi.fn();
  const view = render(<ProofViewerDialog open url={URL} onClose={onClose} {...props} />);
  return { onClose, view, user: userEvent.setup() };
};

const image = () => screen.getByRole('img', { name: 'Comprovante de pagamento' });

describe('ProofViewerDialog', () => {
  it('shows the proof image with a descriptive alt inside a dialog', () => {
    setup();

    expect(screen.getByRole('dialog', { name: 'Comprovante' })).toBeInTheDocument();
    expect(image()).toHaveAttribute('src', URL);
  });

  it('uses the given title when there is one', () => {
    setup({ title: 'Comprovante de João' });

    expect(screen.getByRole('dialog', { name: 'Comprovante de João' })).toBeInTheDocument();
  });

  it('shows a loading indicator until the image loads', () => {
    setup();
    expect(screen.getByRole('progressbar')).toBeInTheDocument();

    fireEvent.load(image());

    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
  });

  it('replaces the image with an instruction when it fails to load (expired signed URL or removed file)', () => {
    setup();

    fireEvent.error(image());

    expect(screen.getByText('Não foi possível carregar o comprovante. Atualize a página.')).toBeInTheDocument();
    expect(screen.queryByRole('img', { name: 'Comprovante de pagamento' })).not.toBeInTheDocument();
    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
  });

  it('offers "Abrir em nova aba" pointing at the same URL (D2-A), even after an error', () => {
    setup();
    fireEvent.error(image());

    const link = screen.getByRole('link', { name: 'Abrir em nova aba' });
    expect(link).toHaveAttribute('href', URL);
    expect(link).toHaveAttribute('target', '_blank');
  });

  it('closes with the Fechar button and with Esc', async () => {
    const { onClose, user } = setup();

    await user.click(screen.getByRole('button', { name: 'Fechar' }));
    expect(onClose).toHaveBeenCalledTimes(1);

    await user.keyboard('{Escape}');
    expect(onClose).toHaveBeenCalledTimes(2);
  });

  it('starts over (loading, no error) when the same dialog gets a new URL', () => {
    const { view, onClose } = setup();
    fireEvent.error(image());
    expect(screen.getByText(/Não foi possível carregar/)).toBeInTheDocument();

    view.rerender(<ProofViewerDialog open url="https://api.exemplo.com/proofs/10?signature=def" onClose={onClose} />);

    expect(screen.queryByText(/Não foi possível carregar/)).not.toBeInTheDocument();
    expect(image()).toHaveAttribute('src', 'https://api.exemplo.com/proofs/10?signature=def');
    expect(screen.getByRole('progressbar')).toBeInTheDocument();
  });

  it('renders nothing when closed', () => {
    setup({ open: false });

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });
});
