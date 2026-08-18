import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import EditIcon from '@mui/icons-material/Edit';
import GroupAddIcon from '@mui/icons-material/GroupAdd';
import AttachMoneyIcon from '@mui/icons-material/AttachMoney';
import ReceiptIcon from '@mui/icons-material/Receipt';
import { CardActions, IconButton } from '@mui/material';

import {
  Box,
  Button,
  Card,
  CardContent,
  CircularProgress,
  Container,
  Grid,
  TextField,
  Typography
} from '@mui/material';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import { API_BASE_URL } from '../config';

// Tipo do grupo conforme API
type Group = {
  id: number;
  name: string;
  description: string;
  create_date: string;
};

const GroupList: React.FC = () => {
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
        setError(err.response?.status === 401
          ? 'Usuário não autenticado. Faça login novamente.'
          : 'Falha ao carregar grupos.');
      })
      .finally(() => setLoading(false));
  }, []);

  const filteredGroups = groups.filter(group =>
    group.name.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <Container sx={{ mt: 4 }}>
      {/* Cabeçalho com título e botão para criar novo grupo */}
      <Box display="flex" justifyContent="space-between" alignItems="center" mb={3}>
        <Typography variant="h4">Meus Grupos XXX</Typography>
        <Button
          variant="contained"
          color="primary"
          component={Link}
          to="/groups/new"
        >
          Novo Grupo
        </Button>
      </Box>

      {/* Campo de busca */}
      <Box mb={2}>
        <TextField
          label="Buscar"
          value={search}
          onChange={e => setSearch(e.target.value)}
          fullWidth
        />
      </Box>

      {/* Carregando, erro ou lista de grupos */}
      {loading ? (
        <CircularProgress />
      ) : error ? (
        <Typography color="error">{error}</Typography>
      ) : (
        <Grid container spacing={2}>
          {filteredGroups.length > 0 ? (
            filteredGroups.map(group => (
              <Grid size={{ xs: 12, sm: 6, md: 4 }} key={group.id}>

                <Card elevation={3} sx={{ borderRadius: 2 }}>
                  <CardContent>
                    <Typography variant="h6" color="primary">
                      {group.name}
                    </Typography>
                    <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
                      {group.description}
                    </Typography>
                  </CardContent>
                  <CardActions>
                    <IconButton onClick={() => navigate(`/groups/${group.id}/edit`)} aria-label="Editar grupo">
                      <EditIcon />
                    </IconButton>
                    <IconButton onClick={() => navigate(`/groups/${group.id}/members`)} aria-label="Adicionar membros">
                      <GroupAddIcon />
                    </IconButton>
                    <IconButton onClick={() => navigate(`/groups/${group.id}/expenses`)} aria-label="Gerir despesas">
                      <ReceiptIcon />
                    </IconButton>
                  </CardActions>
                </Card>

              </Grid>
            ))
          ) : (
            <Typography>Nenhum grupo encontrado.</Typography>
          )}
        </Grid>
      )}
    </Container>
  );
};

export default GroupList;
