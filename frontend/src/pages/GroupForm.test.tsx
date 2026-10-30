import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GroupForm from './GroupForm';
import { trackEvent } from '../analytics/trackEvent';

vi.mock('axios');

// O contrato da página com a camada de analytics é a chamada em si (o que o
// `trackEvent` envia ao GA já é coberto em `analytics/trackEvent.test.ts`).
vi.mock('../analytics/trackEvent', () => ({ trackEvent: vi.fn() }));

const { mockNavigate, mockParams } = vi.hoisted(() => ({
  mockNavigate: vi.fn(),
  mockParams: { current: {} as Record<string, string> },
}));

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => mockNavigate,
    useParams: () => mockParams.current,
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
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.put).mockReset();
    mockNavigate.mockClear();
    mockParams.current = {};
    vi.mocked(trackEvent).mockClear();
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

  describe('medição da criação de grupo (group_created)', () => {
    // Pega a medição que não existe, ou que dispara depois do navigate.
    it('mede group_created, uma vez, antes de voltar para a lista de grupos', async () => {
      const user = userEvent.setup();

      render(
        <MemoryRouter>
          <GroupForm />
        </MemoryRouter>
      );

      await user.type(screen.getByLabelText(/^Nome/), 'Casa dos Amigos');
      await user.type(screen.getByLabelText(/^Descrição/), 'grupo de teste');
      await user.click(screen.getByRole('button', { name: 'Criar Grupo' }));

      await vi.waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/meus-grupos'));
      expect(trackEvent).toHaveBeenCalledTimes(1);
      expect(trackEvent).toHaveBeenCalledWith('group_created');
      expect(vi.mocked(trackEvent).mock.invocationCallOrder[0]).toBeLessThan(
        mockNavigate.mock.invocationCallOrder[0]
      );
    });

    // Pega a medição disparada antes de saber se a API aceitou: a recusa por
    // limite de grupos contaria como grupo criado.
    it('não mede quando a API recusa a criação', async () => {
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
      expect(trackEvent).not.toHaveBeenCalled();
    });

    // Pega o handler compartilhado medindo qualquer salvamento: editar um grupo
    // existente não é criar um.
    it('não mede quando um grupo existente é editado', async () => {
      mockParams.current = { id: '5' };
      vi.mocked(axios.get).mockResolvedValue({
        data: { id: 5, name: 'Casa dos Amigos', description: 'grupo de teste', closing_day: null },
      });
      vi.mocked(axios.put).mockResolvedValue({ data: { id: 5 } });
      const user = userEvent.setup();

      render(
        <MemoryRouter>
          <GroupForm />
        </MemoryRouter>
      );

      await screen.findByDisplayValue('Casa dos Amigos');
      await user.click(screen.getByRole('button', { name: 'Atualizar Grupo' }));

      await vi.waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/meus-grupos'));
      expect(axios.put).toHaveBeenCalledTimes(1);
      expect(trackEvent).not.toHaveBeenCalled();
    });

    it('não mede só por abrir o formulário', () => {
      render(
        <MemoryRouter>
          <GroupForm />
        </MemoryRouter>
      );

      expect(trackEvent).not.toHaveBeenCalled();
    });
  });
});
