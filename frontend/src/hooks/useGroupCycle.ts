import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import { API_BASE_URL } from '../config';

// 'closed_manually': competência fechada manualmente (POST .../expenses/close)
// mas ainda dentro do mês vigente — revisável (reabrir) até a virada do mês,
// diferente de 'closed' (automático, definitivo, por data).
export type CycleStatus = 'closed' | 'open' | 'future' | 'closed_manually';

// `closes_at` = data de corte definitivo (fronteira + carência); até lá um
// ciclo cuja fronteira já passou continua `open`. `settled` = ciclo totalmente
// quitado e selado (foto imutável). Ambos vêm sempre do backend.
export type SummaryCycle = {
  start: string;
  end: string;
  closes_at: string;
  status: CycleStatus;
  settled: boolean;
};

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
  confirmedProofUrl: string | null;
  confirmedAt: string | null;
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
 *
 * Ao trocar de grupo, resolve primeiro em qual ciclo abrir via
 * `GET .../expenses/focus-cycle` (o ciclo fechado/em carência mais recente
 * ainda com pendência, ou 0) e só então busca o `summary` — `focusResolved`
 * segura o fetch de summary para evitar o flash "abre no 0 e pula".
 */
export function useGroupCycle(groupId: string | undefined): UseGroupCycleResult {
  const navigate = useNavigate();

  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [cyclesAgo, setCyclesAgo] = useState<number>(0);
  const [focusResolved, setFocusResolved] = useState<boolean>(false);
  const [reloadToken, setReloadToken] = useState<number>(0);

  // Ao trocar de grupo: reseta e pergunta ao backend qual ciclo focar.
  useEffect(() => {
    if (!groupId) return;

    setFocusResolved(false);

    const token = localStorage.getItem('accessToken');
    axios
      .get<{ cycles_ago: number }>(`${API_BASE_URL}/api/groups/${groupId}/expenses/focus-cycle`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(res => setCyclesAgo(res.data.cycles_ago ?? 0))
      .catch(() => setCyclesAgo(0))
      .finally(() => setFocusResolved(true));
  }, [groupId]);

  useEffect(() => {
    if (!groupId || !focusResolved) return;

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
  }, [groupId, cyclesAgo, focusResolved, reloadToken, navigate]);

  const goToPreviousCycle = useCallback(() => setCyclesAgo(prev => prev + 1), []);
  const goToNextCycle = useCallback(() => setCyclesAgo(prev => prev - 1), []);
  const reload = useCallback(() => setReloadToken(prev => prev + 1), []);

  return { summary, loading, error, cyclesAgo, goToPreviousCycle, goToNextCycle, reload };
}
