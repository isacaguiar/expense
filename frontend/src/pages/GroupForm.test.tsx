import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GroupForm from './GroupForm';

vi.mock('axios');

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => vi.fn(),
    useParams: () => ({}),
  };
});

function lastPostBody(): { name: string; description: string; closing_day: number | null } {
  const calls = vi.mocked(axios.post).mock.calls;
  return calls[calls.length - 1][1] as { name: string; description: string; closing_day: number | null };
}

describe('GroupForm', () => {
  beforeEach(() => {
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: { id: 1 } });
  });

  it('sends closing_day in the payload when filled', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <GroupForm />
      </MemoryRouter>
    );

    await user.type(screen.getByLabelText(/^Nome/), 'Casa dos Amigos');
    await user.type(screen.getByLabelText(/^Descrição/), 'grupo de teste');
    await user.type(screen.getByLabelText('Dia de fechamento (opcional)'), '10');

    await user.click(screen.getByRole('button', { name: 'Criar Grupo' }));

    expect(lastPostBody().closing_day).toBe(10);
  });

  it('sends closing_day as null when left blank', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <GroupForm />
      </MemoryRouter>
    );

    await user.type(screen.getByLabelText(/^Nome/), 'Casa dos Amigos');
    await user.type(screen.getByLabelText(/^Descrição/), 'grupo de teste');

    await user.click(screen.getByRole('button', { name: 'Criar Grupo' }));

    expect(lastPostBody().closing_day).toBeNull();
  });

  it('shows the backend error message when creation is rejected', async () => {
    vi.mocked(axios.post).mockRejectedValueOnce({
      response: { status: 422, data: { message: 'Você já atingiu o limite de 3 grupos criados.' } },
    });
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <GroupForm />
      </MemoryRouter>
    );

    await user.type(screen.getByLabelText(/^Nome/), 'Grupo 4');
    await user.type(screen.getByLabelText(/^Descrição/), 'grupo de teste');
    await user.click(screen.getByRole('button', { name: 'Criar Grupo' }));

    expect(await screen.findByText('Você já atingiu o limite de 3 grupos criados.')).toBeInTheDocument();
  });
});
