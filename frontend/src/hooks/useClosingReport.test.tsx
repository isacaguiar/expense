import { act, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useClosingReport, type ClosingReport } from './useClosingReport';

vi.mock('axios');

const navigateMock = vi.fn();
vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => navigateMock };
});

const closingReport = (start: string, overrides: Partial<ClosingReport> = {}): ClosingReport => ({
  group: { id: 7, name: 'Casa' },
  cycle: { start, end: '2026-09-30', closes_at: '2026-10-05', status: 'closed', settled: false, in_grace: false },
  totals: { total: 390, paid: 90, pending: 300 },
  expenses: [],
  balances: [],
  settlements: [],
  creditors: [
    {
      creditor: { id: 1, name: 'Ana', avatarUrl: null },
      advanced_total: 390,
      debtors: [{ id: 2, name: 'Beto', avatarUrl: null, amount: 195 }],
    },
  ],
  ...overrides,
});

const wrapper = ({ children }: { children: ReactNode }) => <MemoryRouter>{children}</MemoryRouter>;

describe('useClosingReport', () => {
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

  it('loads the closing report of the cycle with the bearer token', async () => {
    const report = closingReport('2026-09-01');
    vi.mocked(axios.get).mockResolvedValue({ data: report });

    const { result } = renderHook(() => useClosingReport('7', '2026-09-01'), { wrapper });

    expect(result.current.loading).toBe(true);
    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.report).toEqual(report);
    expect(result.current.error).toBeNull();

    const [url, config] = vi.mocked(axios.get).mock.calls[0];
    expect(String(url)).toMatch(/\/api\/groups\/7\/expenses\/closing-report$/);
    const { headers, params } = config as { headers: { Authorization: string }; params: unknown };
    expect(headers.Authorization).toBe('Bearer tok-123');
    expect(params).toEqual({ cycle_start: '2026-09-01' });
  });

  it('does not call the API while no cycle is selected', () => {
    const { result } = renderHook(() => useClosingReport('7', undefined), { wrapper });

    expect(axios.get).not.toHaveBeenCalled();
    expect(result.current.loading).toBe(false);
    expect(result.current.report).toBeNull();
    expect(result.current.error).toBeNull();
  });

  it('does not call the API without a group id', () => {
    const { result } = renderHook(() => useClosingReport(undefined, '2026-09-01'), { wrapper });

    expect(axios.get).not.toHaveBeenCalled();
    expect(result.current.loading).toBe(false);
    expect(result.current.report).toBeNull();
  });

  it('fetches again when the cycle changes and drops the previous report while it loads', async () => {
    let resolveSecond: (value: unknown) => void = () => {};
    vi.mocked(axios.get)
      .mockResolvedValueOnce({ data: closingReport('2026-09-01') })
      .mockImplementationOnce(() => new Promise(resolve => (resolveSecond = resolve)));

    const { result, rerender } = renderHook(({ ciclo }) => useClosingReport('7', ciclo), {
      wrapper,
      initialProps: { ciclo: '2026-09-01' },
    });
    await waitFor(() => expect(result.current.report?.cycle.start).toBe('2026-09-01'));

    rerender({ ciclo: '2026-08-01' });

    await waitFor(() => expect(result.current.loading).toBe(true));
    expect(result.current.report).toBeNull();

    await act(async () => {
      resolveSecond({ data: closingReport('2026-08-01') });
    });

    expect(result.current.loading).toBe(false);
    expect(result.current.report?.cycle.start).toBe('2026-08-01');
    expect(axios.get).toHaveBeenCalledTimes(2);
    expect(vi.mocked(axios.get).mock.calls[1][1]).toMatchObject({ params: { cycle_start: '2026-08-01' } });
  });

  it('clears the report when the cycle is deselected', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: closingReport('2026-09-01') });

    const { result, rerender } = renderHook(({ ciclo }) => useClosingReport('7', ciclo), {
      wrapper,
      initialProps: { ciclo: '2026-09-01' as string | undefined },
    });
    await waitFor(() => expect(result.current.report).not.toBeNull());

    rerender({ ciclo: undefined });

    await waitFor(() => expect(result.current.report).toBeNull());
    expect(result.current.loading).toBe(false);
    expect(result.current.error).toBeNull();
    expect(axios.get).toHaveBeenCalledTimes(1);
  });

  it('says the cycle was not found on 404, without sending the user to the login', async () => {
    vi.mocked(axios.get).mockRejectedValue({ response: { status: 404 } });

    const { result } = renderHook(() => useClosingReport('7', '2099-01-01'), { wrapper });

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.error).toBe('Ciclo não encontrado.');
    expect(result.current.report).toBeNull();
    expect(navigateMock).not.toHaveBeenCalled();
  });

  it('treats a malformed date (422) as a cycle that was not found', async () => {
    vi.mocked(axios.get).mockRejectedValue({ response: { status: 422 } });

    const { result } = renderHook(() => useClosingReport('7', 'abc'), { wrapper });

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.error).toBe('Ciclo não encontrado.');
    expect(result.current.report).toBeNull();
  });

  it('shows a friendly error when the request fails for another reason', async () => {
    vi.mocked(axios.get).mockRejectedValue({ response: { status: 500 } });

    const { result } = renderHook(() => useClosingReport('7', '2026-09-01'), { wrapper });

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.error).toBe('Falha ao carregar o Relatório de Fechamento.');
    expect(result.current.report).toBeNull();
    expect(navigateMock).not.toHaveBeenCalled();
  });

  it('sends the user back to the login on 401 without showing an error', async () => {
    vi.mocked(axios.get).mockRejectedValue({ response: { status: 401 } });

    const { result } = renderHook(() => useClosingReport('7', '2026-09-01'), { wrapper });

    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/', { replace: true }));
    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.error).toBeNull();
    expect(result.current.report).toBeNull();
  });

  it('clears an earlier error when the next cycle loads', async () => {
    vi.mocked(axios.get)
      .mockRejectedValueOnce({ response: { status: 404 } })
      .mockResolvedValueOnce({ data: closingReport('2026-09-01') });

    const { result, rerender } = renderHook(({ ciclo }) => useClosingReport('7', ciclo), {
      wrapper,
      initialProps: { ciclo: '2099-01-01' },
    });
    await waitFor(() => expect(result.current.error).toBe('Ciclo não encontrado.'));

    rerender({ ciclo: '2026-09-01' });

    await waitFor(() => expect(result.current.report).not.toBeNull());
    expect(result.current.error).toBeNull();
  });

  it('ignores a late answer from the previous cycle after the cycle changed', async () => {
    let resolveFirst: (value: unknown) => void = () => {};
    vi.mocked(axios.get)
      .mockImplementationOnce(() => new Promise(resolve => (resolveFirst = resolve)))
      .mockResolvedValueOnce({ data: closingReport('2026-08-01') });

    const { result, rerender } = renderHook(({ ciclo }) => useClosingReport('7', ciclo), {
      wrapper,
      initialProps: { ciclo: '2026-09-01' },
    });

    rerender({ ciclo: '2026-08-01' });
    await waitFor(() => expect(result.current.report?.cycle.start).toBe('2026-08-01'));

    // `act` deixa o React aplicar a atualização, se ela acontecer: sem isso o
    // teste passaria mesmo sem o guarda.
    await act(async () => {
      resolveFirst({ data: closingReport('2026-09-01') });
    });

    expect(result.current.report?.cycle.start).toBe('2026-08-01');
    expect(result.current.loading).toBe(false);
  });

  it('keeps loading while the current cycle is pending when the previous one answers late', async () => {
    let resolveFirst: (value: unknown) => void = () => {};
    vi.mocked(axios.get)
      .mockImplementationOnce(() => new Promise(resolve => (resolveFirst = resolve)))
      .mockImplementationOnce(() => new Promise(() => {}));

    const { result, rerender } = renderHook(({ ciclo }) => useClosingReport('7', ciclo), {
      wrapper,
      initialProps: { ciclo: '2026-09-01' },
    });

    rerender({ ciclo: '2026-08-01' });
    await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(2));

    await act(async () => {
      resolveFirst({ data: closingReport('2026-09-01') });
    });

    // O ciclo atual ainda não respondeu: nem o relatório do anterior nem o fim
    // do carregamento dele podem aparecer.
    expect(result.current.loading).toBe(true);
    expect(result.current.report).toBeNull();
  });

  it('ignores a late failure from the previous cycle after the cycle changed', async () => {
    let rejectFirst: (reason: unknown) => void = () => {};
    vi.mocked(axios.get)
      .mockImplementationOnce(() => new Promise((_, reject) => (rejectFirst = reject)))
      .mockResolvedValueOnce({ data: closingReport('2026-08-01') });

    const { result, rerender } = renderHook(({ ciclo }) => useClosingReport('7', ciclo), {
      wrapper,
      initialProps: { ciclo: '2026-09-01' },
    });

    rerender({ ciclo: '2026-08-01' });
    await waitFor(() => expect(result.current.report?.cycle.start).toBe('2026-08-01'));

    await act(async () => {
      rejectFirst({ response: { status: 401 } });
    });

    expect(result.current.error).toBeNull();
    expect(result.current.report?.cycle.start).toBe('2026-08-01');
    expect(navigateMock).not.toHaveBeenCalled();
  });
});
