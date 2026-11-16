import { act, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useSettlementDecision } from './useSettlementDecision';

/** TASK-630: `refuse` leva o motivo no corpo só quando há um; o resto do corpo não muda. */
vi.mock('axios');

describe('useSettlementDecision.refuse com motivo', () => {
  beforeEach(() => {
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    localStorage.setItem('accessToken', 'tok');
  });

  it('sends reason together with the pair and cycles_ago', async () => {
    const onSuccess = vi.fn();
    const { result } = renderHook(() => useSettlementDecision('7', onSuccess));

    act(() => result.current.refuse(3, 4, 1, 'foto cortada'));

    await waitFor(() => expect(onSuccess).toHaveBeenCalledWith('refuse'));
    expect(vi.mocked(axios.post).mock.calls[0][1]).toEqual({ from_user_id: 3, to_user_id: 4, cycles_ago: 1, reason: 'foto cortada' });
  });

  it.each([undefined, '', '   '])('does not send reason when it is %j', async reason => {
    const onSuccess = vi.fn();
    const { result } = renderHook(() => useSettlementDecision('7', onSuccess));

    act(() => result.current.refuse(3, 4, 0, reason));

    await waitFor(() => expect(onSuccess).toHaveBeenCalledWith('refuse'));
    expect(vi.mocked(axios.post).mock.calls[0][1]).toEqual({ from_user_id: 3, to_user_id: 4, cycles_ago: 0 });
  });
});
