import { act, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useGroupPendingCycles, type PendingCycle } from './useGroupPendingCycles';

vi.mock('axios');

const navigateMock = vi.fn();
vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => navigateMock };
});

const pendingCycle = (start: string, overrides: Partial<PendingCycle> = {}): PendingCycle => ({
  cycle: { start, end: '2026-09-30', closes_at: '2026-10-05', status: 'closed', settled: false, in_grace: false },
  totals: { total: 390, paid: 90, pending: 300 },
  settlements_total: 2,
  settlements_confirmed: 1,
  pending_expenses_count: 1,
  awaiting_seal: false,
  ...overrides,
});

const wrapper = ({ children }: { children: ReactNode }) => <MemoryRouter>{children}</MemoryRouter>;

describe('useGroupPendingCycles', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    navigateMock.mockClear();
    localStorage.setItem('accessToken', 'tok-123');
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  afterEach(() => {
    localStorage.removeItem('accessToken');
    vi.restoreAllMocks();
  });

  it('loads the pending cycles of the group with the bearer token', async () => {
    const cycles = [pendingCycle('2026-09-01'), pendingCycle('2026-08-01', { awaiting_seal: true })];
    vi.mocked(axios.get).mockResolvedValue({ data: { data: cycles } });

    const { result } = renderHook(() => useGroupPendingCycles('7'), { wrapper });

    expect(result.current.loading).toBe(true);
    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.cycles).toEqual(cycles);
    expect(result.current.error).toBeNull();

    const [url, config] = vi.mocked(axios.get).mock.calls[0];
    expect(String(url)).toMatch(/\/api\/groups\/7\/expenses\/pending-cycles$/);
    expect((config as { headers: { Authorization: string } }).headers.Authorization).toBe('Bearer tok-123');
  });

  it('returns an empty list when nothing is waiting', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: { data: [] } });

    const { result } = renderHook(() => useGroupPendingCycles('7'), { wrapper });

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.cycles).toEqual([]);
    expect(result.current.error).toBeNull();
  });

  it('shows a friendly error and keeps the list empty when the request fails', async () => {
    vi.mocked(axios.get).mockRejectedValue({ response: { status: 500 } });

    const { result } = renderHook(() => useGroupPendingCycles('7'), { wrapper });

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.error).toBe('Falha ao carregar os ciclos em acompanhamento.');
    expect(result.current.cycles).toEqual([]);
    expect(navigateMock).not.toHaveBeenCalled();
  });

  it('sends the user back to the login on 401 without showing an error', async () => {
    vi.mocked(axios.get).mockRejectedValue({ response: { status: 401 } });

    const { result } = renderHook(() => useGroupPendingCycles('7'), { wrapper });

    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/', { replace: true }));
    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.error).toBeNull();
  });

  it('does not call the API without a group id', () => {
    renderHook(() => useGroupPendingCycles(undefined), { wrapper });

    expect(axios.get).not.toHaveBeenCalled();
  });

  it('ignores a late answer from the previous group after the group changed', async () => {
    let resolveFirst: (value: unknown) => void = () => {};
    vi.mocked(axios.get)
      .mockImplementationOnce(() => new Promise(resolve => (resolveFirst = resolve)))
      .mockResolvedValueOnce({ data: { data: [pendingCycle('2026-09-01')] } });

    const { result, rerender } = renderHook(({ id }) => useGroupPendingCycles(id), {
      wrapper,
      initialProps: { id: '7' },
    });

    rerender({ id: '8' });
    await waitFor(() => expect(result.current.cycles).toHaveLength(1));

    // A resposta do grupo 7 chega por último e não pode sobrescrever a do grupo 8.
    // `act` deixa o React aplicar a atualização, se ela acontecer: sem isso o
    // teste passaria mesmo sem o guarda.
    await act(async () => {
      resolveFirst({ data: { data: [pendingCycle('2026-01-01'), pendingCycle('2026-02-01')] } });
    });

    expect(result.current.cycles).toHaveLength(1);
    expect(result.current.cycles[0].cycle.start).toBe('2026-09-01');
  });
});
