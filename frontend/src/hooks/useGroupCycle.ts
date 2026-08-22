import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import { API_BASE_URL } from '../config';

// 'closed_manually': competência fechada manualmente (POST .../expenses/close)
// mas ainda dentro do mês vigente — revisável (reabrir) até a virada do mês,
// diferente de 'closed' (automático, definitivo, por data).
export type CycleStatus = 'closed' | 'open' | 'future' | 'closed_manually';

export type SummaryCycle = { start: string; end: string; status: CycleStatus };

export type SummaryTotals = { total: number; paid: number; pending: number };

export type SummaryExpense = {
  id: number;
  description: string;
  date: string;
  value: number;
  valuePerPerson: number;
  paid: boolean;
  paymentProofUrl: string | null;
  payerName: string | null;
  participants: string[];
  isFixed: boolean;
  userPayerId: number;
  userCreatorId: number;
};

export type SummaryBalance = {
  user_id: number;
  name: string;
  balance: number;
};

export type SummarySettlement = {
  from_user_id: number;
  to_user_id: number;
  amount: number;
};

export type Summary = {
  cycle: SummaryCycle;
  totals: SummaryTotals;
  expenses: SummaryExpense[];
  balances: SummaryBalance[];
  settlements: SummarySettlement[];
};

export const cycleStatusChip: Record<
  CycleStatus,
  { label: string; color: 'default' | 'info' | 'warning'; variant?: 'outlined' }
> = {
  closed: { label: 'Ciclo fechado', color: 'default' },
  open: { label: 'Ciclo em andamento', color: 'info' },
  future: { label: 'Ciclo futuro', color: 'default', variant: 'outlined' },
  closed_manually: { label: 'Fechado (revisável)', color: 'warning' },
};

type UseGroupCycleResult = {
  summary: Summary | null;
  loading: boolean;
  error: string | null;
  cyclesAgo: number;
  goToPreviousCycle: () => void;
  goToNextCycle: () => void;
  reload: () => void;
};

/**
 * Busca o resumo de despesas/saldos do grupo na competência selecionada
 * (`GET /groups/{groupId}/expenses/summary`) e expõe a navegação por ciclo
 * (`cyclesAgo`) — compartilhado entre a Home do grupo (`GroupSummary`) e a
 * tela de despesas (`ExpenseManager`), que devem navegar pela mesma noção
 * real de competência (`BillingCycle` do backend), não por mês calendário.
 */
export function useGroupCycle(groupId: string | undefined): UseGroupCycleResult {
  const navigate = useNavigate();

  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [cyclesAgo, setCyclesAgo] = useState<number>(0);
  const [reloadToken, setReloadToken] = useState<number>(0);

  useEffect(() => {
    if (!groupId) return;

    setLoading(true);
    setError(null);

    const token = localStorage.getItem('accessToken');
    axios
      .get<Summary>(`${API_BASE_URL}/api/groups/${groupId}/expenses/summary`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' },
        params: { cycles_ago: cyclesAgo }
      })
      .then(res => setSummary(res.data))
      .catch(err => {
        console.error('Erro ao carregar resumo do grupo:', err);
        if (err.response?.status === 401) {
          navigate('/', { replace: true });
          return;
        }
        setError('Falha ao carregar o resumo do grupo.');
      })
      .finally(() => setLoading(false));
  }, [groupId, cyclesAgo, reloadToken, navigate]);

  // Reseta a navegação de ciclo ao trocar de grupo.
  useEffect(() => {
    setCyclesAgo(0);
  }, [groupId]);

  const goToPreviousCycle = useCallback(() => setCyclesAgo(prev => prev + 1), []);
  const goToNextCycle = useCallback(() => setCyclesAgo(prev => prev - 1), []);
  const reload = useCallback(() => setReloadToken(prev => prev + 1), []);

  return { summary, loading, error, cyclesAgo, goToPreviousCycle, goToNextCycle, reload };
}
