import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { Link, useNavigate } from 'react-router-dom';
import {
  Box,
  Card,
  CardContent,
  CircularProgress,
  Container,
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

const ExpensesEntry: React.FC = () => {
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
          navigate(`/groups/${res.data[0].id}/expenses`, { replace: true });
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
      <Container sx={{ mt: 4 }}>
        <Box display="flex" justifyContent="center">
          <CircularProgress />
        </Box>
      </Container>
    );
  }

  if (error) {
    return (
      <Container sx={{ mt: 4 }}>
        <Typography color="error">{error}</Typography>
      </Container>
    );
  }

  if (groups.length === 0) {
    return (
      <Container sx={{ mt: 4 }}>
        <Typography>Você ainda não participa de nenhum grupo.</Typography>
      </Container>
    );
  }

  return (
    <Container sx={{ mt: 4 }}>
      <Typography variant="h4" mb={3}>
        Escolha um grupo
      </Typography>
      <Grid container spacing={2}>
        {groups.map(group => (
          <Grid size={{ xs: 12, sm: 6, md: 4 }} key={group.id}>
            <Card
              elevation={3}
              sx={{ borderRadius: 2 }}
              component={Link}
              to={`/groups/${group.id}/expenses`}
              style={{ textDecoration: 'none', color: 'inherit', display: 'block' }}
            >
              <CardContent>
                <Typography variant="h6" color="primary">
                  {group.name}
                </Typography>
                <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
                  {group.description}
                </Typography>
              </CardContent>
            </Card>
          </Grid>
        ))}
      </Grid>
    </Container>
  );
};

export default ExpensesEntry;
