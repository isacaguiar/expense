import { useCallback, useState } from 'react';
import axios from 'axios';
import { API_BASE_URL } from '../config';
import type { Summary, SummaryExpense } from './useGroupCycle';

type UsePaymentActionsResult = {
  payingExpenseId: number | null;
  payError: string | null;
  paySuccess: boolean;
  unpaySuccess: boolean;
  dismissPayError: () => void;
  dismissPaySuccess: () => void;
  dismissUnpaySuccess: () => void;
  cycleIsOpen: boolean;
  isCreditor: (exp: SummaryExpense) => boolean;
  canPay: (exp: SummaryExpense) => boolean;
  canUnpay: (exp: SummaryExpense) => boolean;
  handlePay: (expenseId: number, proof?: File) => void;
  handleUnpay: (expenseId: number) => void;
};

/**
 * Ações de "marcar como paga"/"desfazer pagamento" (`POST .../pay`,
 * `POST .../unpay`), compartilhadas entre `ExpenseManager.tsx` (um clique,
 * sem comprovante) e `Payments.tsx` (exige foto antes de confirmar, ver
 * `docs/feature/20260822-criacao-tela-pagamentos/plan.md` §6) — mesma regra
 * de habilitação e mesmos endpoints nos dois lugares, só a UX em torno de
 * `handlePay` muda por tela (comprovante é opcional no parâmetro `proof`
 * porque a API também trata a foto como opcional, ver `plan.md` §2).
 */
export function usePaymentActions(
  currentUserId: number | null,
  summary: Summary | null,
  reload: () => void
): UsePaymentActionsResult {
  const [payingExpenseId, setPayingExpenseId] = useState<number | null>(null);
  const [payError, setPayError] = useState<string | null>(null);
  const [paySuccess, setPaySuccess] = useState<boolean>(false);
  const [unpaySuccess, setUnpaySuccess] = useState<boolean>(false);

  const cycleIsOpen = summary?.cycle.status === 'open';

  const isCreditor = useCallback(
    (exp: SummaryExpense) => currentUserId !== null && currentUserId === exp.userPayerId,
    [currentUserId]
  );

  const canPay = useCallback(
    (exp: SummaryExpense) => cycleIsOpen && !exp.paid && isCreditor(exp),
    [cycleIsOpen, isCreditor]
  );

  const canUnpay = useCallback(
    (exp: SummaryExpense) => cycleIsOpen && exp.paid && isCreditor(exp),
    [cycleIsOpen, isCreditor]
  );

  const handlePay = useCallback(
    (expenseId: number, proof?: File) => {
      setPayingExpenseId(expenseId);
      setPayError(null);

      const token = localStorage.getItem('accessToken');
      const body = proof ? (() => {
        const form = new FormData();
        form.append('comprovante', proof);
        return form;
      })() : null;

      axios
        .post(`${API_BASE_URL}/api/expenses/${expenseId}/pay`, body, {
          headers: { Authorization: token ? `Bearer ${token}` : '' }
        })
        .then(() => {
          setPaySuccess(true);
          reload();
        })
        .catch(err => {
          console.error('Erro ao marcar despesa como paga:', err);
          setPayError(err.response?.data?.error ?? 'Falha ao marcar despesa como paga.');
        })
        .finally(() => setPayingExpenseId(null));
    },
    [reload]
  );

  const handleUnpay = useCallback(
    (expenseId: number) => {
      setPayingExpenseId(expenseId);
      setPayError(null);

      const token = localStorage.getItem('accessToken');

      axios
        .post(`${API_BASE_URL}/api/expenses/${expenseId}/unpay`, null, {
          headers: { Authorization: token ? `Bearer ${token}` : '' }
        })
        .then(() => {
          setUnpaySuccess(true);
          reload();
        })
        .catch(err => {
          console.error('Erro ao desfazer pagamento da despesa:', err);
          setPayError(err.response?.data?.error ?? 'Falha ao desfazer pagamento.');
        })
        .finally(() => setPayingExpenseId(null));
    },
    [reload]
  );

  return {
    payingExpenseId,
    payError,
    paySuccess,
    unpaySuccess,
    dismissPayError: () => setPayError(null),
    dismissPaySuccess: () => setPaySuccess(false),
    dismissUnpaySuccess: () => setUnpaySuccess(false),
    cycleIsOpen,
    isCreditor,
    canPay,
    canUnpay,
    handlePay,
    handleUnpay
  };
}
