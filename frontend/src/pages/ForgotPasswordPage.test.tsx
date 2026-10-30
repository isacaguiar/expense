import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ForgotPasswordPage from './ForgotPasswordPage';
import { API_BASE_URL } from '../config';

vi.mock('axios');

describe('ForgotPasswordPage', () => {
  beforeEach(() => {
    vi.mocked(axios.post).mockReset();
  });

  it('renders only the e-mail field and the submit button', () => {
    render(
      <MemoryRouter>
        <ForgotPasswordPage />
      </MemoryRouter>
    );

    expect(screen.getByLabelText(/E-mail/)).toBeInTheDocument();
    expect(screen.queryByLabelText(/Senha/)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Enviar link de recuperação' })).toBeInTheDocument();
  });

  it('submits the e-mail to POST /api/forgot-password and shows the generic success message', async () => {
    vi.mocked(axios.post).mockResolvedValue({ data: { message: 'Link de recuperação enviado para seu e-mail.' } });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ForgotPasswordPage />
      </MemoryRouter>
    );

    await user.type(screen.getByLabelText(/E-mail/), 'pessoa@example.com');
    await user.click(screen.getByRole('button', { name: 'Enviar link de recuperação' }));

    expect(axios.post).toHaveBeenCalledWith(`${API_BASE_URL}/api/forgot-password`, {
      email: 'pessoa@example.com',
    });
    expect(
      await screen.findByText(/Se esse e-mail estiver cadastrado/)
    ).toBeInTheDocument();
  });

  it('shows the same generic success message when the e-mail does not exist (422)', async () => {
    vi.mocked(axios.post).mockRejectedValue({
      response: { status: 422, data: { message: 'The selected email is invalid.' } },
    });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ForgotPasswordPage />
      </MemoryRouter>
    );

    await user.type(screen.getByLabelText(/E-mail/), 'nao-existe@example.com');
    await user.click(screen.getByRole('button', { name: 'Enviar link de recuperação' }));

    expect(
      await screen.findByText(/Se esse e-mail estiver cadastrado/)
    ).toBeInTheDocument();
    expect(screen.queryByText('The selected email is invalid.')).not.toBeInTheDocument();
  });

  it('shows the backend rate limit message on 429', async () => {
    vi.mocked(axios.post).mockRejectedValue({
      response: {
        status: 429,
        data: { message: 'Você já solicitou recuperação recentemente. Aguarde alguns minutos.' },
      },
    });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ForgotPasswordPage />
      </MemoryRouter>
    );

    await user.type(screen.getByLabelText(/E-mail/), 'pessoa@example.com');
    await user.click(screen.getByRole('button', { name: 'Enviar link de recuperação' }));

    expect(
      await screen.findByText('Você já solicitou recuperação recentemente. Aguarde alguns minutos.')
    ).toBeInTheDocument();
  });

  it('allows resending by submitting the form again after a success', async () => {
    vi.mocked(axios.post).mockResolvedValue({ data: { message: 'ok' } });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ForgotPasswordPage />
      </MemoryRouter>
    );

    await user.type(screen.getByLabelText(/E-mail/), 'pessoa@example.com');
    await user.click(screen.getByRole('button', { name: 'Enviar link de recuperação' }));
    await screen.findByText(/Se esse e-mail estiver cadastrado/);

    await user.click(screen.getByRole('button', { name: 'Reenviar link' }));

    expect(axios.post).toHaveBeenCalledTimes(2);
  });

  it('has a link back to the login page', () => {
    render(
      <MemoryRouter>
        <ForgotPasswordPage />
      </MemoryRouter>
    );

    expect(screen.getByRole('link', { name: 'Voltar para o login' })).toHaveAttribute('href', '/');
  });
});
