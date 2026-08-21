import { render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ExpensesEntry from './ExpensesEntry';

vi.mock('axios');

const navigateMock = vi.fn();

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => navigateMock,
  };
});

describe('ExpensesEntry', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
  });

  it('redirects automatically when the user belongs to exactly 1 group', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({
      data: [{ id: 42, name: 'Grupo Único', description: '', create_date: '2026-01-01', expenses_max_date_payment: null }],
    });

    render(
      <MemoryRouter>
        <ExpensesEntry />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(navigateMock).toHaveBeenCalledWith('/groups/42/expenses', { replace: true });
    });
  });

  it('redirects to the group with the most recent expense when there is more than 1 group, without showing a picker', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({
      data: [
        { id: 1, name: 'Grupo A', description: '', create_date: '2026-01-01', expenses_max_date_payment: '2026-02-10' },
        { id: 2, name: 'Grupo B', description: '', create_date: '2026-01-01', expenses_max_date_payment: '2026-05-01' },
      ],
    });

    render(
      <MemoryRouter>
        <ExpensesEntry />
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(navigateMock).toHaveBeenCalledWith('/groups/2/expenses', { replace: true });
    });
    expect(screen.queryByText('Grupo A')).not.toBeInTheDocument();
    expect(screen.queryByText('Grupo B')).not.toBeInTheDocument();
  });

  it('shows an informative message when the user belongs to 0 groups', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({ data: [] });

    render(
      <MemoryRouter>
        <ExpensesEntry />
      </MemoryRouter>
    );

    expect(await screen.findByText('Você ainda não participa de nenhum grupo.')).toBeInTheDocument();
  });
});
