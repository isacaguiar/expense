import React, { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import axios from 'axios';
import {
  Avatar,
  AvatarGroup,
  Box,
  Button,
  CircularProgress,
  IconButton,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TextField,
  Tooltip,
  Typography
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import EditOutlinedIcon from '@mui/icons-material/EditOutlined';
import PeopleOutlineOutlinedIcon from '@mui/icons-material/PeopleOutlineOutlined';
import ReceiptLongOutlinedIcon from '@mui/icons-material/ReceiptLongOutlined';
import { API_BASE_URL } from '../config';
import { getInitials } from '../layouts/group/getInitials';
import { brandColors } from '../theme/brandColors';

type Member = { id: number; name: string; email: string };

type Group = {
  id: number;
  name: string;
  description: string;
  create_date: string;
  created_by: number | null;
  creator?: { id: number; email: string } | null;
  members: Member[];
};

const MAX_GROUPS_CREATED_PER_USER = 3;

const Dashboard: React.FC = () => {
  const [groups, setGroups] = useState<Group[]>([]);
  const [currentUserId, setCurrentUserId] = useState<number | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState<string>('');
  const navigate = useNavigate();

  useEffect(() => {
    const token = localStorage.getItem('accessToken');
    const headers = { Authorization: token ? `Bearer ${token}` : '' };

    axios
      .get<{ id: number }>(`${API_BASE_URL}/api/me`, { headers })
      .then(res => setCurrentUserId(res.data.id))
      .catch(err => console.error('Erro ao carregar usuário autenticado:', err));

    axios
      .get<Group[]>(`${API_BASE_URL}/api/groups`, { headers })
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

  const myGroupsCount = groups.filter(group => group.created_by === currentUserId).length;
  const reachedCreationLimit = myGroupsCount >= MAX_GROUPS_CREATED_PER_USER;

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
        <Tooltip title={reachedCreationLimit ? `Você já atingiu o limite de ${MAX_GROUPS_CREATED_PER_USER} grupos criados.` : ''}>
          <span>
            <Button
              variant="contained"
              startIcon={<AddIcon />}
              component={Link}
              to="/groups/new"
              disabled={reachedCreationLimit}
            >
              Novo grupo
            </Button>
          </span>
        </Tooltip>
      </Box>

      {groups.length === 0 ? (
        <Typography color="text.secondary">Você ainda não participa de nenhum grupo.</Typography>
      ) : filteredGroups.length === 0 ? (
        <Typography color="text.secondary">Nenhum grupo encontrado.</Typography>
      ) : (
        <TableContainer component={Paper} elevation={3} sx={{ borderRadius: 2 }}>
          <Table>
            <TableHead>
              <TableRow>
                <TableCell>Nome</TableCell>
                <TableCell>Responsável</TableCell>
                <TableCell>Integrantes</TableCell>
                <TableCell align="right">Ações</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {filteredGroups.map(group => (
                <TableRow key={group.id} hover>
                  <TableCell>
                    <Typography
                      component={Link}
                      to={`/groups/${group.id}/summary`}
                      color="primary"
                      sx={{ textDecoration: 'none', fontWeight: 500 }}
                    >
                      {group.name}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2" color="text.secondary">
                      {group.creator?.email ?? '—'}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    <AvatarGroup max={5} sx={{ justifyContent: 'flex-end' }}>
                      {group.members.map(member => (
                        <Avatar
                          key={member.id}
                          sx={{ bgcolor: brandColors.primaryLight, color: brandColors.primary, fontSize: '0.8rem', width: 32, height: 32 }}
                        >
                          {getInitials(member.email)}
                        </Avatar>
                      ))}
                    </AvatarGroup>
                  </TableCell>
                  <TableCell align="right">
                    <IconButton onClick={() => navigate(`/groups/${group.id}/edit`)} aria-label="Editar grupo">
                      <EditOutlinedIcon fontSize="small" />
                    </IconButton>
                    <IconButton onClick={() => navigate(`/groups/${group.id}/members`)} aria-label="Participantes">
                      <PeopleOutlineOutlinedIcon fontSize="small" />
                    </IconButton>
                    <IconButton onClick={() => navigate(`/groups/${group.id}/expenses`)} aria-label="Despesas">
                      <ReceiptLongOutlinedIcon fontSize="small" />
                    </IconButton>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}
    </>
  );
};

export default Dashboard;
