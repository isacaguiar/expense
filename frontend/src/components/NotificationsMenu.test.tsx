import { render, screen, waitFor } from '@testing-library/react';
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

type Item = {
  id: number;
  type: string;
  group_id: number | null;
  data: Record<string, unknown> | null;
  read_at: string | null;
  created_at: string;
};

const paidItem = (over: Partial<Item> = {}): Item => ({
  id: 1,
  type: 'expense_paid',
  group_id: 7,
  data: { actorName: 'Bruno', expenseDescription: 'Luz', groupId: 7 },
  read_at: null,
  created_at: new Date().toISOString(),
  ...over
});

function renderMenu(props: Partial<React.ComponentProps<typeof NotificationsMenu>> = {}) {
  const onClose = vi.fn();
  const onRead = vi.fn();
  render(
    <MemoryRouter>
      <NotificationsMenu anchorEl={document.body} open onClose={onClose} onRead={onRead} {...props} />
    </MemoryRouter>
  );
  return { onClose, onRead };
}

describe('NotificationsMenu', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: { message: 'ok' } });
    localStorage.setItem('accessToken', 't');
  });

  it('fetches page 1 and lists the notifications when open', async () => {
    vi.mocked(axios.get).mockResolvedValue({
      data: {
        data: [
          paidItem(),
          paidItem({ id: 2, type: 'group_member_added', data: { actorName: 'Ana', groupName: 'Casa' }, read_at: '2026-09-01T00:00:00Z' })
        ]
      }
    });

    renderMenu();

    expect(await screen.findByText('Bruno marcou "Luz" como paga')).toBeInTheDocument();
    expect(screen.getByText('Ana adicionou você ao grupo "Casa"')).toBeInTheDocument();
    expect(axios.get).toHaveBeenCalledWith(
      expect.stringContaining('/api/notifications'),
      expect.objectContaining({ params: { page: 1 } })
    );
  });

  it('does not fetch while closed', () => {
    vi.mocked(axios.get).mockResolvedValue({ data: { data: [] } });
    renderMenu({ open: false });
    expect(axios.get).not.toHaveBeenCalled();
  });

  it('marks all as read and reloads the list', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: { data: [paidItem()] } });
    const { onRead } = renderMenu();
    await screen.findByText('Bruno marcou "Luz" como paga');

    await userEvent.click(screen.getByRole('button', { name: 'Marcar todas como lidas' }));

    await waitFor(() =>
      expect(axios.post).toHaveBeenCalledWith(
        expect.stringContaining('/api/notifications/read'),
        null,
        expect.anything()
      )
    );
    expect(onRead).toHaveBeenCalled();
    expect(axios.get).toHaveBeenCalledTimes(2);
  });

  it('marks one as read, navigates to its group and closes on item click', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: { data: [paidItem()] } });
    const { onClose, onRead } = renderMenu();

    await userEvent.click(await screen.findByText('Bruno marcou "Luz" como paga'));

    expect(axios.post).toHaveBeenCalledWith(
      expect.stringContaining('/api/notifications/read'),
      { id: 1 },
      expect.anything()
    );
    expect(navigateMock).toHaveBeenCalledWith('/groups/7/summary');
    expect(onClose).toHaveBeenCalled();
    expect(onRead).toHaveBeenCalled();
  });

  it('shows the empty state when there is nothing', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: { data: [] } });
    renderMenu();
    expect(await screen.findByText('Nenhuma notificação.')).toBeInTheDocument();
  });

  it('shows an error message when the list fails to load', async () => {
    vi.mocked(axios.get).mockRejectedValue(new Error('boom'));
    renderMenu();
    expect(await screen.findByText('Não foi possível carregar as notificações.')).toBeInTheDocument();
  });
});
