import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import LoginPage from './LoginPage';
import { API_BASE_URL } from '../config';

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => vi.fn(),
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

    expect(screen.getByRole('link', { name: /Google/ })).toHaveAttribute('href', '#');
    expect(screen.getByRole('link', { name: /Microsoft/ })).toHaveAttribute('href', '#');
    expect(screen.getByRole('link', { name: 'Cadastre-se' })).toHaveAttribute('href', '#');
    expect(screen.getByRole('link', { name: 'Esqueci minha senha' })).toHaveAttribute('href', '#');
  });
});
