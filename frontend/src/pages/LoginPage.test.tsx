import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import LoginPage from './LoginPage';
import { API_BASE_URL } from '../config';

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
});
