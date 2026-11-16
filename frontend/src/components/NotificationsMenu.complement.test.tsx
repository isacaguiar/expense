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

/** TASK-629: o aviso do complemento aparece no sino e o clique leva ao resumo do grupo, como os demais. */
describe('NotificationsMenu: aviso do complemento', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: { message: 'ok' } });
    localStorage.setItem('accessToken', 't');
  });

  it('shows the complement text and a click opens the group summary', async () => {
    vi.mocked(axios.get).mockResolvedValue({
      data: {
        data: [
          {
            id: 5,
            type: 'settlement_complement_added',
            group_id: 7,
            data: { groupId: 7, groupName: 'Casa', actorName: 'Maria', debtorName: 'João', amount: '100.00' },
            read_at: null,
            created_at: new Date().toISOString()
          }
        ]
      }
    });
    render(
      <MemoryRouter>
        <NotificationsMenu anchorEl={document.body} open onClose={vi.fn()} onRead={vi.fn()} />
      </MemoryRouter>
    );

    await userEvent.click(await screen.findByText(/Maria adicionou uma justificativa e um comprovante ao acerto de João de R\$\s?100,00/));

    expect(axios.post).toHaveBeenCalledWith(expect.stringContaining('/api/notifications/read'), { id: 5 }, expect.anything());
    expect(navigateMock).toHaveBeenCalledWith('/groups/7/summary');
  });
});
