import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AcceptInvitePage from './AcceptInvitePage';
import { trackEvent } from '../analytics/trackEvent';

vi.mock('axios');

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

function renderWithQuery(query: string) {
  return render(
    <MemoryRouter initialEntries={[`/aceitar-convite${query}`]}>
      <Routes>
        <Route path="/aceitar-convite" element={<AcceptInvitePage />} />
      </Routes>
    </MemoryRouter>
  );
}

function renderResetWithQuery(query: string) {
  return render(
    <MemoryRouter initialEntries={[`/recuperar-senha${query}`]}>
      <Routes>
        <Route path="/recuperar-senha" element={<AcceptInvitePage mode="reset" />} />
      </Routes>
    </MemoryRouter>
  );
}

describe('AcceptInvitePage', () => {
  beforeEach(() => {
    vi.mocked(axios.post).mockReset();
    mockNavigate.mockReset();
    vi.mocked(trackEvent).mockClear();
  });

  // Pega a medição que não existe, ou que dispara com método errado.
  it('mede sign_up com method invite, uma vez, quando a conta é ativada', async () => {
    vi.mocked(axios.post).mockResolvedValue({ data: { message: 'ok' } });
    const user = userEvent.setup();

    renderWithQuery('?email=convidado%40example.com&token=abc123');

    await user.type(screen.getByLabelText(/^Nova senha/), 'senha-nova-123');
    await user.type(screen.getByLabelText(/^Confirmar senha/), 'senha-nova-123');
    await user.click(screen.getByRole('button', { name: 'Ativar conta' }));

    expect(await screen.findByText(/Senha definida com sucesso/)).toBeInTheDocument();
    expect(trackEvent).toHaveBeenCalledTimes(1);
    expect(trackEvent).toHaveBeenCalledWith('sign_up', { method: 'invite' });

    // A página agenda o redirecionamento para 2s depois do sucesso. Esperar por ele aqui
    // evita que o timer vaze para o teste seguinte (que confere `navigate` não chamado).
    await vi.waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/'), { timeout: 3000 });
  });

  // Pega a medição disparada antes de saber se a API aceitou: o convite com
  // token vencido contaria como cadastro.
  it('não mede quando o token é inválido ou expirou', async () => {
    vi.mocked(axios.post).mockRejectedValue({
      response: { data: { message: 'Token inválido ou expirado.' } },
    });
    const user = userEvent.setup();

    renderWithQuery('?email=convidado%40example.com&token=abc123');

    await user.type(screen.getByLabelText(/^Nova senha/), 'senha-nova-123');
    await user.type(screen.getByLabelText(/^Confirmar senha/), 'senha-nova-123');
    await user.click(screen.getByRole('button', { name: 'Ativar conta' }));

    expect(await screen.findByText('Token inválido ou expirado.')).toBeInTheDocument();
    expect(trackEvent).not.toHaveBeenCalled();
  });

  it('não mede só por abrir o link do convite', () => {
    renderWithQuery('?email=convidado%40example.com&token=abc123');

    expect(trackEvent).not.toHaveBeenCalled();
  });

  it('submits email/token/password to POST /api/invitations/verify and shows a success message', async () => {
    vi.mocked(axios.post).mockResolvedValue({ data: { message: 'ok' } });
    const user = userEvent.setup();

    renderWithQuery('?email=convidado%40example.com&token=abc123');

    await user.type(screen.getByLabelText(/^Nova senha/), 'senha-nova-123');
    await user.type(screen.getByLabelText(/^Confirmar senha/), 'senha-nova-123');
    await user.click(screen.getByRole('button', { name: 'Ativar conta' }));

    expect(axios.post).toHaveBeenCalledWith(
      expect.stringContaining('/api/invitations/verify'),
      {
        email: 'convidado@example.com',
        token: 'abc123',
        password: 'senha-nova-123',
        password_confirmation: 'senha-nova-123',
      }
    );
    expect(await screen.findByText(/Senha definida com sucesso/)).toBeInTheDocument();
  });

  it('shows the backend error message when the token is invalid or expired', async () => {
    vi.mocked(axios.post).mockRejectedValue({
      response: { data: { message: 'Token inválido ou expirado.' } },
    });
    const user = userEvent.setup();

    renderWithQuery('?email=convidado%40example.com&token=abc123');

    await user.type(screen.getByLabelText(/^Nova senha/), 'senha-nova-123');
    await user.type(screen.getByLabelText(/^Confirmar senha/), 'senha-nova-123');
    await user.click(screen.getByRole('button', { name: 'Ativar conta' }));

    expect(await screen.findByText('Token inválido ou expirado.')).toBeInTheDocument();
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it('disables the form and warns when email or token is missing from the link', () => {
    renderWithQuery('');

    expect(
      screen.getByText('Link de convite inválido — faltam informações. Solicite um novo convite.')
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Ativar conta' })).toBeDisabled();
  });
});

describe('AcceptInvitePage com mode="reset" (recuperação de senha)', () => {
  beforeEach(() => {
    vi.mocked(axios.post).mockReset();
    mockNavigate.mockReset();
    vi.mocked(trackEvent).mockClear();
  });

  // Pega o componente compartilhado medindo qualquer sucesso como cadastro: quem
  // só redefiniu a senha de uma conta antiga inflaria o número de cadastros.
  it('não mede a redefinição de senha como cadastro', async () => {
    vi.mocked(axios.post).mockResolvedValue({ data: { message: 'ok' } });
    const user = userEvent.setup();

    renderResetWithQuery('?email=pessoa%40example.com&token=xyz789');

    await user.type(screen.getByLabelText(/^Nova senha/), 'senha-nova-123');
    await user.type(screen.getByLabelText(/^Confirmar senha/), 'senha-nova-123');
    await user.click(screen.getByRole('button', { name: 'Redefinir senha' }));

    expect(await screen.findByText(/Senha redefinida com sucesso/)).toBeInTheDocument();
    expect(trackEvent).not.toHaveBeenCalled();

    // Mesmo motivo do teste de convite: drena o redirecionamento agendado para 2s.
    await vi.waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/'), { timeout: 3000 });
    expect(trackEvent).not.toHaveBeenCalled();
  });

  it('submits to the same POST /api/invitations/verify and shows the reset success message', async () => {
    vi.mocked(axios.post).mockResolvedValue({ data: { message: 'ok' } });
    const user = userEvent.setup();

    renderResetWithQuery('?email=pessoa%40example.com&token=xyz789');

    await user.type(screen.getByLabelText(/^Nova senha/), 'senha-nova-123');
    await user.type(screen.getByLabelText(/^Confirmar senha/), 'senha-nova-123');
    await user.click(screen.getByRole('button', { name: 'Redefinir senha' }));

    expect(axios.post).toHaveBeenCalledWith(
      expect.stringContaining('/api/invitations/verify'),
      {
        email: 'pessoa@example.com',
        token: 'xyz789',
        password: 'senha-nova-123',
        password_confirmation: 'senha-nova-123',
      }
    );
    expect(await screen.findByText(/Senha redefinida com sucesso/)).toBeInTheDocument();
  });

  it('shows the recovery alert (not the invite one) when email or token is missing', () => {
    renderResetWithQuery('');

    expect(
      screen.getByText(
        'Link de recuperação inválido — faltam informações. Solicite uma nova recuperação de senha.'
      )
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Redefinir senha' })).toBeDisabled();
  });
});
