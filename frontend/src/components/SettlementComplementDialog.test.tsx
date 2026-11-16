import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import SettlementComplementDialog from './SettlementComplementDialog';

/**
 * TASK-634 (plan §11): o diálogo "Adicionar justificativa". "Enviar" só habilita com foto **e** justificativa
 * não vazia; "Cancelar" não envia; tudo desabilita com `busy`; a justificativa vai como texto.
 */
const setup = (props: Partial<React.ComponentProps<typeof SettlementComplementDialog>> = {}) => {
  const onSubmit = vi.fn();
  const onCancel = vi.fn();
  render(<SettlementComplementDialog open debtorName="João" busy={false} onSubmit={onSubmit} onCancel={onCancel} {...props} />);
  return { onSubmit, onCancel, user: userEvent.setup() };
};

const send = () => screen.getByRole('button', { name: 'Enviar' });
const field = () => screen.getByLabelText('Justificativa');
const fileInput = () => document.querySelector('input[type="file"]') as HTMLInputElement;
const photo = () => new File(['x'], 'prova.jpg', { type: 'image/jpeg' });

describe('SettlementComplementDialog', () => {
  it('shows the title, the explanation naming the debtor, the photo button and the 0/500 counter', () => {
    setup();

    expect(screen.getByRole('dialog', { name: 'Adicionar justificativa' })).toBeInTheDocument();
    expect(
      screen.getByText(
        /Use quando o comprovante enviado por João está errado ou incompleto\. O comprovante atual continua valendo; o credor e João são avisados\./
      )
    ).toBeInTheDocument();
    expect(screen.getByText('Escolher foto do comprovante')).toBeInTheDocument();
    expect(field()).toHaveAttribute('maxlength', '500');
    expect(screen.getByText('0/500')).toBeInTheDocument();
  });

  it('starts with Enviar disabled and needs both the photo and a non-blank justification', async () => {
    const { user } = setup();
    expect(send()).toBeDisabled();

    await user.type(field(), 'o pix foi de outra conta');
    expect(send()).toBeDisabled();

    await user.upload(fileInput(), photo());
    expect(send()).toBeEnabled();

    await user.clear(field());
    await user.type(field(), '   ');
    expect(send()).toBeDisabled();
  });

  it('a photo alone is not enough', async () => {
    const { user } = setup();

    await user.upload(fileInput(), photo());

    expect(send()).toBeDisabled();
  });

  it('Enviar hands over the photo and the trimmed justification', async () => {
    const { user, onSubmit } = setup();
    const file = photo();

    await user.upload(fileInput(), file);
    await user.type(field(), '  o pix foi de outra conta  ');
    await user.click(send());

    expect(onSubmit).toHaveBeenCalledWith(file, 'o pix foi de outra conta');
  });

  it('Cancelar sends nothing', async () => {
    const { user, onSubmit, onCancel } = setup();

    await user.type(field(), 'x');
    await user.click(screen.getByRole('button', { name: 'Cancelar' }));

    expect(onCancel).toHaveBeenCalledTimes(1);
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('disables everything while busy', () => {
    const { onSubmit } = setup({ busy: true });

    expect(send()).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Cancelar' })).toBeDisabled();
    expect(field()).toBeDisabled();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('shows the error from the server', () => {
    setup({ error: 'O limite foi atingido.' });

    expect(screen.getByText('O limite foi atingido.')).toBeInTheDocument();
  });

  it('starts empty every time it opens', async () => {
    const props = { debtorName: 'João', busy: false, onSubmit: vi.fn(), onCancel: vi.fn() };
    const { rerender } = render(<SettlementComplementDialog open {...props} />);
    await userEvent.type(field(), 'um texto');

    rerender(<SettlementComplementDialog open={false} {...props} />);
    rerender(<SettlementComplementDialog open {...props} />);

    expect(field()).toHaveValue('');
    expect(send()).toBeDisabled();
  });
});
