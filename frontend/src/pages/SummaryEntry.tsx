import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { Link, useNavigate } from 'react-router-dom';
import {
  Box,
  Card,
  CardActionArea,
  CardContent,
  CircularProgress,
  Grid,
  Typography
} from '@mui/material';
import { API_BASE_URL } from '../config';

type Group = {
  id: number;
  name: string;
  description: string;
  create_date: string;
};

const SummaryEntry: React.FC = () => {
  const [groups, setGroups] = useState<Group[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const navigate = useNavigate();

  useEffect(() => {
    const token = localStorage.getItem('accessToken');
    axios
      .get<Group[]>(`${API_BASE_URL}/api/groups`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(res => {
        if (res.data.length === 1) {
          navigate(`/groups/${res.data[0].id}/summary`, { replace: true });
          return;
        }
        setGroups(res.data);
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

  if (groups.length === 0) {
    return <Typography color="text.secondary">Você ainda não participa de nenhum grupo.</Typography>;
  }

  return (
    <Grid container spacing={2}>
      {groups.map(group => (
        <Grid size={{ xs: 12, sm: 6, md: 4 }} key={group.id}>
          <Card elevation={3} sx={{ borderRadius: 2 }}>
            <CardActionArea component={Link} to={`/groups/${group.id}/summary`}>
              <CardContent>
                <Typography variant="h6" color="primary">
                  {group.name}
                </Typography>
                <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
                  {group.description}
                </Typography>
              </CardContent>
            </CardActionArea>
          </Card>
        </Grid>
      ))}
    </Grid>
  );
};

export default SummaryEntry;
