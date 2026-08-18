import { render, waitFor } from '@testing-library/react';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GroupList from './GroupList';

const navigateMock = vi.fn();

vi.mock('axios');

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => navigateMock,
  };
});

describe('GroupList', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
  });

  it('redirects to login when the groups request returns 401', async () => {
    vi.mocked(axios.get).mockRejectedValueOnce({
      response: { status: 401 },
    });

    render(
      <MemoryRouter>
        <GroupList />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(navigateMock).toHaveBeenCalledWith('/', { replace: true });
    });
  });
});
