import { renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useGroupCycle } from './useGroupCycle';

vi.mock('axios');

const navigateMock = vi.fn();
vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => navigateMock };
});

const summaryPayload = {
  cycle: { start: '2026-01-01', end: '2026-01-31', closes_at: '2026-02-05', status: 'closed', settled: false },
  totals: { total: 0, paid: 0, pending: 0 },
  expenses: [],
  balances: [],
  settlements: [],
};

const wrapper = ({ children }: { children: ReactNode }) => <MemoryRouter>{children}</MemoryRouter>;

describe('useGroupCycle', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    navigateMock.mockClear();
  });

  it('resolves focus-cycle before summary and seeds cycles_ago with its answer', async () => {
    const calls: string[] = [];
    vi.mocked(axios.get).mockImplementation((url: string) => {
      calls.push(url);
      if (url.includes('/expenses/focus-cycle')) {
        return Promise.resolve({ data: { cycles_ago: 2 } });
      }
      if (url.includes('/expenses/summary')) {
        return Promise.resolve({ data: summaryPayload });
      }
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });

    const { result } = renderHook(() => useGroupCycle('7'), { wrapper });

    await waitFor(() => expect(result.current.summary).not.toBeNull());

    // focus-cycle veio antes do primeiro summary
    const focusIdx = calls.findIndex(u => u.includes('/expenses/focus-cycle'));
    const summaryIdx = calls.findIndex(u => u.includes('/expenses/summary'));
    expect(focusIdx).toBeGreaterThanOrEqual(0);
    expect(focusIdx).toBeLessThan(summaryIdx);

    // o summary saiu com cycles_ago = 2 (retorno do focus-cycle)
    const summaryCall = vi.mocked(axios.get).mock.calls.find(c => String(c[0]).includes('/expenses/summary'));
    expect((summaryCall?.[1] as { params: { cycles_ago: number } }).params.cycles_ago).toBe(2);
    expect(result.current.cyclesAgo).toBe(2);
  });

  it('falls back to cycles_ago 0 when focus-cycle fails', async () => {
    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/expenses/focus-cycle')) {
        return Promise.reject(new Error('boom'));
      }
      if (url.includes('/expenses/summary')) {
        return Promise.resolve({ data: summaryPayload });
      }
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });

    const { result } = renderHook(() => useGroupCycle('7'), { wrapper });

    await waitFor(() => expect(result.current.summary).not.toBeNull());

    const summaryCall = vi.mocked(axios.get).mock.calls.find(c => String(c[0]).includes('/expenses/summary'));
    expect((summaryCall?.[1] as { params: { cycles_ago: number } }).params.cycles_ago).toBe(0);
  });
});
