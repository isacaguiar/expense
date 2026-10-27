import { useEffect, useState } from 'react';
import axios from 'axios';
import { API_BASE_URL } from '../config';

/**
 * Id do usuário autenticado (`GET /api/me`), ou `null` enquanto não chega, se a
 * resposta não traz um id numérico ou se a chamada falha. Substitui as cópias
 * que `ExpenseManager` e `Payments` tinham desse mesmo efeito e serve a
 * `GroupSummary`, que precisa do id para pôr o usuário logado primeiro no Saldo
 * (docs/feature/concluidas/202610/20261026-organizacao-despesas-pagamentos/plan.md §3).
 */
export function useCurrentUserId(): number | null {
  const [currentUserId, setCurrentUserId] = useState<number | null>(null);

  useEffect(() => {
    const token = localStorage.getItem('accessToken');
    axios
      .get<{ id?: number }>(`${API_BASE_URL}/api/me`, { headers: { Authorization: token ? `Bearer ${token}` : '' } })
      .then(res => setCurrentUserId(typeof res.data.id === 'number' ? res.data.id : null))
      .catch(err => console.error('Erro ao carregar usuário autenticado:', err));
  }, []);

  return currentUserId;
}
