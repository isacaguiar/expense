import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import useProofViewer from './useProofViewer';

/**
 * TASK-654 (plan §2): o gancho que liga um link de comprovante ao `ProofViewerDialog`. O gatilho mantém o
 * `href` (papel `link`, botão do meio e `ctrl+clique` seguem abrindo em outra aba) e só o clique simples
 * abre o modal.
 */
const URL_A = 'https://api.exemplo.com/proofs/1?signature=a';
const URL_B = 'https://api.exemplo.com/proofs/2?signature=b';

const Harness = () => {
  const { proofLinkProps, proofViewer } = useProofViewer();
  return (
    <>
      <a {...proofLinkProps(URL_A)}>Ver A</a>
      <a {...proofLinkProps(URL_B)}>Ver B</a>
      {proofViewer}
    </>
  );
};

const dialog = () => screen.queryByRole('dialog');

describe('useProofViewer', () => {
  it('gives the trigger the href, the dialog hint and no target', () => {
    render(<Harness />);

    const link = screen.getByRole('link', { name: 'Ver A' });
    expect(link).toHaveAttribute('href', URL_A);
    expect(link).toHaveAttribute('aria-haspopup', 'dialog');
    expect(link).not.toHaveAttribute('target');
    expect(dialog()).not.toBeInTheDocument();
  });

  it('opens the dialog with that link\'s URL on a plain click, without navigating', async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.click(screen.getByRole('link', { name: 'Ver B' }));

    expect(dialog()).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Comprovante de pagamento' })).toHaveAttribute('src', URL_B);
  });

  it('prevents the default navigation on a plain click', () => {
    render(<Harness />);
    const link = screen.getByRole('link', { name: 'Ver A' });

    const notPrevented = link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));

    expect(notPrevented).toBe(false);
  });

  it.each([['ctrlKey'], ['metaKey'], ['shiftKey'], ['altKey']])('does not open nor prevent the default with %s', key => {
    render(<Harness />);
    const link = screen.getByRole('link', { name: 'Ver A' });

    const notPrevented = link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, [key]: true }));

    expect(notPrevented).toBe(true);
    expect(dialog()).not.toBeInTheDocument();
  });

  it('does not open nor prevent the default for a non-primary button', () => {
    render(<Harness />);
    const link = screen.getByRole('link', { name: 'Ver A' });

    const notPrevented = link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 1 }));

    expect(notPrevented).toBe(true);
    expect(dialog()).not.toBeInTheDocument();
  });

  it('closes with Fechar and gives the focus back to the trigger', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    const link = screen.getByRole('link', { name: 'Ver A' });

    await user.click(link);
    expect(dialog()).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Fechar' }));

    await waitFor(() => expect(dialog()).not.toBeInTheDocument());
    expect(link).toHaveFocus();
  });
});

