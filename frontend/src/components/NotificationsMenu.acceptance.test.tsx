import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import NotificationsMenu from './NotificationsMenu';

vi.mock('axios');

const navigateMock = vi.fn();
vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => navigateMock };
});

/**
 * TASK-606 (feature 20261110-quem-paga-se-declara-e-credor-recusa): os três avisos novos aparecem no sino
 * com o texto certo e o clique leva ao resumo do grupo, como os outros avisos de acerto.
 */
const item = (id: number, type: string, data: Record<string, unknown>) => ({
  id,
  type,
  group_id: 7,
  data: { groupId: 7, groupName: 'Casa', ...data },
  read_at: null,
  created_at: new Date().toISOString()
});

describe('NotificationsMenu: avisos de quem paga se declara e do aceite do credor', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: { message: 'ok' } });
    localStorage.setItem('accessToken', 't');
  });

  it.each([
    ['payment_responsible_self_declared', { actorName: 'Maria' }, 'Maria agora paga por você no grupo "Casa"'],
    ['settlement_refused', { actorName: 'Ana', debtorName: 'João', amount: '100.00' }, /Ana recusou o comprovante de João de R\$\s?100,00/],
    ['settlement_accepted', { actorName: 'Ana', amount: '100.00' }, /Ana aceitou o comprovante de R\$\s?100,00/]
  ])('%s shows its text and a click opens the group summary', async (type, data, text) => {
    vi.mocked(axios.get).mockResolvedValue({ data: { data: [item(4, type, data)] } });
    render(
      <MemoryRouter>
        <NotificationsMenu anchorEl={document.body} open onClose={vi.fn()} onRead={vi.fn()} />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByText(text));

    expect(axios.post).toHaveBeenCalledWith(expect.stringContaining('/api/notifications/read'), { id: 4 }, expect.anything());
    expect(navigateMock).toHaveBeenCalledWith('/groups/7/summary');
  });
});
