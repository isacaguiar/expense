import { useEffect, useState } from 'react';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import { API_BASE_URL } from '../config';
import type { Summary, SummaryCycle } from './useGroupCycle';

// `id` é nulo quando o snapshot é antigo e o nome não casa com nenhum membro do grupo.
export type ClosingParty = { id: number | null; name: string; avatarUrl: string | null };

export type ClosingDebtor = ClosingParty & { amount: number };

// Quem adiantou dinheiro no ciclo e quanto cada participante lhe deve, no valor
// bruto por despesa (ainda sem o netting dos acertos).
export type ClosingCreditor = {
  creditor: ClosingParty;
  advanced_total: number;
  debtors: ClosingDebtor[];
};

// O Relatório de Fechamento de um ciclo (`GET /groups/{groupId}/expenses/closing-report`).
// Reaproveita o formato de `Summary` e acrescenta o grupo, `in_grace` (que o
// `summary()` não envia, por isso fica fora de `SummaryCycle`) e os credores.
export type ClosingReport = Omit<Summary, 'cycle'> & {
  group: { id: number; name: string };
  cycle: SummaryCycle & { in_grace: boolean };
  creditors: ClosingCreditor[];
};

type UseClosingReportResult = {
  report: ClosingReport | null;
  loading: boolean;
  error: string | null;
};

/**
 * Busca o Relatório de Fechamento do ciclo que começa em `cycleStart`
 * (`YYYY-MM-DD`, o `?ciclo=` da URL de Relatórios). Sem ciclo escolhido não
 * chama a API. O backend só lê: abrir o relatório não sela nem muda nada.
 *
 * `404` (ciclo futuro, data que não abre ciclo, grupo alheio) e `422` (data mal
 * formada na URL) dão a mesma mensagem: para quem abriu o link, é um ciclo que
 * não existe.
 */
export function useClosingReport(groupId: string | undefined, cycleStart: string | undefined): UseClosingReportResult {
  const navigate = useNavigate();

  const [report, setReport] = useState<ClosingReport | null>(null);
  const [loading, setLoading] = useState<boolean>(Boolean(groupId && cycleStart));
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setReport(null);
    setError(null);

    if (!groupId || !cycleStart) {
      setLoading(false);
      return;
    }

    // Resposta de um ciclo anterior que chega depois da troca não pode
    // sobrescrever a do ciclo atual.
    let current = true;

    setLoading(true);

    const token = localStorage.getItem('accessToken');
    axios
      .get<ClosingReport>(`${API_BASE_URL}/api/groups/${groupId}/expenses/closing-report`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' },
        params: { cycle_start: cycleStart }
      })
      .then(res => {
        if (current) setReport(res.data);
      })
      .catch(err => {
        if (!current) return;
        console.error('Erro ao carregar o Relatório de Fechamento do grupo:', err);
        const status = err.response?.status;
        if (status === 401) {
          navigate('/', { replace: true });
          return;
        }
        setError(status === 404 || status === 422 ? 'Ciclo não encontrado.' : 'Falha ao carregar o Relatório de Fechamento.');
      })
      .finally(() => {
        if (current) setLoading(false);
      });

    return () => {
      current = false;
    };
  }, [groupId, cycleStart, navigate]);

  return { report, loading, error };
}
