import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RegisterPage from './RegisterPage';
import { API_BASE_URL } from '../config';
import { trackEvent } from '../analytics/trackEvent';

// O contrato da página com a camada de analytics é a chamada em si (o que o
// `trackEvent` envia ao GA já é coberto em `analytics/trackEvent.test.ts`).
vi.mock('../analytics/trackEvent', () => ({ trackEvent: vi.fn() }));

const navigateMock = vi.fn();

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => navigateMock,
  };
});

const HANDLE = 'a'.repeat(64);

const okPreRegister = {
  ok: true,
  json: async () => ({
    message: 'Enviamos um código de confirmação para o seu e-mail.',
    handle: HANDLE,
    expires_in_seconds: 900,
    resend_available_in: 60,
  }),
};

const okVerify = {
  ok: true,
  json: async () => ({ access_token: 'token-123', token_type: 'bearer', expires_in: 3600 }),
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
    navigateMock.mockClear();
    vi.mocked(trackEvent).mockClear();
    localStorage.clear();
  });

  /** Leva a página até a etapa do código, com o pré-cadastro já aceito. */
  async function advanceToCodeStep(user: ReturnType<typeof userEvent.setup>) {
    await fillForm(user);
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));
    await screen.findByLabelText(/Código de confirmação/);
  }

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

    expect(await screen.findByLabelText(/Código de confirmação/)).toBeInTheDocument();
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

  it('confirms the code, stores the session and lands on the groups page', async () => {
    const user = userEvent.setup();
    renderPage();
    await advanceToCodeStep(user);

    vi.mocked(fetch).mockResolvedValue(okVerify as unknown as Response);

    await user.type(screen.getByLabelText(/Código de confirmação/), '123456');
    await user.click(screen.getByRole('button', { name: 'Confirmar e entrar' }));

    await vi.waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/meus-grupos'));

    // O handle devolvido pelo pré-cadastro precisa voltar na confirmação --
    // é ele que amarra a confirmação a quem submeteu o formulário.
    const verifyCall = vi.mocked(fetch).mock.calls.at(-1)!;
    expect(verifyCall[0]).toBe(`${API_BASE_URL}/api/pre-register/verify`);
    expect(JSON.parse(verifyCall[1]!.body as string)).toEqual({
      email: 'maria@example.com',
      handle: HANDLE,
      code: '123456',
    });

    expect(localStorage.getItem('accessToken')).toBe('token-123');
  });

  it('keeps the user on the code step and shows the error when the code is wrong', async () => {
    const user = userEvent.setup();
    renderPage();
    await advanceToCodeStep(user);

    vi.mocked(fetch).mockResolvedValue({
      ok: false,
      json: async () => ({
        message: 'The given data was invalid.',
        errors: { code: ['Código inválido ou expirado. Confira o e-mail ou peça um novo código.'] },
      }),
    } as unknown as Response);

    await user.type(screen.getByLabelText(/Código de confirmação/), '000000');
    await user.click(screen.getByRole('button', { name: 'Confirmar e entrar' }));

    expect(await screen.findByText(/Código inválido ou expirado/)).toBeInTheDocument();
    expect(screen.getByLabelText(/Código de confirmação/)).toBeInTheDocument();
    expect(navigateMock).not.toHaveBeenCalled();
    expect(localStorage.getItem('accessToken')).toBeNull();
  });

  it('only accepts digits in the code field, capped at six', async () => {
    const user = userEvent.setup();
    renderPage();
    await advanceToCodeStep(user);

    await user.type(screen.getByLabelText(/Código de confirmação/), 'a1b2c3d4e5f6g7');

    expect(screen.getByLabelText(/Código de confirmação/)).toHaveValue('123456');
  });

  it('disables the resend button while the cooldown returned by the API is running', async () => {
    const user = userEvent.setup();
    renderPage();
    await advanceToCodeStep(user);

    // resend_available_in = 60 veio do pré-cadastro.
    expect(screen.getByRole('button', { name: /Reenviar código em \d+s/ })).toBeDisabled();
  });

  it('goes back to the form when the user picks the wrong-email link', async () => {
    const user = userEvent.setup();
    renderPage();
    await advanceToCodeStep(user);

    await user.click(screen.getByRole('button', { name: 'Voltar e corrigir' }));

    expect(await screen.findByRole('button', { name: 'Criar conta' })).toBeInTheDocument();
  });

  describe('medição do cadastro (sign_up)', () => {
    async function confirmWith(user: ReturnType<typeof userEvent.setup>, code: string) {
      await user.type(screen.getByLabelText(/Código de confirmação/), code);
      await user.click(screen.getByRole('button', { name: 'Confirmar e entrar' }));
    }

    // Pega a medição que não existe, ou que dispara com método errado.
    it('mede sign_up com method email, uma vez, quando o código é confirmado', async () => {
      const user = userEvent.setup();
      renderPage();
      await advanceToCodeStep(user);

      vi.mocked(fetch).mockResolvedValue(okVerify as unknown as Response);
      await confirmWith(user, '123456');

      await vi.waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/meus-grupos'));
      expect(trackEvent).toHaveBeenCalledTimes(1);
      expect(trackEvent).toHaveBeenCalledWith('sign_up', { method: 'email' });
    });

    // Pega o evento disparado depois do navigate: a tela desmonta e o envio fica
    // à mercê do que acontecer na rota seguinte.
    it('mede antes de navegar para a página de grupos', async () => {
      const user = userEvent.setup();
      renderPage();
      await advanceToCodeStep(user);

      vi.mocked(fetch).mockResolvedValue(okVerify as unknown as Response);
      await confirmWith(user, '123456');

      await vi.waitFor(() => expect(navigateMock).toHaveBeenCalled());
      expect(vi.mocked(trackEvent).mock.invocationCallOrder[0]).toBeLessThan(
        navigateMock.mock.invocationCallOrder[0]
      );
    });

    // Pega a medição colocada no pré-cadastro: contaria quem nunca confirmou o
    // e-mail e ainda não tem conta.
    it('não mede quando o pré-cadastro é aceito e o código ainda não foi confirmado', async () => {
      const user = userEvent.setup();
      renderPage();
      await advanceToCodeStep(user);

      expect(trackEvent).not.toHaveBeenCalled();
    });

    it('não mede quando o código está errado', async () => {
      const user = userEvent.setup();
      renderPage();
      await advanceToCodeStep(user);

      vi.mocked(fetch).mockResolvedValue({
        ok: false,
        json: async () => ({
          message: 'The given data was invalid.',
          errors: { code: ['Código inválido ou expirado. Confira o e-mail ou peça um novo código.'] },
        }),
      } as unknown as Response);
      await confirmWith(user, '000000');

      expect(await screen.findByText(/Código inválido ou expirado/)).toBeInTheDocument();
      expect(trackEvent).not.toHaveBeenCalled();
    });

    it('não mede quando a confirmação falha por erro de rede', async () => {
      const user = userEvent.setup();
      renderPage();
      await advanceToCodeStep(user);

      vi.mocked(fetch).mockRejectedValue(new Error('Failed to fetch'));
      vi.spyOn(console, 'error').mockImplementation(() => {});
      await confirmWith(user, '123456');

      expect(await screen.findByText(/Verifique sua conexão/)).toBeInTheDocument();
      expect(trackEvent).not.toHaveBeenCalled();
    });

    // Pega a medição no reenvio: reenviar o código não é um novo cadastro.
    it('não mede quando o código é reenviado', async () => {
      vi.mocked(fetch).mockResolvedValue({
        ok: true,
        json: async () => ({
          message: 'Enviamos um código de confirmação para o seu e-mail.',
          handle: HANDLE,
          expires_in_seconds: 900,
          resend_available_in: 0,
        }),
      } as unknown as Response);
      const user = userEvent.setup();
      renderPage();
      await advanceToCodeStep(user);

      await user.click(screen.getByRole('button', { name: 'Reenviar código' }));

      expect(await screen.findByText(/Enviamos um novo código/)).toBeInTheDocument();
      expect(trackEvent).not.toHaveBeenCalled();
    });
  });

  /**
   * Dublê do backend para uma falha de validação. O Laravel só devolve o `422` em JSON a quem
   * pede JSON (`Accept: application/json`); sem isso responde `302` para a origem do frontend,
   * que o navegador bloqueia por CORS, e o `fetch` rejeita com `TypeError`. Comprovado com
   * `curl` e no navegador em `docs/bugfix/20261006-cadastro-fetch-sem-accept-json.md`, §1.
   * Os mocks acima respondem `{ ok: false, json }` para qualquer request e por isso nunca
   * pegaram o defeito.
   */
  function validationFailure(body: object) {
    return vi.fn(async (_url: RequestInfo | URL, init?: RequestInit) => {
      const accept = new Headers(init?.headers).get('Accept') ?? '';

      if (!accept.includes('application/json')) {
        throw new TypeError('Failed to fetch');
      }

      return { ok: false, status: 422, json: async () => body } as unknown as Response;
    });
  }

  describe('validation errors from the API reach the user (regression: requests sent no Accept header)', () => {
    // Pega o `fetch` do pré-cadastro sem `Accept`: o e-mail repetido viraria "verifique sua conexão".
    it('shows the field error instead of a connection error when pre-register validation fails', async () => {
      vi.stubGlobal(
        'fetch',
        validationFailure({
          message: 'The given data was invalid.',
          errors: { email: ['Este e-mail já está cadastrado.'] },
        })
      );
      const user = userEvent.setup();
      renderPage();

      await fillForm(user);
      await user.click(screen.getByRole('button', { name: 'Criar conta' }));

      expect(await screen.findByText('Este e-mail já está cadastrado.')).toBeInTheDocument();
      expect(screen.queryByText(/Verifique sua conexão/)).not.toBeInTheDocument();
    });

    // Pega o `fetch` da confirmação sem `Accept`: o código errado viraria "verifique sua conexão".
    it('shows "invalid code" instead of a connection error when the confirmation code is wrong', async () => {
      const user = userEvent.setup();
      renderPage();
      await advanceToCodeStep(user);

      vi.stubGlobal(
        'fetch',
        validationFailure({
          message: 'The given data was invalid.',
          errors: { code: ['Código inválido ou expirado. Confira o e-mail ou peça um novo código.'] },
        })
      );

      await user.type(screen.getByLabelText(/Código de confirmação/), '000000');
      await user.click(screen.getByRole('button', { name: 'Confirmar e entrar' }));

      expect(await screen.findByText(/Código inválido ou expirado/)).toBeInTheDocument();
      expect(screen.queryByText(/Verifique sua conexão/)).not.toBeInTheDocument();
    });

    // Pega o `fetch` do reenvio sem `Accept`.
    it('shows the API message instead of a connection error when resending the code is refused', async () => {
      vi.mocked(fetch).mockResolvedValue({
        ok: true,
        json: async () => ({
          message: 'Enviamos um código de confirmação para o seu e-mail.',
          handle: HANDLE,
          expires_in_seconds: 900,
          resend_available_in: 0,
        }),
      } as unknown as Response);
      const user = userEvent.setup();
      renderPage();
      await advanceToCodeStep(user);

      vi.stubGlobal('fetch', validationFailure({ message: 'Este cadastro não pode mais receber um novo código.' }));

      await user.click(screen.getByRole('button', { name: 'Reenviar código' }));

      expect(await screen.findByText('Este cadastro não pode mais receber um novo código.')).toBeInTheDocument();
      expect(screen.queryByText(/Verifique sua conexão/)).not.toBeInTheDocument();
    });
  });
});
