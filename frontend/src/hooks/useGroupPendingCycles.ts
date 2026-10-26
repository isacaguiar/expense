import { useEffect, useState } from 'react';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import { API_BASE_URL } from '../config';
import type { SummaryCycle, SummaryTotals } from './useGroupCycle';

// Um ciclo encerrado (ou em carência) que ainda aguarda quitação — uma linha da
// aba Acompanhamento de Relatórios (`GET /groups/{groupId}/expenses/pending-cycles`).
// `in_grace` e `awaiting_seal` vêm sempre neste endpoint, então são obrigatórios
// aqui; ficam fora de `SummaryCycle`/`Summary` (a resposta de `summary()` não os
// traz), que seguem compilando sem edição.
export type PendingCycle = {
  // `in_grace`: a fronteira já passou e o ciclo ainda está `open` (carência até `closes_at`).
  cycle: SummaryCycle & { in_grace: boolean };
  totals: SummaryTotals;
  settlements_total: number;
  settlements_confirmed: number;
  // Despesas ainda não pagas, sem contar parcela retroativa nascida quitada.
  pending_expenses_count: number;
  // Já está todo quitado, mas ainda sem o selo (mostrado como "Quitado").
  awaiting_seal: boolean;
};

type UseGroupPendingCyclesResult = {
  cycles: PendingCycle[];
  loading: boolean;
  error: string | null;
};

/**
 * Busca os ciclos que aguardam quitação do grupo, do mais recente ao mais
 * antigo, para a aba Acompanhamento da tela de Relatórios. O backend só lê:
 * a lista não sela nem muda nada, e os números refletem o estado de agora.
 */
export function useGroupPendingCycles(groupId: string | undefined): UseGroupPendingCyclesResult {
  const navigate = useNavigate();

  const [cycles, setCycles] = useState<PendingCycle[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!groupId) return;

    // Resposta de um grupo anterior que chega depois da troca não pode
    // sobrescrever a do grupo atual.
    let current = true;

    setLoading(true);
    setError(null);

    const token = localStorage.getItem('accessToken');
    axios
      .get<{ data: PendingCycle[] }>(`${API_BASE_URL}/api/groups/${groupId}/expenses/pending-cycles`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(res => {
        if (current) setCycles(res.data.data);
      })
      .catch(err => {
        if (!current) return;
        console.error('Erro ao carregar os ciclos em acompanhamento do grupo:', err);
        if (err.response?.status === 401) {
          navigate('/', { replace: true });
          return;
        }
        setError('Falha ao carregar os ciclos em acompanhamento.');
      })
      .finally(() => {
        if (current) setLoading(false);
      });

    return () => {
      current = false;
    };
  }, [groupId, navigate]);

  return { cycles, loading, error };
}
