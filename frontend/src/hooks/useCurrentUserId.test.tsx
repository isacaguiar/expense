import { renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useCurrentUserId } from './useCurrentUserId';

vi.mock('axios');

describe('useCurrentUserId', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.spyOn(console, 'error').mockImplementation(() => {});
    localStorage.setItem('accessToken', 'token-de-teste');
  });

  afterEach(() => {
    localStorage.removeItem('accessToken');
    vi.restoreAllMocks();
  });

  it('começa sem usuário e passa a devolver o id de GET /api/me, com o token Bearer', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: { id: 533, name: 'Carla' } });

    const { result } = renderHook(() => useCurrentUserId());

    expect(result.current).toBeNull();
    await waitFor(() => expect(result.current).toBe(533));

    const [url, config] = vi.mocked(axios.get).mock.calls[0];
    expect(url).toMatch(/\/api\/me$/);
    expect(config).toEqual({ headers: { Authorization: 'Bearer token-de-teste' } });
    expect(axios.get).toHaveBeenCalledTimes(1);
  });

  it('fica nulo quando a resposta não traz um id numérico', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: { name: 'QA Header Usuario', email: 'qa@example.com' } });

    const { result } = renderHook(() => useCurrentUserId());

    await waitFor(() => expect(axios.get).toHaveBeenCalled());
    // Dá a chance de o `.then` rodar antes de afirmar que nada foi gravado.
    await new Promise(resolve => setTimeout(resolve, 0));
    expect(result.current).toBeNull();
  });

  it('fica nulo e registra o erro quando a chamada falha', async () => {
    const erro = new Error('rede fora');
    vi.mocked(axios.get).mockRejectedValue(erro);

    const { result } = renderHook(() => useCurrentUserId());

    await waitFor(() =>
      expect(console.error).toHaveBeenCalledWith('Erro ao carregar usuário autenticado:', erro)
    );
    expect(result.current).toBeNull();
  });
});
