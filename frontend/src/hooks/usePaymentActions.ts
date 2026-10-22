import { useCallback, useState } from 'react';
import axios from 'axios';
import { API_BASE_URL } from '../config';
import type { Summary, SummaryExpense } from './useGroupCycle';

/**
 * O que acompanha um pagamento: foto do comprovante, descrição em texto, ou os
 * dois. O tipo exige **pelo menos um** (docs/feature/concluidas/202610/20261021-comprovante-ao-marcar-paga,
 * specify R1): `handlePay(id)` sem detalhes, o antigo clique sem popup, não compila.
 */
export type PaymentDetails = { proof: File; note?: string } | { proof?: File; note: string };

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
  handlePay: (expenseId: number, details: PaymentDetails) => void;
  handleUnpay: (expenseId: number) => void;
};

/**
 * Ações de "marcar como paga"/"desfazer pagamento" (`POST .../pay`,
 * `POST .../unpay`), compartilhadas entre `ExpenseManager.tsx` e
 * `Payments.tsx` — mesma regra de habilitação e mesmos endpoints nos dois
 * lugares. Nas duas telas o ✓ abre o `PaymentProofDialog`, que só deixa
 * confirmar com foto ou descrição; o tipo `PaymentDetails` repete essa
 * exigência na compilação. A API segue aceitando `pay` sem corpo (decisão D1
 * da feature, por compatibilidade), mas esta tela nunca o chama assim.
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
    (expenseId: number, details: PaymentDetails) => {
      setPayingExpenseId(expenseId);
      setPayError(null);

      const token = localStorage.getItem('accessToken');
      const { proof } = details;
      const note = details.note?.trim();
      const body = proof
        ? (() => {
            const form = new FormData();
            form.append('comprovante', proof);
            form.append('cycles_ago', String(cyclesAgo));
            if (note) form.append('descricao', note);
            return form;
          })()
        : { cycles_ago: cyclesAgo, ...(note ? { descricao: note } : {}) };

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
