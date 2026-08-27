import { useEffect, useState } from 'react';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import { API_BASE_URL } from '../config';
import { Summary } from './useGroupCycle';

type PaginatedCycles = {
  data: Summary[];
  current_page: number;
  last_page: number;
};

type UseGroupCycleHistoryResult = {
  cycles: Summary[];
  loading: boolean;
  error: string | null;
  page: number;
  totalPages: number;
  setPage: (page: number) => void;
};

/**
 * Busca o histórico paginado de ciclos já fechados do grupo
 * (`GET /groups/{groupId}/expenses/cycles`) para a tela de Relatórios —
 * cada item vem no mesmo formato `Summary` que `useGroupCycle` já usa para a
 * competência vigente, sempre com `cycle.status === 'closed'`.
 */
export function useGroupCycleHistory(groupId: string | undefined): UseGroupCycleHistoryResult {
  const navigate = useNavigate();

  const [cycles, setCycles] = useState<Summary[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState<number>(1);
  const [totalPages, setTotalPages] = useState<number>(1);

  useEffect(() => {
    if (!groupId) return;

    setLoading(true);
    setError(null);

    const token = localStorage.getItem('accessToken');
    axios
      .get<PaginatedCycles>(`${API_BASE_URL}/api/groups/${groupId}/expenses/cycles`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' },
        params: { page }
      })
      .then(res => {
        setCycles(res.data.data);
        setTotalPages(res.data.last_page);
      })
      .catch(err => {
        console.error('Erro ao carregar histórico de ciclos do grupo:', err);
        if (err.response?.status === 401) {
          navigate('/', { replace: true });
          return;
        }
        setError('Falha ao carregar o histórico de ciclos do grupo.');
      })
      .finally(() => setLoading(false));
  }, [groupId, page, navigate]);

  // Reseta a paginação ao trocar de grupo.
  useEffect(() => {
    setPage(1);
  }, [groupId]);

  return { cycles, loading, error, page, totalPages, setPage };
}
