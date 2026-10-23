import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import { API_BASE_URL } from '../config';
import type { MyOverview } from '../types/overview';

type UseMyOverviewResult = {
  data: MyOverview | null;
  /** Só a primeira carga: um `reload` mantém os cartões na tela até os dados novos chegarem. */
  loading: boolean;
  error: string | null;
  reload: () => void;
};

/**
 * Carrega a visão pessoal do Painel (`GET /api/me/overview`). Sessão expirada
 * (401) volta para o login, como nos demais hooks de tela; outro erro vira
 * mensagem. `reload` é chamado depois de cada ação (comprovante enviado,
 * despesa marcada ou desmarcada) para os números virem de novo do servidor.
 *
 * docs/feature/20261010-painel-financeiro-pessoal/plan.md §2
 */
export function useMyOverview(): UseMyOverviewResult {
  const navigate = useNavigate();

  const [data, setData] = useState<MyOverview | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [version, setVersion] = useState<number>(0);

  useEffect(() => {
    // Uma resposta que chega depois de um `reload` mais novo é descartada.
    let current = true;

    setError(null);

    const token = localStorage.getItem('accessToken');
    axios
      .get<MyOverview>(`${API_BASE_URL}/api/me/overview`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(res => {
        if (current) setData(res.data);
      })
      .catch(err => {
        if (!current) return;
        console.error('Erro ao carregar o painel:', err);
        if (err.response?.status === 401) {
          navigate('/', { replace: true });
          return;
        }
        setError('Falha ao carregar o painel.');
      })
      .finally(() => {
        if (current) setLoading(false);
      });

    return () => {
      current = false;
    };
  }, [version, navigate]);

  const reload = useCallback(() => setVersion(previous => previous + 1), []);

  return { data, loading, error, reload };
}
