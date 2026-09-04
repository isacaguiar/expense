import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { API_BASE_URL } from '../config';

/** Único polling do app — ver docs/feature/20260903-notificacoes-in-app/plan.md §4. */
const POLL_INTERVAL_MS = 60_000;

type UseUnreadNotificationsCountResult = {
  count: number;
  refetch: () => void;
};

/**
 * Contagem de notificações não-lidas do usuário logado, para o badge do sino
 * no cabeçalho (`GET /api/notifications/unread-count`). Busca na montagem e a
 * cada 60s. Um erro/401 num poll de fundo é **silencioso** — não redireciona;
 * a próxima navegação ou ação real é que trata a expiração da sessão.
 */
export function useUnreadNotificationsCount(): UseUnreadNotificationsCountResult {
  const [count, setCount] = useState<number>(0);

  const refetch = useCallback(() => {
    const token = localStorage.getItem('accessToken');
    axios
      .get<{ count: number }>(`${API_BASE_URL}/api/notifications/unread-count`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(res => setCount(res.data.count))
      .catch(() => {
        // badge é best-effort: mantém o valor atual e tenta de novo no próximo poll
      });
  }, []);

  useEffect(() => {
    refetch();
    const id = setInterval(refetch, POLL_INTERVAL_MS);
    return () => clearInterval(id);
  }, [refetch]);

  return { count, refetch };
}
