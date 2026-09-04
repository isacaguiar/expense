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
 * `docs/feature/concluidas/202608/20260822-criacao-tela-pagamentos/plan.md` §6) — mesma regra
 * de habilitação e mesmos endpoints nos dois lugares, só a UX em torno de
 * `handlePay` muda por tela (comprovante é opcional no parâmetro `proof`
 * porque a API também trata a foto como opcional, ver `plan.md` §2).
 *
 * O credor marca/desmarca pagamento em qualquer estado de ciclo exceto
 * `future` (a `Quota` ainda não existe) — inclusive em ciclo `closed` ou já
 * selado (`unpay` de um ciclo selado o dessela no backend). `cyclesAgo` diz
 * ao backend sobre qual competência agir.
 */
export function usePaymentActions(
  currentUserId: number | null,
  summary: Summary | null,
  cyclesAgo: number,
  reload: () => void
): UsePaymentActionsResult {
  const [payingExpenseId, setPayingExpenseId] = useState<number | null>(null);
  const [payError, setPayError] = useState<string | null>(null);
  const [paySuccess, setPaySuccess] = useState<boolean>(false);
  const [unpaySuccess, setUnpaySuccess] = useState<boolean>(false);

  const cycleIsFuture = summary?.cycle.status === 'future';

  const isCreditor = useCallback(
    (exp: SummaryExpense) => currentUserId !== null && currentUserId === exp.userPayerId,
    [currentUserId]
  );

  const canPay = useCallback(
    (exp: SummaryExpense) => !cycleIsFuture && !exp.paid && isCreditor(exp),
    [cycleIsFuture, isCreditor]
  );

  const canUnpay = useCallback(
    (exp: SummaryExpense) => !cycleIsFuture && exp.paid && isCreditor(exp),
    [cycleIsFuture, isCreditor]
  );

  const handlePay = useCallback(
    (expenseId: number, proof?: File) => {
      setPayingExpenseId(expenseId);
      setPayError(null);

      const token = localStorage.getItem('accessToken');
      const body = proof
        ? (() => {
            const form = new FormData();
            form.append('comprovante', proof);
            form.append('cycles_ago', String(cyclesAgo));
            return form;
          })()
        : { cycles_ago: cyclesAgo };

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
    [cyclesAgo, reload]
  );

  const handleUnpay = useCallback(
    (expenseId: number) => {
      setPayingExpenseId(expenseId);
      setPayError(null);

      const token = localStorage.getItem('accessToken');

      axios
        .post(`${API_BASE_URL}/api/expenses/${expenseId}/unpay`, { cycles_ago: cyclesAgo }, {
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
    [cyclesAgo, reload]
  );

  return {
    payingExpenseId,
    payError,
    paySuccess,
    unpaySuccess,
    dismissPayError: () => setPayError(null),
    dismissPaySuccess: () => setPaySuccess(false),
    dismissUnpaySuccess: () => setUnpaySuccess(false),
    isCreditor,
    canPay,
    canUnpay,
    handlePay,
    handleUnpay
  };
}
