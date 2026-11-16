import { act, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useSettlementComplement } from './useSettlementComplement';

/**
 * TASK-634 (feature 20261114-historico-de-comprovantes-e-justificativa, plan §11): o complemento é um multipart
 * para `POST .../settlements/complement` com o par, `cycles_ago`, `comprovante` e `justificativa`, o mesmo
 * cabeçalho de `useConfirmSettlement`; uma ação por vez; a mensagem `error` do servidor vai à tela.
 */
vi.mock('axios');

const GENERIC = 'Não foi possível enviar a justificativa. Tente novamente.';
const photo = () => new File(['x'], 'comprovante.jpg', { type: 'image/jpeg' });

describe('useSettlementComplement', () => {
  beforeEach(() => {
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: { message: 'ok' } });
    localStorage.setItem('accessToken', 'tok');
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
  });

  it('posts a multipart form with the pair, cycles_ago, photo and justification and the bearer token', async () => {
    const onSuccess = vi.fn();
    const file = photo();
    const { result } = renderHook(() => useSettlementComplement('7', onSuccess));

    act(() => result.current.complement(3, 4, 1, file, 'o pix foi de outra conta'));

    await waitFor(() => expect(onSuccess).toHaveBeenCalledTimes(1));
    const [url, body, config] = vi.mocked(axios.post).mock.calls[0] as unknown as [
      string,
      FormData,
      { headers: { Authorization: string } }
    ];
    expect(url).toMatch(/\/api\/groups\/7\/settlements\/complement$/);
    expect(body).toBeInstanceOf(FormData);
    expect(body.get('from_user_id')).toBe('3');
    expect(body.get('to_user_id')).toBe('4');
    expect(body.get('cycles_ago')).toBe('1');
    expect(body.get('comprovante')).toBeInstanceOf(File);
    expect((body.get('comprovante') as File).name).toBe('comprovante.jpg');
    expect(body.get('justificativa')).toBe('o pix foi de outra conta');
    expect(config.headers.Authorization).toBe('Bearer tok');
  });

  it('does nothing without a group id', () => {
    const { result } = renderHook(() => useSettlementComplement(undefined, vi.fn()));

    act(() => result.current.complement(3, 4, 0, photo(), 'x'));

    expect(axios.post).not.toHaveBeenCalled();
  });

  it('is busy while it runs and ignores a second call at the same instant', async () => {
    let resolve!: () => void;
    vi.mocked(axios.post).mockReturnValue(new Promise(r => (resolve = () => r({ data: {} }))));
    const { result } = renderHook(() => useSettlementComplement('7', vi.fn()));

    act(() => {
      result.current.complement(3, 4, 0, photo(), 'a');
      result.current.complement(3, 4, 0, photo(), 'b');
    });

    expect(result.current.busy).toBe(true);
    expect(axios.post).toHaveBeenCalledTimes(1);
    await act(async () => resolve());
    await waitFor(() => expect(result.current.busy).toBe(false));
  });

  it('shows the error message the server sent', async () => {
    const message = 'Só dá para complementar o comprovante que o próprio devedor enviou.';
    vi.mocked(axios.post).mockRejectedValue({ response: { status: 422, data: { error: message } } });
    const onSuccess = vi.fn();
    const { result } = renderHook(() => useSettlementComplement('7', onSuccess));

    act(() => result.current.complement(3, 4, 0, photo(), 'x'));

    await waitFor(() => expect(result.current.error).toBe(message));
    expect(onSuccess).not.toHaveBeenCalled();
    expect(result.current.busy).toBe(false);
  });

  it('shows the generic text when the server sends none, and clearError clears it', async () => {
    vi.mocked(axios.post).mockRejectedValue(new Error('rede'));
    const { result } = renderHook(() => useSettlementComplement('7', vi.fn()));

    act(() => result.current.complement(3, 4, 0, photo(), 'x'));
    await waitFor(() => expect(result.current.error).toBe(GENERIC));

    act(() => result.current.clearError());
    expect(result.current.error).toBeNull();
  });
});
