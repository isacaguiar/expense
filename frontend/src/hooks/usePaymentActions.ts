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

/**
 * Edição do comprovante de uma despesa **já paga** (`POST .../payment-proof`,
 * docs/feature/concluidas/202610/20261022-editar-comprovante-depois-de-paga, plan §3). A tela
 * sempre manda o estado final da descrição, por isso `note` é obrigatório (texto
 * vazio limpa a descrição). `proof` troca ou anexa a foto; `removeProof` apaga a
 * atual. As duas coisas juntas o backend recusa, e o diálogo nunca as monta.
 */
export type EditProofInput = { proof?: File; removeProof?: boolean; note: string };

type UsePaymentActionsResult = {
  payingExpenseId: number | null;
  payError: string | null;
  paySuccess: boolean;
  unpaySuccess: boolean;
  editSuccess: boolean;
  undoPurchaseSuccess: boolean;
  dismissPayError: () => void;
  dismissPaySuccess: () => void;
  dismissUnpaySuccess: () => void;
  dismissEditSuccess: () => void;
  dismissUndoPurchaseSuccess: () => void;
  isCreditor: (exp: SummaryExpense) => boolean;
  canPay: (exp: SummaryExpense) => boolean;
  canUnpay: (exp: SummaryExpense) => boolean;
  canEditProof: (exp: SummaryExpense) => boolean;
  canUndoPurchase: (exp: SummaryExpense) => boolean;
  handlePay: (expenseId: number, details: PaymentDetails) => void;
  handleUnpay: (expenseId: number) => void;
  handleEditProof: (expenseId: number, input: EditProofInput) => void;
  handleUndoPurchase: (expenseId: number) => void;
};

/**
 * Ações de "marcar como paga"/"desfazer pagamento"/"editar comprovante"
 * (`POST .../pay`, `POST .../unpay`, `POST .../payment-proof`), compartilhadas
 * entre `ExpenseManager.tsx` e `Payments.tsx` — mesma regra de habilitação e
 * mesmos endpoints nos dois lugares. Nas duas telas o ✓ abre o
 * `PaymentProofDialog`, que só deixa confirmar com foto ou descrição; o tipo
 * `PaymentDetails` repete essa exigência na compilação. A API segue aceitando
 * `pay` sem corpo (decisão D1 da feature, por compatibilidade), mas esta tela
 * nunca o chama assim.
 *
 * O credor marca/desmarca pagamento em qualquer estado de ciclo exceto
 * `future` (a `Quota` ainda não existe) — inclusive em ciclo `closed` ou já
 * selado (`unpay` de um ciclo selado o dessela no backend). `cyclesAgo` diz
 * ao backend sobre qual competência agir.
 *
 * Editar o comprovante (`canEditProof`) é diferente: só vale com a despesa paga
 * e **nunca em ciclo selado** (`cycle.settled`), onde o backend responde 422
 * ("Desfaça o pagamento para corrigir o comprovante"). A edição troca, remove ou
 * anexa a foto e ajusta a descrição sem tocar em pago/data/quem pagou.
 *
 * Desfazer o pagamento da compra (`canUndoPurchase`, `POST .../undo-purchase`) limpa de
 * uma vez todas as parcelas de uma compra parcelada paga no cadastro, inclusive as futuras,
 * que o `unpay` por parcela não alcança. Quem decide se ainda dá é o servidor, que manda
 * `canUndoPurchase: true` só enquanto nenhuma parcela paga está em ciclo encerrado
 * (docs/feature/20261027-pagamento-no-cadastro-despesa, R19 e D11); a rota não recebe ciclo.
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
  const [editSuccess, setEditSuccess] = useState<boolean>(false);
  const [undoPurchaseSuccess, setUndoPurchaseSuccess] = useState<boolean>(false);

  const cycleIsFuture = summary?.cycle.status === 'future';
  // Editar o comprovante exige provar que o ciclo não está selado; sem o resumo
  // carregado isso não dá para saber, então não se oferece (ao contrário de
  // `canPay`/`canUnpay`, que só excluem o ciclo `future`).
  const cycleAllowsProofEdit = summary !== null && !cycleIsFuture && summary.cycle.settled !== true;

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

  const canEditProof = useCallback(
    (exp: SummaryExpense) => cycleAllowsProofEdit && exp.paid && isCreditor(exp),
    [cycleAllowsProofEdit, isCreditor]
  );

  // A chave só existe quando o servidor a manda `true`; conferir o credor aqui é só uma
  // segunda trava, a regra de verdade (janela aberta) continua sendo do servidor.
  const canUndoPurchase = useCallback(
    (exp: SummaryExpense) => exp.canUndoPurchase === true && isCreditor(exp),
    [isCreditor]
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

  const handleEditProof = useCallback(
    (expenseId: number, { proof, removeProof, note }: EditProofInput) => {
      setPayingExpenseId(expenseId);
      setPayError(null);

      const token = localStorage.getItem('accessToken');
      // `descricao` vai sempre, mesmo vazia: a edição manda o estado final, e o
      // backend limpa a descrição quando recebe texto vazio (omitir a manteria).
      const descricao = note.trim();
      const body = proof
        ? (() => {
            const form = new FormData();
            form.append('comprovante', proof);
            form.append('cycles_ago', String(cyclesAgo));
            form.append('descricao', descricao);
            // O `boolean` do Laravel não aceita a string "true"; '1' é aceito.
            if (removeProof) form.append('remover_comprovante', '1');
            return form;
          })()
        : { cycles_ago: cyclesAgo, descricao, ...(removeProof ? { remover_comprovante: true } : {}) };

      axios
        .post(`${API_BASE_URL}/api/expenses/${expenseId}/payment-proof`, body, {
          headers: { Authorization: token ? `Bearer ${token}` : '' }
        })
        .then(() => {
          setEditSuccess(true);
          reload();
        })
        .catch(err => {
          console.error('Erro ao atualizar o comprovante da despesa:', err);
          setPayError(err.response?.data?.error ?? 'Falha ao atualizar o comprovante.');
        })
        .finally(() => setPayingExpenseId(null));
    },
    [cyclesAgo, reload]
  );

  const handleUndoPurchase = useCallback(
    (expenseId: number) => {
      setPayingExpenseId(expenseId);
      setPayError(null);

      const token = localStorage.getItem('accessToken');

      axios
        .post(`${API_BASE_URL}/api/expenses/${expenseId}/undo-purchase`, {}, {
          headers: { Authorization: token ? `Bearer ${token}` : '' }
        })
        .then(() => {
          setUndoPurchaseSuccess(true);
          reload();
        })
        .catch(err => {
          console.error('Erro ao desfazer o pagamento da compra:', err);
          setPayError(err.response?.data?.error ?? 'Falha ao desfazer o pagamento da compra.');
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
    editSuccess,
    undoPurchaseSuccess,
    dismissPayError: () => setPayError(null),
    dismissPaySuccess: () => setPaySuccess(false),
    dismissUnpaySuccess: () => setUnpaySuccess(false),
    dismissEditSuccess: () => setEditSuccess(false),
    dismissUndoPurchaseSuccess: () => setUndoPurchaseSuccess(false),
    isCreditor,
    canPay,
    canUnpay,
    canEditProof,
    canUndoPurchase,
    handlePay,
    handleUnpay,
    handleEditProof,
    handleUndoPurchase
  };
}
