import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AcceptInvitePage from './AcceptInvitePage';

vi.mock('axios');

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

describe('AcceptInvitePage', () => {
  beforeEach(() => {
    vi.mocked(axios.post).mockReset();
    mockNavigate.mockReset();
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
