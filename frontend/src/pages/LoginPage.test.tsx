import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import LoginPage from './LoginPage';
import { API_BASE_URL } from '../config';
import { trackEvent } from '../analytics/trackEvent';

// O contrato da página com a camada de analytics é a chamada em si (o que o
// `trackEvent` envia ao GA já é coberto em `analytics/trackEvent.test.ts`).
vi.mock('../analytics/trackEvent', () => ({ trackEvent: vi.fn() }));

const mockNavigate = vi.fn();

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => mockNavigate,
  };
});

describe('LoginPage', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ access_token: 'token-123', refresh_token: 'refresh-123' }),
      })
    );
    localStorage.clear();
    mockNavigate.mockClear();
    vi.mocked(trackEvent).mockClear();
  });

  it('renders the email/password fields and the submit button', () => {
    render(
      <MemoryRouter>
        <LoginPage />
      </MemoryRouter>
    );

    expect(screen.getByLabelText(/E-mail/)).toBeInTheDocument();
    expect(screen.getByLabelText(/^Senha/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Entrar' })).toBeInTheDocument();
  });

  it('calls the login endpoint with the typed credentials on submit', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <LoginPage />
      </MemoryRouter>
    );

    await user.type(screen.getByLabelText(/E-mail/), 'user@example.com');
    await user.type(screen.getByLabelText(/^Senha/), 'secret123');
    await user.click(screen.getByRole('button', { name: 'Entrar' }));

    expect(fetch).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/login`,
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ email: 'user@example.com', password: 'secret123' }),
      })
    );
  });

  it('shows an error message when the credentials are rejected', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: false,
        status: 401,
        json: async () => ({ error: 'Não autorizado' }),
      })
    );
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <LoginPage />
      </MemoryRouter>
    );

    await user.type(screen.getByLabelText(/E-mail/), 'user@example.com');
    await user.type(screen.getByLabelText(/^Senha/), 'wrong-password');
    await user.click(screen.getByRole('button', { name: 'Entrar' }));

    expect(await screen.findByText('E-mail ou senha inválidos.')).toBeInTheDocument();
  });

  it('shows the backend message when login is rejected for unverified e-mail', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: false,
        status: 403,
        json: async () => ({
          error: 'E-mail não verificado. Use "Esqueci minha senha" para confirmar seu e-mail e definir uma nova senha.',
        }),
      })
    );
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <LoginPage />
      </MemoryRouter>
    );

    await user.type(screen.getByLabelText(/E-mail/), 'user@example.com');
    await user.type(screen.getByLabelText(/^Senha/), 'secret123');
    await user.click(screen.getByRole('button', { name: 'Entrar' }));

    expect(
      await screen.findByText(
        'E-mail não verificado. Use "Esqueci minha senha" para confirmar seu e-mail e definir uma nova senha.'
      )
    ).toBeInTheDocument();
  });

  it('falls back to the generic message when an unexpected status has no JSON body', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: false,
        status: 500,
        json: async () => {
          throw new Error('not json');
        },
      })
    );
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <LoginPage />
      </MemoryRouter>
    );

    await user.type(screen.getByLabelText(/E-mail/), 'user@example.com');
    await user.type(screen.getByLabelText(/^Senha/), 'secret123');
    await user.click(screen.getByRole('button', { name: 'Entrar' }));

    expect(
      await screen.findByText('Não foi possível fazer login. Tente novamente em instantes.')
    ).toBeInTheDocument();
  });

  it('shows a connection error message when the request fails', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network down')));
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <LoginPage />
      </MemoryRouter>
    );

    await user.type(screen.getByLabelText(/E-mail/), 'user@example.com');
    await user.type(screen.getByLabelText(/^Senha/), 'secret123');
    await user.click(screen.getByRole('button', { name: 'Entrar' }));

    expect(
      await screen.findByText('Não foi possível fazer login. Verifique sua conexão e tente novamente.')
    ).toBeInTheDocument();
  });

  it('renders the branding headline and the differentiators', () => {
    render(
      <MemoryRouter>
        <LoginPage />
      </MemoryRouter>
    );

    expect(screen.getByText('Despesas compartilhadas,')).toBeInTheDocument();
    expect(screen.getByText('contas em dia.')).toBeInTheDocument();
    expect(screen.getByText('Grupos organizados')).toBeInTheDocument();
    expect(screen.getByText('Divisão igualitária')).toBeInTheDocument();
    expect(screen.getByText('Seguro e confiável')).toBeInTheDocument();
  });

  it('renders the social login placeholders and the footer links', () => {
    render(
      <MemoryRouter>
        <LoginPage />
      </MemoryRouter>
    );

    expect(screen.getByRole('link', { name: /Google/ })).toHaveAttribute(
      'href',
      `${API_BASE_URL}/api/auth/google/login`
    );
    expect(screen.queryByRole('link', { name: /Microsoft/ })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Cadastre-se' })).toHaveAttribute('href', '/cadastro');
    expect(screen.getByRole('link', { name: 'Esqueci minha senha' })).toHaveAttribute('href', '#');
  });

  it('exchanges the google_code for a token and redirects to the groups page', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ access_token: 'google-token-123', token_type: 'bearer', expires_in: 3600 }),
      })
    );

    render(
      <MemoryRouter initialEntries={['/login?google_code=abc123']}>
        <LoginPage />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(localStorage.getItem('accessToken')).toBe('google-token-123');
    });

    expect(fetch).toHaveBeenCalledWith(`${API_BASE_URL}/api/auth/google/exchange?code=abc123`);
    expect(mockNavigate).toHaveBeenCalledWith('/meus-grupos');
  });

  it('shows an error message when google_error is present', async () => {
    render(
      <MemoryRouter initialEntries={['/login?google_error=1']}>
        <LoginPage />
      </MemoryRouter>
    );

    expect(
      await screen.findByText('Não foi possível entrar com o Google. Tente novamente.')
    ).toBeInTheDocument();
  });

  describe('medição do cadastro por Google (sign_up)', () => {
    function stubExchange(body: Record<string, unknown>) {
      vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
          ok: true,
          json: async () => ({ access_token: 'google-token-123', token_type: 'bearer', expires_in: 3600, ...body }),
        })
      );
    }

    function renderWithGoogleCode() {
      return render(
        <MemoryRouter initialEntries={['/login?google_code=abc123']}>
          <LoginPage />
        </MemoryRouter>
      );
    }

    // Pega a medição que não existe, ou que dispara com método errado.
    it('mede sign_up com method google, uma vez, quando a troca informa conta nova', async () => {
      stubExchange({ new_user: true });

      renderWithGoogleCode();

      await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/meus-grupos'));
      expect(trackEvent).toHaveBeenCalledTimes(1);
      expect(trackEvent).toHaveBeenCalledWith('sign_up', { method: 'google' });
      expect(vi.mocked(trackEvent).mock.invocationCallOrder[0]).toBeLessThan(
        mockNavigate.mock.invocationCallOrder[0]
      );
    });

    // Pega a medição que trata todo login por Google como cadastro.
    it('não mede quando a troca informa que a conta já existia', async () => {
      stubExchange({ new_user: false });

      renderWithGoogleCode();

      await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/meus-grupos'));
      expect(trackEvent).not.toHaveBeenCalled();
    });

    // Pega a medição que depende de a flag existir: respostas sem `new_user`
    // (um backend anterior ao deploy da TASK-386) não podem contar como cadastro.
    it('não mede quando a resposta não traz new_user', async () => {
      stubExchange({});

      renderWithGoogleCode();

      await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/meus-grupos'));
      expect(trackEvent).not.toHaveBeenCalled();
    });

    // Pega a medição disparada antes de saber se a troca deu certo.
    it('não mede quando a troca do código falha', async () => {
      vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, json: async () => ({ new_user: true }) }));

      renderWithGoogleCode();

      expect(
        await screen.findByText('Não foi possível concluir o login com o Google. Tente novamente.')
      ).toBeInTheDocument();
      expect(trackEvent).not.toHaveBeenCalled();
    });

    it('não mede o login por e-mail e senha', async () => {
      const user = userEvent.setup();
      render(
        <MemoryRouter>
          <LoginPage />
        </MemoryRouter>
      );

      await user.type(screen.getByLabelText(/E-mail/), 'user@example.com');
      await user.type(screen.getByLabelText(/^Senha/), 'secret123');
      await user.click(screen.getByRole('button', { name: 'Entrar' }));

      await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/meus-grupos'));
      expect(trackEvent).not.toHaveBeenCalled();
    });
  });
});
