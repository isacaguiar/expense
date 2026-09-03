import { renderHook, act, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { usePaymentActions } from './usePaymentActions';
import type { Summary, SummaryExpense, CycleStatus } from './useGroupCycle';

vi.mock('axios');

const expense: SummaryExpense = {
  id: 42,
  description: 'Mercado',
  date: '2026-01-10',
  value: 100,
  valuePerPerson: 50,
  paid: false,
  paymentProofUrl: null,
  payerName: 'Ana',
  participants: ['Ana', 'Bruno'],
  isFixed: false,
  userPayerId: 1,
  userCreatorId: 1,
};

function summaryWith(status: CycleStatus, settled = false): Summary {
  return {
    cycle: { start: '2026-01-01', end: '2026-01-31', closes_at: '2026-02-05', status, settled },
    totals: { total: 100, paid: 0, pending: 100 },
    expenses: [expense],
    balances: [],
    settlements: [],
  };
}

describe('usePaymentActions', () => {
  beforeEach(() => {
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
  });

  it('lets the creditor pay in a closed, unsettled cycle', () => {
    const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 1, () => {}));
    expect(result.current.canPay(expense)).toBe(true);
    expect(result.current.canUnpay({ ...expense, paid: true })).toBe(true);
  });

  it('blocks pay/unpay only when the cycle is future', () => {
    const { result } = renderHook(() => usePaymentActions(1, summaryWith('future'), 0, () => {}));
    expect(result.current.canPay(expense)).toBe(false);
    expect(result.current.canUnpay({ ...expense, paid: true })).toBe(false);
  });

  it('still lets the creditor unpay a settled cycle', () => {
    const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed', true), 2, () => {}));
    expect(result.current.canUnpay({ ...expense, paid: true })).toBe(true);
  });

  it('sends cycles_ago in the pay and unpay POST bodies', async () => {
    const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 3, () => {}));

    act(() => result.current.handlePay(42));
    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    expect(vi.mocked(axios.post).mock.calls[0][0]).toContain('/expenses/42/pay');
    expect(vi.mocked(axios.post).mock.calls[0][1]).toEqual({ cycles_ago: 3 });

    act(() => result.current.handleUnpay(42));
    await waitFor(() => expect(vi.mocked(axios.post).mock.calls.length).toBe(2));
    expect(vi.mocked(axios.post).mock.calls[1][0]).toContain('/expenses/42/unpay');
    expect(vi.mocked(axios.post).mock.calls[1][1]).toEqual({ cycles_ago: 3 });
  });

  it('sends cycles_ago as a field when a proof file is attached', async () => {
    const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 2, () => {}));

    act(() => result.current.handlePay(42, new File(['x'], 'proof.png', { type: 'image/png' })));
    await waitFor(() => expect(axios.post).toHaveBeenCalled());

    const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
    expect(body).toBeInstanceOf(FormData);
    expect(body.get('cycles_ago')).toBe('2');
    expect(body.get('comprovante')).toBeInstanceOf(File);
  });
});
