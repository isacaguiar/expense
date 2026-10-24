import { useCallback, useState } from 'react';
import axios from 'axios';
import { API_BASE_URL } from '../config';

type UseConfirmSettlementResult = {
  submitting: boolean;
  error: string | null;
  clearError: () => void;
  confirm: (toUserId: number, proof: File, cyclesAgo: number, note?: string) => void;
};

/**
 * Envio do comprovante do acerto pelo devedor (`POST
 * /groups/{id}/settlements/confirm`, multipart com `to_user_id`, `comprovante`
 * e `cycles_ago`). Conceito distinto de marcar uma despesa como paga, que é do
 * credor (`usePaymentActions`). Ver
 * docs/feature/concluidas/202608/20260825-pagamentos-grid-pix/plan.md §6.
 *
 * `cyclesAgo` é argumento de `confirm`, não do hook, para quem chama nunca
 * esquecer de dizer qual competência está acertando: sem ele o backend assume
 * o ciclo corrente (aberto) e recusa o acerto de um ciclo anterior já fechado
 * ("só pode ser confirmado depois que a competência é fechada"). Usado por
 * `Payments.tsx` e pelo Painel
 * (docs/feature/concluidas/202610/20261010-painel-financeiro-pessoal/plan.md §3).
 */
export function useConfirmSettlement(
  groupId: string | number | undefined,
  onSuccess: () => void
): UseConfirmSettlementResult {
  const [submitting, setSubmitting] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  const confirm = useCallback(
    (toUserId: number, proof: File, cyclesAgo: number, note?: string) => {
      if (!groupId) return;

      setSubmitting(true);
      setError(null);

      const token = localStorage.getItem('accessToken');
      const form = new FormData();
      form.append('to_user_id', String(toUserId));
      form.append('comprovante', proof);
      form.append('cycles_ago', String(cyclesAgo));
      // Descrição opcional do acerto (servidor: `descricao`, até 500 caracteres, vai para `note`).
      if (note) form.append('descricao', note);

      axios
        .post(`${API_BASE_URL}/api/groups/${groupId}/settlements/confirm`, form, {
          headers: { Authorization: token ? `Bearer ${token}` : '' }
        })
        .then(() => onSuccess())
        .catch(err => {
          console.error('Erro ao confirmar pagamento do settlement:', err);
          setError(err.response?.data?.error ?? 'Falha ao enviar o comprovante.');
        })
        .finally(() => setSubmitting(false));
    },
    [groupId, onSuccess]
  );

  return {
    submitting,
    error,
    clearError: () => setError(null),
    confirm
  };
}
