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

  it('sends cycles_ago and descricao as JSON when only a note is given, and cycles_ago when undoing', async () => {
    const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 3, () => {}));

    act(() => result.current.handlePay(42, { note: 'Pago em dinheiro' }));
    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    expect(vi.mocked(axios.post).mock.calls[0][0]).toContain('/expenses/42/pay');
    expect(vi.mocked(axios.post).mock.calls[0][1]).toEqual({ cycles_ago: 3, descricao: 'Pago em dinheiro' });

    act(() => result.current.handleUnpay(42));
    await waitFor(() => expect(vi.mocked(axios.post).mock.calls.length).toBe(2));
    expect(vi.mocked(axios.post).mock.calls[1][0]).toContain('/expenses/42/unpay');
    expect(vi.mocked(axios.post).mock.calls[1][1]).toEqual({ cycles_ago: 3 });
  });

  it('trims the note before sending it', async () => {
    const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 0, () => {}));

    act(() => result.current.handlePay(42, { note: '  ref. 12345  ' }));
    await waitFor(() => expect(axios.post).toHaveBeenCalled());

    expect(vi.mocked(axios.post).mock.calls[0][1]).toEqual({ cycles_ago: 0, descricao: 'ref. 12345' });
  });

  it('omits descricao when the note is blank and a proof file is attached', async () => {
    const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 0, () => {}));

    act(() => result.current.handlePay(42, { proof: new File(['x'], 'proof.png', { type: 'image/png' }), note: '   ' }));
    await waitFor(() => expect(axios.post).toHaveBeenCalled());

    const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
    expect(body).toBeInstanceOf(FormData);
    expect(body.has('descricao')).toBe(false);
  });

  it('sends cycles_ago as a field when a proof file is attached', async () => {
    const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 2, () => {}));

    act(() => result.current.handlePay(42, { proof: new File(['x'], 'proof.png', { type: 'image/png' }) }));
    await waitFor(() => expect(axios.post).toHaveBeenCalled());

    const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
    expect(body).toBeInstanceOf(FormData);
    expect(body.get('cycles_ago')).toBe('2');
    expect(body.get('comprovante')).toBeInstanceOf(File);
    expect(body.has('descricao')).toBe(false);
  });

  it('sends the proof file, the note and cycles_ago together in the multipart body', async () => {
    const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 1, () => {}));

    act(() =>
      result.current.handlePay(42, {
        proof: new File(['x'], 'proof.png', { type: 'image/png' }),
        note: 'Pix agendado para sexta',
      })
    );
    await waitFor(() => expect(axios.post).toHaveBeenCalled());

    const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
    expect(body.get('cycles_ago')).toBe('1');
    expect(body.get('comprovante')).toBeInstanceOf(File);
    expect(body.get('descricao')).toBe('Pix agendado para sexta');
  });

  // Desfazer de uma vez o pagamento de uma compra parcelada registrada no cadastro
  // (docs/feature/20261027-pagamento-no-cadastro-despesa, plan §7, R19).
  describe('undo purchase', () => {
    const purchase = { ...expense, paid: true, expenseType: 'IN_INSTALLMENTS' as const, canUndoPurchase: true };

    it('canUndoPurchase is true only when the server says so and the user is the creditor', () => {
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('open'), 0, () => {}));

      expect(result.current.canUndoPurchase(purchase)).toBe(true);
      expect(result.current.canUndoPurchase({ ...purchase, canUndoPurchase: false })).toBe(false);
      expect(result.current.canUndoPurchase({ ...purchase, canUndoPurchase: undefined })).toBe(false);
      expect(result.current.canUndoPurchase({ ...purchase, userPayerId: 2 })).toBe(false);
    });

    it('does not depend on the cycle being viewed: the server already looked at every installment', () => {
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('future'), 0, () => {}));

      expect(result.current.canUndoPurchase(purchase)).toBe(true);
    });

    it('is false for a creditor who is not signed in yet', () => {
      const { result } = renderHook(() => usePaymentActions(null, summaryWith('open'), 0, () => {}));

      expect(result.current.canUndoPurchase(purchase)).toBe(false);
    });

    it('handleUndoPurchase calls the route without a cycle and reloads', async () => {
      const reload = vi.fn();
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('open'), 2, reload));

      act(() => result.current.handleUndoPurchase(42));
      await waitFor(() => expect(result.current.undoPurchaseSuccess).toBe(true));

      expect(axios.post).toHaveBeenCalledTimes(1);
      expect(vi.mocked(axios.post).mock.calls[0][0]).toMatch(/\/api\/expenses\/42\/undo-purchase$/);
      expect(vi.mocked(axios.post).mock.calls[0][1]).toEqual({});
      expect(reload).toHaveBeenCalledTimes(1);
      expect(result.current.payError).toBeNull();
      expect(result.current.payingExpenseId).toBeNull();
      // Os avisos de sucesso das outras ações não são acionados por ela.
      expect(result.current.paySuccess).toBe(false);
      expect(result.current.unpaySuccess).toBe(false);
    });

    it('marks the expense as being worked on until the request ends', async () => {
      let finish: (value: { data: object }) => void = () => {};
      vi.mocked(axios.post).mockReturnValue(new Promise(resolve => (finish = resolve)));
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('open'), 0, () => {}));

      act(() => result.current.handleUndoPurchase(42));
      expect(result.current.payingExpenseId).toBe(42);

      await act(async () => finish({ data: {} }));
      expect(result.current.payingExpenseId).toBeNull();
    });

    it('shows the reason the server gave when the undo is refused, and does not reload', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {});
      vi.mocked(axios.post).mockRejectedValueOnce({
        response: { data: { error: 'O ciclo desta compra já foi encerrado; o pagamento não pode mais ser desfeito.' } },
      });
      const reload = vi.fn();
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('open'), 0, reload));

      act(() => result.current.handleUndoPurchase(42));
      await waitFor(() =>
        expect(result.current.payError).toBe('O ciclo desta compra já foi encerrado; o pagamento não pode mais ser desfeito.')
      );

      expect(result.current.undoPurchaseSuccess).toBe(false);
      expect(reload).not.toHaveBeenCalled();
      expect(result.current.payingExpenseId).toBeNull();
    });

    it('falls back to a generic message when the failure has no reason', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {});
      vi.mocked(axios.post).mockRejectedValueOnce(new Error('Network Error'));
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('open'), 0, () => {}));

      act(() => result.current.handleUndoPurchase(42));
      await waitFor(() => expect(result.current.payError).toBe('Falha ao desfazer o pagamento da compra.'));
    });

    it('dismissUndoPurchaseSuccess clears the success flag', async () => {
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('open'), 0, () => {}));

      act(() => result.current.handleUndoPurchase(42));
      await waitFor(() => expect(result.current.undoPurchaseSuccess).toBe(true));

      act(() => result.current.dismissUndoPurchaseSuccess());

      expect(result.current.undoPurchaseSuccess).toBe(false);
    });

    it('clears an earlier error when a new undo starts', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {});
      vi.mocked(axios.post).mockRejectedValueOnce({ response: { data: { error: 'Motivo antigo.' } } });
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('open'), 0, () => {}));

      act(() => result.current.handleUndoPurchase(42));
      await waitFor(() => expect(result.current.payError).toBe('Motivo antigo.'));

      act(() => result.current.handleUndoPurchase(42));

      await waitFor(() => expect(result.current.undoPurchaseSuccess).toBe(true));
      expect(result.current.payError).toBeNull();
    });
  });

  describe('canEditProof', () => {
    const paid = { ...expense, paid: true };

    it('is true only for the creditor of a paid expense in a non-future, unsettled cycle', () => {
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 1, () => {}));
      expect(result.current.canEditProof(paid)).toBe(true);
    });

    it('is also true in the current open cycle', () => {
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('open'), 0, () => {}));
      expect(result.current.canEditProof(paid)).toBe(true);
    });

    it('is false for an expense whose creditor is someone else', () => {
      const { result } = renderHook(() => usePaymentActions(2, summaryWith('closed'), 0, () => {}));
      expect(result.current.canEditProof(paid)).toBe(false);
    });

    it('is false when nobody is logged in', () => {
      const { result } = renderHook(() => usePaymentActions(null, summaryWith('closed'), 0, () => {}));
      expect(result.current.canEditProof(paid)).toBe(false);
    });

    it('is false for a pending expense', () => {
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 0, () => {}));
      expect(result.current.canEditProof(expense)).toBe(false);
    });

    it('is false in a future cycle', () => {
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('future'), 0, () => {}));
      expect(result.current.canEditProof(paid)).toBe(false);
    });

    it('is false in a settled (sealed) cycle, where the backend would answer 422', () => {
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed', true), 2, () => {}));
      expect(result.current.canEditProof(paid)).toBe(false);
    });

    it('is false while the summary has not loaded', () => {
      const { result } = renderHook(() => usePaymentActions(1, null, 0, () => {}));
      expect(result.current.canEditProof(paid)).toBe(false);
    });
  });

  describe('handleEditProof', () => {
    const png = () => new File(['x'], 'novo.png', { type: 'image/png' });

    it('posts JSON to the payment-proof route when no photo is sent', async () => {
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 3, () => {}));

      act(() => result.current.handleEditProof(42, { note: 'Pago em dinheiro' }));
      await waitFor(() => expect(axios.post).toHaveBeenCalled());

      const [url, body] = vi.mocked(axios.post).mock.calls[0];
      expect(url).toMatch(/\/api\/expenses\/42\/payment-proof$/);
      expect(body).toEqual({ cycles_ago: 3, descricao: 'Pago em dinheiro' });
    });

    it('adds remover_comprovante: true to the JSON when the photo is removed', async () => {
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 0, () => {}));

      act(() => result.current.handleEditProof(42, { removeProof: true, note: 'Ficou só o texto' }));
      await waitFor(() => expect(axios.post).toHaveBeenCalled());

      expect(vi.mocked(axios.post).mock.calls[0][1]).toEqual({
        cycles_ago: 0,
        descricao: 'Ficou só o texto',
        remover_comprovante: true,
      });
    });

    it('does not send remover_comprovante when removeProof is false', async () => {
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 0, () => {}));

      act(() => result.current.handleEditProof(42, { removeProof: false, note: 'x' }));
      await waitFor(() => expect(axios.post).toHaveBeenCalled());

      expect(vi.mocked(axios.post).mock.calls[0][1]).toEqual({ cycles_ago: 0, descricao: 'x' });
    });

    it('sends FormData with comprovante, cycles_ago and descricao when a photo is given', async () => {
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 2, () => {}));

      act(() => result.current.handleEditProof(42, { proof: png(), note: 'Pix de sexta' }));
      await waitFor(() => expect(axios.post).toHaveBeenCalled());

      const [url, body] = vi.mocked(axios.post).mock.calls[0];
      expect(url).toMatch(/\/api\/expenses\/42\/payment-proof$/);
      expect(body).toBeInstanceOf(FormData);
      const form = body as FormData;
      expect(form.get('comprovante')).toBeInstanceOf(File);
      expect(form.get('cycles_ago')).toBe('2');
      expect(form.get('descricao')).toBe('Pix de sexta');
      expect(form.has('remover_comprovante')).toBe(false);
    });

    it("sends remover_comprovante as '1' in the multipart body", async () => {
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 0, () => {}));

      act(() => result.current.handleEditProof(42, { proof: png(), removeProof: true, note: 'x' }));
      await waitFor(() => expect(axios.post).toHaveBeenCalled());

      expect((vi.mocked(axios.post).mock.calls[0][1] as FormData).get('remover_comprovante')).toBe('1');
    });

    it('always sends descricao, trimmed, and empty as an empty string so the backend clears it (JSON)', async () => {
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 0, () => {}));

      act(() => result.current.handleEditProof(42, { note: '  ref. 12345  ' }));
      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      expect(vi.mocked(axios.post).mock.calls[0][1]).toEqual({ cycles_ago: 0, descricao: 'ref. 12345' });

      act(() => result.current.handleEditProof(42, { note: '   ' }));
      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(2));
      expect(vi.mocked(axios.post).mock.calls[1][1]).toEqual({ cycles_ago: 0, descricao: '' });
    });

    it('always sends descricao, trimmed and even empty, in the multipart body', async () => {
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 0, () => {}));

      act(() => result.current.handleEditProof(42, { proof: png(), note: '  ref. 9  ' }));
      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      expect((vi.mocked(axios.post).mock.calls[0][1] as FormData).get('descricao')).toBe('ref. 9');

      act(() => result.current.handleEditProof(42, { proof: png(), note: '' }));
      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(2));
      const form = vi.mocked(axios.post).mock.calls[1][1] as FormData;
      expect(form.has('descricao')).toBe(true);
      expect(form.get('descricao')).toBe('');
    });

    it('sends the bearer token', async () => {
      localStorage.setItem('accessToken', 'tok-123');
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 0, () => {}));

      act(() => result.current.handleEditProof(42, { note: 'x' }));
      await waitFor(() => expect(axios.post).toHaveBeenCalled());

      expect(vi.mocked(axios.post).mock.calls[0][2]).toEqual({ headers: { Authorization: 'Bearer tok-123' } });
      localStorage.removeItem('accessToken');
    });

    it('flags editSuccess and reloads on success, and the success flag can be dismissed', async () => {
      const reload = vi.fn();
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 0, reload));
      expect(result.current.editSuccess).toBe(false);

      act(() => result.current.handleEditProof(42, { note: 'x' }));
      await waitFor(() => expect(result.current.editSuccess).toBe(true));
      expect(reload).toHaveBeenCalledTimes(1);
      expect(result.current.paySuccess).toBe(false);
      expect(result.current.unpaySuccess).toBe(false);

      act(() => result.current.dismissEditSuccess());
      expect(result.current.editSuccess).toBe(false);
    });

    it('marks the expense as busy while the request is in flight and frees it afterwards', async () => {
      let resolve!: (value: unknown) => void;
      vi.mocked(axios.post).mockReturnValueOnce(new Promise(r => { resolve = r; }));
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 0, () => {}));

      act(() => result.current.handleEditProof(42, { note: 'x' }));
      expect(result.current.payingExpenseId).toBe(42);

      await act(async () => resolve({ data: {} }));
      await waitFor(() => expect(result.current.payingExpenseId).toBeNull());
    });

    it('surfaces the API error in payError, does not reload and does not flag success', async () => {
      const reload = vi.fn();
      vi.spyOn(console, 'error').mockImplementation(() => {});
      vi.mocked(axios.post).mockRejectedValueOnce({
        response: { data: { error: 'Esta competência já foi encerrada. Desfaça o pagamento para corrigir o comprovante.' } },
      });
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 0, reload));

      act(() => result.current.handleEditProof(42, { note: 'x' }));
      await waitFor(() =>
        expect(result.current.payError).toBe(
          'Esta competência já foi encerrada. Desfaça o pagamento para corrigir o comprovante.'
        )
      );
      expect(reload).not.toHaveBeenCalled();
      expect(result.current.editSuccess).toBe(false);
      expect(result.current.payingExpenseId).toBeNull();
    });

    it('falls back to the default message when the API gives no error text', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {});
      vi.mocked(axios.post).mockRejectedValueOnce(new Error('Network Error'));
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 0, () => {}));

      act(() => result.current.handleEditProof(42, { note: 'x' }));
      await waitFor(() => expect(result.current.payError).toBe('Falha ao atualizar o comprovante.'));
    });

    it('clears a previous error when a new edit starts', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {});
      vi.mocked(axios.post).mockRejectedValueOnce(new Error('boom'));
      const { result } = renderHook(() => usePaymentActions(1, summaryWith('closed'), 0, () => {}));

      act(() => result.current.handleEditProof(42, { note: 'x' }));
      await waitFor(() => expect(result.current.payError).not.toBeNull());

      act(() => result.current.handleEditProof(42, { note: 'y' }));
      expect(result.current.payError).toBeNull();
      await waitFor(() => expect(result.current.editSuccess).toBe(true));
    });
  });

  // Guarda em tempo de compilação (roda no `tsc --noEmit`, não no vitest):
  // `handlePay` exige foto OU texto, então o clique "sem popup" não compila.
  // Se alguém voltar a aceitar `handlePay(id)`, estas diretivas ficam sem erro
  // para suprimir e o `tsc` falha com "Unused '@ts-expect-error' directive".
  it('requires a proof or a note in the handlePay type', () => {
    const typeGuard = (handlePay: ReturnType<typeof usePaymentActions>['handlePay']) => {
      // @ts-expect-error — sem detalhes não é um pagamento válido
      handlePay(42);
      // @ts-expect-error — objeto vazio não traz nem foto nem texto
      handlePay(42, {});
    };

    expect(typeof typeGuard).toBe('function');
  });

  // Mesma guarda para a edição: a tela sempre manda o estado final da descrição,
  // então `note` é obrigatório no tipo (omitir levaria o backend a manter a
  // descrição antiga sem que o usuário tenha pedido isso).
  it('requires the note in the handleEditProof type', () => {
    const typeGuard = (handleEditProof: ReturnType<typeof usePaymentActions>['handleEditProof']) => {
      // @ts-expect-error — sem `note` o estado final da descrição fica indefinido
      handleEditProof(42, {});
      // @ts-expect-error — só a remoção da foto não basta, falta a descrição
      handleEditProof(42, { removeProof: true });
    };

    expect(typeof typeGuard).toBe('function');
  });
});
