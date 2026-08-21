import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { logout } from './logout';

vi.mock('axios');

describe('logout', () => {
  beforeEach(() => {
    vi.mocked(axios.post).mockReset();
    localStorage.setItem('accessToken', 'a-token');
  });

  it('calls POST /api/logout, clears localStorage and navigates to /', async () => {
    vi.mocked(axios.post).mockResolvedValueOnce({ data: { message: 'ok' } });
    const navigate = vi.fn();

    await logout(navigate);

    expect(axios.post).toHaveBeenCalledWith(
      expect.stringContaining('/api/logout'),
      null,
      expect.objectContaining({ headers: expect.objectContaining({ Authorization: 'Bearer a-token' }) })
    );
    expect(localStorage.getItem('accessToken')).toBeNull();
    expect(navigate).toHaveBeenCalledWith('/');
  });

  it('still clears localStorage and navigates even if the backend call fails', async () => {
    vi.mocked(axios.post).mockRejectedValueOnce(new Error('network error'));
    const navigate = vi.fn();

    await logout(navigate);

    expect(localStorage.getItem('accessToken')).toBeNull();
    expect(navigate).toHaveBeenCalledWith('/');
  });
});
