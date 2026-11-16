import { useCallback, useRef, useState } from 'react';
import axios from 'axios';
import { apiErrorMessage } from '../utils/expenseRequest';
import { API_BASE_URL } from '../config';

type UseSettlementComplementResult = {
  busy: boolean;
  error: string | null;
  clearError: () => void;
  complement: (fromUserId: number, toUserId: number, cyclesAgo: number, proof: File, justification: string) => void;
};

const GENERIC_ERROR = 'Não foi possível enviar a justificativa. Tente novamente.';

/**
 * Justificativa com foto de quem paga pelo devedor sobre o comprovante que o próprio devedor enviou (`POST
 * /groups/{id}/settlements/complement`, multipart com `from_user_id`, `to_user_id`, `cycles_ago`, `comprovante`
 * e `justificativa`; feature 20261114-historico-de-comprovantes-e-justificativa, plan §11). Mesmo cabeçalho e
 * mesmo `cycles_ago` de `useConfirmSettlement`. Uma ação por vez (`busy`); a mensagem `error` do servidor
 * (422: ciclo, estado, limite) vai à tela, e sem ela o texto genérico.
 */
export function useSettlementComplement(
  groupId: string | number | undefined,
  onSuccess: () => void
): UseSettlementComplementResult {
  const [busy, setBusy] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  // O estado só vale no próximo render; a trava precisa valer já, para dois cliques no mesmo instante.
  const inFlight = useRef<boolean>(false);

  const complement = useCallback(
    (fromUserId: number, toUserId: number, cyclesAgo: number, proof: File, justification: string) => {
      if (!groupId || inFlight.current) return;

      inFlight.current = true;
      setBusy(true);
      setError(null);

      const token = localStorage.getItem('accessToken');
      const form = new FormData();
      form.append('from_user_id', String(fromUserId));
      form.append('to_user_id', String(toUserId));
      form.append('cycles_ago', String(cyclesAgo));
      form.append('comprovante', proof);
      form.append('justificativa', justification);

      axios
        .post(`${API_BASE_URL}/api/groups/${groupId}/settlements/complement`, form, {
          headers: { Authorization: token ? `Bearer ${token}` : '' }
        })
        .then(() => onSuccess())
        .catch(err => {
          console.error('Erro ao enviar a justificativa do acerto:', err);
          setError(apiErrorMessage(err, GENERIC_ERROR));
        })
        .finally(() => {
          inFlight.current = false;
          setBusy(false);
        });
    },
    [groupId, onSuccess]
  );

  return { busy, error, clearError: () => setError(null), complement };
}
