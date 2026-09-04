import { act, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useUnreadNotificationsCount } from './useUnreadNotificationsCount';

vi.mock('axios');

describe('useUnreadNotificationsCount', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.get).mockResolvedValue({ data: { count: 3 } });
    localStorage.setItem('accessToken', 'a-token');
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('fetches the unread count from GET /api/notifications/unread-count on mount', async () => {
    const { result } = renderHook(() => useUnreadNotificationsCount());

    await waitFor(() => expect(result.current.count).toBe(3));
    expect(axios.get).toHaveBeenCalledWith(
      expect.stringContaining('/api/notifications/unread-count'),
      expect.objectContaining({ headers: { Authorization: 'Bearer a-token' } })
    );
  });

  it('polls again every 60s and stops after unmount', async () => {
    vi.useFakeTimers();

    const { unmount } = renderHook(() => useUnreadNotificationsCount());
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0); // flush o fetch da montagem
    });
    expect(axios.get).toHaveBeenCalledTimes(1);

    await act(async () => {
      await vi.advanceTimersByTimeAsync(60_000);
    });
    expect(axios.get).toHaveBeenCalledTimes(2);

    await act(async () => {
      await vi.advanceTimersByTimeAsync(60_000);
    });
    expect(axios.get).toHaveBeenCalledTimes(3);

    unmount();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(180_000);
    });
    expect(axios.get).toHaveBeenCalledTimes(3);
  });

  it('keeps the count at 0 and does not throw when the poll fails', async () => {
    vi.mocked(axios.get).mockRejectedValue({ response: { status: 401 } });

    const { result } = renderHook(() => useUnreadNotificationsCount());

    await act(async () => {
      await Promise.resolve();
    });
    expect(result.current.count).toBe(0);
  });
});
