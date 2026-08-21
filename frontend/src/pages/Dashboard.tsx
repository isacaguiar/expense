import React, { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import axios from 'axios';
import {
  Box,
  Button,
  Card,
  CardActionArea,
  CardActions,
  CardContent,
  CircularProgress,
  Grid,
  IconButton,
  TextField,
  Typography
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import EditOutlinedIcon from '@mui/icons-material/EditOutlined';
import PeopleOutlineOutlinedIcon from '@mui/icons-material/PeopleOutlineOutlined';
import ReceiptLongOutlinedIcon from '@mui/icons-material/ReceiptLongOutlined';
import { API_BASE_URL } from '../config';

type Group = {
  id: number;
  name: string;
  description: string;
  create_date: string;
};

const Dashboard: React.FC = () => {
  const [groups, setGroups] = useState<Group[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState<string>('');
  const navigate = useNavigate();

  useEffect(() => {
    const token = localStorage.getItem('accessToken');
    axios
      .get<Group[]>(`${API_BASE_URL}/api/groups`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(res => setGroups(res.data))
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

  const filteredGroups = groups.filter(group =>
    group.name.toLowerCase().includes(search.toLowerCase())
  );

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

  return (
    <>
      <Box display="flex" justifyContent="space-between" alignItems="center" gap={2} mb={3} flexWrap="wrap">
        <TextField
          label="Buscar grupo"
          value={search}
          onChange={e => setSearch(e.target.value)}
          size="small"
          sx={{ minWidth: 260 }}
        />
        <Button variant="contained" startIcon={<AddIcon />} component={Link} to="/groups/new">
          Novo grupo
        </Button>
      </Box>

      {groups.length === 0 ? (
        <Typography color="text.secondary">Você ainda não participa de nenhum grupo.</Typography>
      ) : filteredGroups.length === 0 ? (
        <Typography color="text.secondary">Nenhum grupo encontrado.</Typography>
      ) : (
        <Grid container spacing={2}>
          {filteredGroups.map(group => (
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
                <CardActions>
                  <IconButton onClick={() => navigate(`/groups/${group.id}/edit`)} aria-label="Editar grupo">
                    <EditOutlinedIcon fontSize="small" />
                  </IconButton>
                  <IconButton onClick={() => navigate(`/groups/${group.id}/members`)} aria-label="Participantes">
                    <PeopleOutlineOutlinedIcon fontSize="small" />
                  </IconButton>
                  <IconButton onClick={() => navigate(`/groups/${group.id}/expenses`)} aria-label="Despesas">
                    <ReceiptLongOutlinedIcon fontSize="small" />
                  </IconButton>
                </CardActions>
              </Card>
            </Grid>
          ))}
        </Grid>
      )}
    </>
  );
};

export default Dashboard;
