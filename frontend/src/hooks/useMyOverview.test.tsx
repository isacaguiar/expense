import { renderHook, act, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useMyOverview } from './useMyOverview';
import { myOverviewFixture } from '../types/overview.fixtures';

vi.mock('axios');

const navigateMock = vi.fn();

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => navigateMock };
});

describe('useMyOverview', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    navigateMock.mockReset();
    vi.spyOn(console, 'error').mockImplementation(() => {});
    localStorage.setItem('accessToken', 'token-de-teste');
  });

  afterEach(() => {
    localStorage.removeItem('accessToken');
    vi.restoreAllMocks();
  });

  it('loads GET /api/me/overview with the bearer token and exposes the data', async () => {
    const overview = myOverviewFixture();
    vi.mocked(axios.get).mockResolvedValue({ data: overview });

    const { result } = renderHook(() => useMyOverview());

    expect(result.current.loading).toBe(true);
    await waitFor(() => expect(result.current.loading).toBe(false));

    const [url, config] = vi.mocked(axios.get).mock.calls[0];
    expect(url).toMatch(/\/api\/me\/overview$/);
    expect(config).toEqual({ headers: { Authorization: 'Bearer token-de-teste' } });
    expect(result.current.data).toEqual(overview);
    expect(result.current.error).toBeNull();
  });

  it('sends the user back to the login on 401', async () => {
    vi.mocked(axios.get).mockRejectedValue({ response: { status: 401 } });

    const { result } = renderHook(() => useMyOverview());

    await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/', { replace: true }));
    expect(result.current.error).toBeNull();
  });

  it('shows a message on any other error', async () => {
    vi.mocked(axios.get).mockRejectedValue({ response: { status: 500 } });

    const { result } = renderHook(() => useMyOverview());

    await waitFor(() => expect(result.current.error).toBe('Falha ao carregar o painel.'));
    expect(result.current.loading).toBe(false);
    expect(result.current.data).toBeNull();
    expect(navigateMock).not.toHaveBeenCalled();
  });

  it('fetches again on reload and keeps the screen while it does', async () => {
    vi.mocked(axios.get).mockResolvedValueOnce({ data: myOverviewFixture() });
    const { result } = renderHook(() => useMyOverview());
    await waitFor(() => expect(result.current.data).not.toBeNull());

    const updated = myOverviewFixture({ truncated: true });
    let resolveGet: (value: unknown) => void = () => {};
    vi.mocked(axios.get).mockReturnValueOnce(new Promise(resolve => (resolveGet = resolve)));

    act(() => result.current.reload());

    expect(axios.get).toHaveBeenCalledTimes(2);
    // Depois de uma ação (comprovante, marcar paga), o painel recarrega sem
    // voltar ao spinner: os cartões continuam na tela até os dados novos chegarem.
    expect(result.current.loading).toBe(false);
    expect(result.current.data).not.toBeNull();

    await act(async () => resolveGet({ data: updated }));

    expect(result.current.data).toEqual(updated);
  });
});
