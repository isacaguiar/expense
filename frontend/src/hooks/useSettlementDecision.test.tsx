import { act, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useSettlementDecision } from './useSettlementDecision';

/**
 * Feature 20261110-quem-paga-se-declara-e-credor-recusa (TASK-608, plan §11): aceitar, recusar e excluir o
 * comprovante de um acerto. O corpo de `accept` e `refuse` é JSON; `confirmation` é um DELETE com o par na
 * query. Todos levam o token e o `cycles_ago` que quem chama informa.
 */

vi.mock('axios');

const GENERIC = 'Não foi possível concluir a ação. Tente novamente.';
const NOT_FOUND = 'Este comprovante não está mais disponível. Atualize a tela.';
const FORBIDDEN = 'Você não pode fazer isso neste comprovante.';

const failure = (status: number, data: unknown = {}) => ({ response: { status, data } });

describe('useSettlementDecision', () => {
  beforeEach(() => {
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.delete).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    vi.mocked(axios.delete).mockResolvedValue({ data: {} });
    localStorage.setItem('accessToken', 'tok');
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
  });

  it.each([
    ['accept', 'accept'],
    ['refuse', 'refuse']
  ] as const)('%s posts the pair and cycles_ago with the bearer token, then reports the action', async (action, path) => {
    const onSuccess = vi.fn();
    const { result } = renderHook(() => useSettlementDecision('7', onSuccess));

    act(() => result.current[action](3, 4, 1));

    await waitFor(() => expect(onSuccess).toHaveBeenCalledWith(action));
    expect(axios.post).toHaveBeenCalledTimes(1);
    const [url, body, config] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toMatch(new RegExp(`/api/groups/7/settlements/${path}$`));
    expect(body).toEqual({ from_user_id: 3, to_user_id: 4, cycles_ago: 1 });
    expect(config).toEqual({ headers: { Authorization: 'Bearer tok' } });
    expect(result.current.error).toBeNull();
  });

  it('destroy sends a DELETE with the pair and cycles_ago in the query and the bearer token', async () => {
    const onSuccess = vi.fn();
    const { result } = renderHook(() => useSettlementDecision('7', onSuccess));

    act(() => result.current.destroy(3, 4, 0));

    await waitFor(() => expect(onSuccess).toHaveBeenCalledWith('destroy'));
    expect(axios.delete).toHaveBeenCalledTimes(1);
    const [url, config] = vi.mocked(axios.delete).mock.calls[0];
    expect(url).toMatch(/\/api\/groups\/7\/settlements\/confirmation$/);
    expect(config).toEqual({
      headers: { Authorization: 'Bearer tok' },
      params: { from_user_id: 3, to_user_id: 4, cycles_ago: 0 }
    });
    expect(axios.post).not.toHaveBeenCalled();
  });

  it('shows the server message on 422', async () => {
    vi.mocked(axios.post).mockRejectedValue(failure(422, { error: 'Esta competência já foi encerrada.' }));
    const onSuccess = vi.fn();
    const { result } = renderHook(() => useSettlementDecision('7', onSuccess));

    act(() => result.current.accept(3, 4, 0));

    await waitFor(() => expect(result.current.error).toBe('Esta competência já foi encerrada.'));
    expect(onSuccess).not.toHaveBeenCalled();
    expect(result.current.busy).toBe(false);
  });

  it('explains a 404 and a 403 in Portuguese, ignoring any message of the framework', async () => {
    vi.mocked(axios.post).mockRejectedValueOnce(failure(404, { message: 'Not Found' }));
    vi.mocked(axios.delete).mockRejectedValueOnce(failure(403, { message: 'This action is unauthorized.' }));
    const { result } = renderHook(() => useSettlementDecision('7', vi.fn()));

    act(() => result.current.refuse(3, 4, 0));
    await waitFor(() => expect(result.current.error).toBe(NOT_FOUND));

    act(() => result.current.destroy(3, 4, 0));
    await waitFor(() => expect(result.current.error).toBe(FORBIDDEN));
  });

  it('falls back to a generic message when there is no response (network failure)', async () => {
    vi.mocked(axios.post).mockRejectedValue(new Error('Network Error'));
    const { result } = renderHook(() => useSettlementDecision('7', vi.fn()));

    act(() => result.current.accept(3, 4, 0));

    await waitFor(() => expect(result.current.error).toBe(GENERIC));
  });

  it('clears the previous error on the next attempt and with clearError', async () => {
    vi.mocked(axios.post).mockRejectedValueOnce(failure(422, { error: 'Algo deu errado.' }));
    const { result } = renderHook(() => useSettlementDecision('7', vi.fn()));

    act(() => result.current.accept(3, 4, 0));
    await waitFor(() => expect(result.current.error).toBe('Algo deu errado.'));

    act(() => result.current.refuse(3, 4, 0));
    await waitFor(() => expect(result.current.busy).toBe(false));
    expect(result.current.error).toBeNull();

    vi.mocked(axios.post).mockRejectedValueOnce(failure(422, { error: 'De novo.' }));
    act(() => result.current.accept(3, 4, 0));
    await waitFor(() => expect(result.current.error).toBe('De novo.'));
    act(() => result.current.clearError());
    expect(result.current.error).toBeNull();
  });

  it('is busy while a request is in flight and ignores a second action until it ends', async () => {
    let finish: (value: unknown) => void = () => undefined;
    vi.mocked(axios.post).mockReturnValue(new Promise(resolve => (finish = resolve)));
    const onSuccess = vi.fn();
    const { result } = renderHook(() => useSettlementDecision('7', onSuccess));

    act(() => result.current.accept(3, 4, 0));
    await waitFor(() => expect(result.current.busy).toBe(true));
    act(() => result.current.refuse(3, 4, 0));
    act(() => result.current.destroy(3, 4, 0));

    expect(axios.post).toHaveBeenCalledTimes(1);
    expect(axios.delete).not.toHaveBeenCalled();

    await act(async () => finish({ data: {} }));
    await waitFor(() => expect(result.current.busy).toBe(false));
    expect(onSuccess).toHaveBeenCalledTimes(1);
  });

  it('sends nothing without a group id', () => {
    const { result } = renderHook(() => useSettlementDecision(undefined, vi.fn()));

    act(() => result.current.accept(3, 4, 0));
    act(() => result.current.destroy(3, 4, 0));

    expect(axios.post).not.toHaveBeenCalled();
    expect(axios.delete).not.toHaveBeenCalled();
  });
});
