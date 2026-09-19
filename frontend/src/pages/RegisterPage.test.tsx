import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RegisterPage from './RegisterPage';
import { API_BASE_URL } from '../config';

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => vi.fn(),
  };
});

const okPreRegister = {
  ok: true,
  json: async () => ({
    message: 'Enviamos um código de confirmação para o seu e-mail.',
    expires_in_seconds: 900,
    resend_available_in: 60,
  }),
};

function renderPage() {
  return render(
    <MemoryRouter>
      <RegisterPage />
    </MemoryRouter>
  );
}

/** Preenche o formulário inteiro com dados válidos, sobrescrevendo o que for passado. */
async function fillForm(user: ReturnType<typeof userEvent.setup>, overrides: Record<string, string> = {}) {
  const values: Record<string, string> = {
    Nome: 'Maria Souza',
    'E-mail': 'maria@example.com',
    'Confirmar e-mail': 'maria@example.com',
    Senha: 'senha-forte-123',
    'Confirmar senha': 'senha-forte-123',
    ...overrides,
  };

  await user.type(screen.getByLabelText(/^Nome/), values.Nome);
  await user.type(screen.getByLabelText(/^E-mail/), values['E-mail']);
  await user.type(screen.getByLabelText(/^Confirmar e-mail/), values['Confirmar e-mail']);
  await user.type(screen.getByLabelText(/^Senha/), values.Senha);
  await user.type(screen.getByLabelText(/^Confirmar senha/), values['Confirmar senha']);
}

describe('RegisterPage', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(okPreRegister));
    localStorage.clear();
  });

  it('renders every field of the registration form', () => {
    renderPage();

    expect(screen.getByLabelText(/^Nome/)).toBeInTheDocument();
    expect(screen.getByLabelText(/^E-mail/)).toBeInTheDocument();
    expect(screen.getByLabelText(/^Confirmar e-mail/)).toBeInTheDocument();
    expect(screen.getByLabelText(/^Telefone/)).toBeInTheDocument();
    expect(screen.getByLabelText(/^Senha/)).toBeInTheDocument();
    expect(screen.getByLabelText(/^Confirmar senha/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Criar conta' })).toBeInTheDocument();
  });

  it('shows a field-level error when the e-mails do not match, without calling the API', async () => {
    const user = userEvent.setup();
    renderPage();

    await fillForm(user, { 'Confirmar e-mail': 'outro@example.com' });
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));

    expect(screen.getByText('Os e-mails não conferem.')).toBeInTheDocument();
    expect(fetch).not.toHaveBeenCalled();
  });

  it('shows a field-level error when the passwords do not match, without calling the API', async () => {
    const user = userEvent.setup();
    renderPage();

    await fillForm(user, { 'Confirmar senha': 'outra-senha-456' });
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));

    expect(screen.getByText('As senhas não conferem.')).toBeInTheDocument();
    expect(fetch).not.toHaveBeenCalled();
  });

  it('masks the phone as the user types', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.type(screen.getByLabelText(/^Telefone/), '11912345678');

    expect(screen.getByLabelText(/^Telefone/)).toHaveValue('(11) 91234-5678');
  });

  it('posts the form to the pre-register endpoint and moves on to the code step', async () => {
    const user = userEvent.setup();
    renderPage();

    await fillForm(user);
    await user.type(screen.getByLabelText(/^Telefone/), '11912345678');
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));

    expect(fetch).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/pre-register`,
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({
          name: 'Maria Souza',
          email: 'maria@example.com',
          email_confirmation: 'maria@example.com',
          whatsapp: '(11) 91234-5678',
          password: 'senha-forte-123',
          password_confirmation: 'senha-forte-123',
        }),
      })
    );

    expect(await screen.findByText(/Enviamos um código de confirmação/)).toBeInTheDocument();
  });

  it('sends whatsapp as null when the optional phone is left empty', async () => {
    const user = userEvent.setup();
    renderPage();

    await fillForm(user);
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));

    const body = JSON.parse(vi.mocked(fetch).mock.calls[0][1]!.body as string);
    expect(body.whatsapp).toBeNull();
  });

  it('maps a 422 from the API back onto the offending field', async () => {
    vi.mocked(fetch).mockResolvedValue({
      ok: false,
      json: async () => ({
        message: 'The given data was invalid.',
        errors: { email: ['Este e-mail já está cadastrado.'] },
      }),
    } as unknown as Response);

    const user = userEvent.setup();
    renderPage();

    await fillForm(user);
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));

    expect(await screen.findByText('Este e-mail já está cadastrado.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Criar conta' })).toBeInTheDocument();
  });

  it('surfaces a 429 cooldown message from the API', async () => {
    vi.mocked(fetch).mockResolvedValue({
      ok: false,
      json: async () => ({
        message: 'Já enviamos um código para este e-mail. Aguarde 42 segundos para pedir outro.',
        retry_after: 42,
      }),
    } as unknown as Response);

    const user = userEvent.setup();
    renderPage();

    await fillForm(user);
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));

    expect(await screen.findByText(/Aguarde 42 segundos/)).toBeInTheDocument();
  });
});
