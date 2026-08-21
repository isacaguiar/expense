import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ChangePassword from './ChangePassword';

vi.mock('axios');

describe('ChangePassword', () => {
  beforeEach(() => {
    vi.mocked(axios.put).mockReset();
  });

  it('submits current/new/confirm password to PUT /api/user/password and shows a success message', async () => {
    vi.mocked(axios.put).mockResolvedValue({ data: { message: 'ok' } });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ChangePassword />
      </MemoryRouter>
    );

    await user.type(screen.getByLabelText(/^Senha atual/), 'senha-antiga');
    await user.type(screen.getByLabelText(/^Nova senha/), 'senha-nova-123');
    await user.type(screen.getByLabelText(/^Confirmar nova senha/), 'senha-nova-123');
    await user.click(screen.getByRole('button', { name: 'Alterar senha' }));

    expect(axios.put).toHaveBeenCalledWith(
      expect.stringContaining('/api/user/password'),
      {
        current_password: 'senha-antiga',
        new_password: 'senha-nova-123',
        new_password_confirmation: 'senha-nova-123',
      },
      expect.anything()
    );
    expect(await screen.findByText('Senha atualizada com sucesso.')).toBeInTheDocument();
  });

  it('shows the backend error when the current password is wrong', async () => {
    vi.mocked(axios.put).mockRejectedValue({
      response: { data: { errors: { current_password: ['Senha atual incorreta.'] } } },
    });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <ChangePassword />
      </MemoryRouter>
    );

    await user.type(screen.getByLabelText(/^Senha atual/), 'senha-errada');
    await user.type(screen.getByLabelText(/^Nova senha/), 'senha-nova-123');
    await user.type(screen.getByLabelText(/^Confirmar nova senha/), 'senha-nova-123');
    await user.click(screen.getByRole('button', { name: 'Alterar senha' }));

    expect(await screen.findByText('Senha atual incorreta.')).toBeInTheDocument();
  });
});
