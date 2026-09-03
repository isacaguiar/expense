import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import { Box, CircularProgress, Typography } from '@mui/material';
import { API_BASE_URL } from '../config';
import { mostActiveGroup } from './mostActiveGroup';

type Group = {
  id: number;
  name: string;
  description: string;
  create_date: string;
  expenses_max_date_payment: string | null;
};

const MembersEntry: React.FC = () => {
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [empty, setEmpty] = useState<boolean>(false);
  const navigate = useNavigate();

  useEffect(() => {
    const token = localStorage.getItem('accessToken');
    axios
      .get<Group[]>(`${API_BASE_URL}/api/groups`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(res => {
        const target = mostActiveGroup(res.data);
        if (!target) {
          setEmpty(true);
          return;
        }
        navigate(`/groups/${target.id}/members`, { replace: true });
      })
      .catch(err => {
        console.error('Erro ao carregar grupos:', err);
        if (err.response?.status === 401) {
          navigate('/', { replace: true });
          return;
        }
        setError('Falha ao carregar grupos.');
      })
      .finally(() => setLoading(false));
  }, [navigate]);

  if (loading) {
    return (
      <Box display="flex" justifyContent="center" mt={4}>
        <CircularProgress />
      </Box>
    );
  }

  if (error) {
    return <Typography color="error">{error}</Typography>;
  }

  if (empty) {
    return <Typography color="text.secondary">Você ainda não participa de nenhum grupo.</Typography>;
  }

  return null;
};

export default MembersEntry;
