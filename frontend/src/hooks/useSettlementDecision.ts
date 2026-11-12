import { useCallback, useRef, useState } from 'react';
import axios from 'axios';
import { API_BASE_URL } from '../config';

export type SettlementDecisionAction = 'accept' | 'refuse' | 'destroy';

type UseSettlementDecisionResult = {
  busy: boolean;
  error: string | null;
  clearError: () => void;
  accept: (fromUserId: number, toUserId: number, cyclesAgo: number) => void;
  refuse: (fromUserId: number, toUserId: number, cyclesAgo: number) => void;
  destroy: (fromUserId: number, toUserId: number, cyclesAgo: number) => void;
};

const GENERIC_ERROR = 'Não foi possível concluir a ação. Tente novamente.';
const NOT_FOUND = 'Este comprovante não está mais disponível. Atualize a tela.';
const FORBIDDEN = 'Você não pode fazer isso neste comprovante.';

/**
 * A mensagem do servidor vem em `error` (422: ciclo encerrado, comprovante já aceito etc.). O 404 e o 403
 * do backend não trazem texto útil (`abort` sem mensagem, ou a do framework em inglês), então ganham um
 * texto por status; qualquer outra falha, o genérico.
 */
function errorMessage(err: unknown): string {
  const response = (err as { response?: { status?: number; data?: { error?: unknown } } } | null)?.response;
  const server = response?.data?.error;

  if (typeof server === 'string' && server !== '') return server;
  if (response?.status === 404) return NOT_FOUND;
  if (response?.status === 403) return FORBIDDEN;

  return GENERIC_ERROR;
}

/**
 * O credor aceita (`POST .../settlements/accept`) ou recusa (`POST .../settlements/refuse`) o comprovante que
 * outra pessoa enviou pelo devedor, e quem enviou, ou o devedor, o exclui (`DELETE .../settlements/confirmation`,
 * o par na query). Todos levam o par (devedor e credor) e o `cycles_ago`, como `useConfirmSettlement`: sem ele o
 * backend assume o ciclo corrente. Uma ação por vez (`busy`); `onSuccess` recebe qual foi, para a tela avisar e
 * recarregar o ciclo.
 * docs/feature/20261110-quem-paga-se-declara-e-credor-recusa/plan.md §11.
 */
export function useSettlementDecision(
  groupId: string | number | undefined,
  onSuccess: (action: SettlementDecisionAction) => void
): UseSettlementDecisionResult {
  const [busy, setBusy] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  // O estado só vale no próximo render; a trava precisa valer já, para dois cliques no mesmo instante.
  const inFlight = useRef<boolean>(false);

  const run = useCallback(
    (action: SettlementDecisionAction, send: (headers: { Authorization: string }) => Promise<unknown>) => {
      if (!groupId || inFlight.current) return;

      inFlight.current = true;
      setBusy(true);
      setError(null);

      const token = localStorage.getItem('accessToken');

      send({ Authorization: token ? `Bearer ${token}` : '' })
        .then(() => onSuccess(action))
        .catch(err => {
          console.error(`Erro ao decidir sobre o comprovante (${action}):`, err);
          setError(errorMessage(err));
        })
        .finally(() => {
          inFlight.current = false;
          setBusy(false);
        });
    },
    [groupId, onSuccess]
  );

  const pair = (fromUserId: number, toUserId: number, cyclesAgo: number) => ({
    from_user_id: fromUserId,
    to_user_id: toUserId,
    cycles_ago: cyclesAgo
  });

  const accept = useCallback(
    (fromUserId: number, toUserId: number, cyclesAgo: number) =>
      run('accept', headers =>
        axios.post(`${API_BASE_URL}/api/groups/${groupId}/settlements/accept`, pair(fromUserId, toUserId, cyclesAgo), { headers })
      ),
    [groupId, run]
  );

  const refuse = useCallback(
    (fromUserId: number, toUserId: number, cyclesAgo: number) =>
      run('refuse', headers =>
        axios.post(`${API_BASE_URL}/api/groups/${groupId}/settlements/refuse`, pair(fromUserId, toUserId, cyclesAgo), { headers })
      ),
    [groupId, run]
  );

  const destroy = useCallback(
    (fromUserId: number, toUserId: number, cyclesAgo: number) =>
      run('destroy', headers =>
        axios.delete(`${API_BASE_URL}/api/groups/${groupId}/settlements/confirmation`, {
          headers,
          params: pair(fromUserId, toUserId, cyclesAgo)
        })
      ),
    [groupId, run]
  );

  return { busy, error, clearError: () => setError(null), accept, refuse, destroy };
}
