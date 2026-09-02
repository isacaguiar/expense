import { render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MembersEntry from './MembersEntry';

vi.mock('axios');

const navigateMock = vi.fn();

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => navigateMock,
  };
});

describe('MembersEntry', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
  });

  it("redirects to the most active group's members screen", async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({
      data: [
        { id: 1, name: 'Grupo A', description: '', create_date: '2026-01-01', expenses_max_date_payment: '2026-02-10' },
        { id: 2, name: 'Grupo B', description: '', create_date: '2026-01-01', expenses_max_date_payment: '2026-05-01' },
      ],
    });

    render(
      <MemoryRouter>
        <MembersEntry />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(navigateMock).toHaveBeenCalledWith('/groups/2/members', { replace: true });
    });
  });

  it('shows an informative message when the user belongs to 0 groups', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({ data: [] });

    render(
      <MemoryRouter>
        <MembersEntry />
      </MemoryRouter>
    );

    expect(await screen.findByText('Você ainda não participa de nenhum grupo.')).toBeInTheDocument();
  });
});
