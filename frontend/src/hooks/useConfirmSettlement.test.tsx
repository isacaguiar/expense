import { renderHook, act, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useConfirmSettlement } from './useConfirmSettlement';

vi.mock('axios');

const proof = () => new File(['foto'], 'pix.png', { type: 'image/png' });

describe('useConfirmSettlement', () => {
  beforeEach(() => {
    vi.mocked(axios.post).mockReset();
    vi.spyOn(console, 'error').mockImplementation(() => {});
    localStorage.setItem('accessToken', 'token-de-teste');
  });

  afterEach(() => {
    localStorage.removeItem('accessToken');
    vi.restoreAllMocks();
  });

  it.each([1, 2])('posts the proof as multipart with to_user_id and cycles_ago = %i', async cyclesAgo => {
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    const file = proof();
    const { result } = renderHook(() => useConfirmSettlement('5', () => {}));

    act(() => result.current.confirm(999, file, cyclesAgo));
    await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));

    const [url, body, config] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/groups/5/settlements/confirm');
    expect(body).toBeInstanceOf(FormData);
    expect((body as FormData).get('to_user_id')).toBe('999');
    expect((body as FormData).get('comprovante')).toBe(file);
    expect((body as FormData).get('cycles_ago')).toBe(String(cyclesAgo));
    expect(config).toEqual({ headers: { Authorization: 'Bearer token-de-teste' } });
  });

  it('is submitting while the request runs and calls onSuccess when it succeeds', async () => {
    let resolvePost: (value: unknown) => void = () => {};
    vi.mocked(axios.post).mockReturnValue(new Promise(resolve => (resolvePost = resolve)));
    const onSuccess = vi.fn();
    const { result } = renderHook(() => useConfirmSettlement('5', onSuccess));

    expect(result.current.submitting).toBe(false);

    act(() => result.current.confirm(999, proof(), 1));
    expect(result.current.submitting).toBe(true);
    expect(onSuccess).not.toHaveBeenCalled();

    await act(async () => resolvePost({ data: {} }));

    expect(result.current.submitting).toBe(false);
    expect(result.current.error).toBeNull();
    expect(onSuccess).toHaveBeenCalledTimes(1);
  });

  it('exposes the error message the API sends', async () => {
    vi.mocked(axios.post).mockRejectedValue({ response: { data: { error: 'O acerto já foi confirmado.' } } });
    const onSuccess = vi.fn();
    const { result } = renderHook(() => useConfirmSettlement('5', onSuccess));

    act(() => result.current.confirm(999, proof(), 1));

    await waitFor(() => expect(result.current.error).toBe('O acerto já foi confirmado.'));
    expect(result.current.submitting).toBe(false);
    expect(onSuccess).not.toHaveBeenCalled();
  });

  it('falls back to a generic message when the API sends none', async () => {
    vi.mocked(axios.post).mockRejectedValue(new Error('Network Error'));
    const { result } = renderHook(() => useConfirmSettlement('5', () => {}));

    act(() => result.current.confirm(999, proof(), 1));

    await waitFor(() => expect(result.current.error).toBe('Falha ao enviar o comprovante.'));
  });

  it('clears the error on demand and before a new attempt', async () => {
    vi.mocked(axios.post).mockRejectedValueOnce(new Error('Network Error'));
    const { result } = renderHook(() => useConfirmSettlement('5', () => {}));

    act(() => result.current.confirm(999, proof(), 1));
    await waitFor(() => expect(result.current.error).not.toBeNull());

    act(() => result.current.clearError());
    expect(result.current.error).toBeNull();

    vi.mocked(axios.post).mockRejectedValueOnce(new Error('Network Error'));
    act(() => result.current.confirm(999, proof(), 1));
    await waitFor(() => expect(result.current.error).not.toBeNull());

    let resolvePost: (value: unknown) => void = () => {};
    vi.mocked(axios.post).mockReturnValueOnce(new Promise(resolve => (resolvePost = resolve)));
    act(() => result.current.confirm(999, proof(), 1));
    expect(result.current.error).toBeNull();
    await act(async () => resolvePost({ data: {} }));
  });

  it('does nothing without a group', () => {
    const { result } = renderHook(() => useConfirmSettlement(undefined, () => {}));

    act(() => result.current.confirm(999, proof(), 1));

    expect(axios.post).not.toHaveBeenCalled();
    expect(result.current.submitting).toBe(false);
  });
});
