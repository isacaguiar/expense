import { useEffect, useState } from 'react';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import { API_BASE_URL } from '../config';
import { CycleStatus } from './useGroupCycle';

export type GrossDebtor = { id: number; name: string; amount: number };

export type GrossCreditor = {
  creditor: { id: number; name: string; email: string; pix: string | null };
  debtors: GrossDebtor[];
};

export type GrossDebtsData = {
  cycle: { start: string; end: string; status: CycleStatus };
  creditors: GrossCreditor[];
};

type UseGroupGrossDebtsResult = {
  data: GrossDebtsData | null;
  loading: boolean;
  error: string | null;
};

/**
 * Busca a árvore Credor→devedores (valores brutos, sem netting) de um grupo
 * numa competência (`GET /groups/{groupId}/expenses/gross-debts`) — usado
 * pelo painel expansível do Dashboard. O carregamento "sob demanda" vem de
 * quem usa este hook só montar o componente quando a linha expande, não de
 * uma flag `enabled` aqui.
 */
export function useGroupGrossDebts(groupId: string, cyclesAgo: number): UseGroupGrossDebtsResult {
  const navigate = useNavigate();

  const [data, setData] = useState<GrossDebtsData | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setLoading(true);
    setError(null);

    const token = localStorage.getItem('accessToken');
    axios
      .get<GrossDebtsData>(`${API_BASE_URL}/api/groups/${groupId}/expenses/gross-debts`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' },
        params: { cycles_ago: cyclesAgo }
      })
      .then(res => setData(res.data))
      .catch(err => {
        console.error('Erro ao carregar resumo de pendências do grupo:', err);
        if (err.response?.status === 401) {
          navigate('/', { replace: true });
          return;
        }
        setError('Falha ao carregar o resumo de pendências do grupo.');
      })
      .finally(() => setLoading(false));
  }, [groupId, cyclesAgo, navigate]);

  return { data, loading, error };
}
