import axios from 'axios';
import type { NavigateFunction } from 'react-router-dom';
import { API_BASE_URL } from '../config';

export async function logout(navigate: NavigateFunction): Promise<void> {
  const token = localStorage.getItem('accessToken');

  try {
    await axios.post(`${API_BASE_URL}/api/logout`, null, {
      headers: { Authorization: token ? `Bearer ${token}` : '' }
    });
  } catch (err) {
    console.error('Erro ao encerrar sessão no servidor:', err);
  } finally {
    localStorage.clear();
    navigate('/');
  }
}
