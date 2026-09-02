import React, { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import axios from 'axios';
import {
  Alert,
  Avatar,
  AvatarGroup,
  Box,
  Button,
  Card,
  CardContent,
  CircularProgress,
  Collapse,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  Paper,
  Snackbar,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TextField,
  Tooltip,
  Typography,
  useMediaQuery,
  useTheme
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import EditOutlinedIcon from '@mui/icons-material/EditOutlined';
import ExpandLessIcon from '@mui/icons-material/ExpandLess';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import PeopleOutlineOutlinedIcon from '@mui/icons-material/PeopleOutlineOutlined';
import ReceiptLongOutlinedIcon from '@mui/icons-material/ReceiptLongOutlined';
import { API_BASE_URL } from '../config';
import { getInitials } from '../layouts/group/getInitials';
import { brandColors } from '../theme/brandColors';
import GroupGrossDebtsPanel from '../components/GroupGrossDebtsPanel';

type Member = { id: number; name: string; email: string };

type Group = {
  id: number;
  name: string;
  description: string;
  create_date: string;
  created_by: number | null;
  creator?: { id: number; email: string } | null;
  members: Member[];
  cycle_snapshots_exists: boolean;
};

const MAX_GROUPS_CREATED_PER_USER = 3;

const Dashboard: React.FC = () => {
  const [groups, setGroups] = useState<Group[]>([]);
  const [currentUserId, setCurrentUserId] = useState<number | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState<string>('');
  const [removeGroupId, setRemoveGroupId] = useState<number | null>(null);
  const [removeSuccessMessage, setRemoveSuccessMessage] = useState<string | null>(null);
  const [expandedGroupIds, setExpandedGroupIds] = useState<Set<number>>(new Set());
  const navigate = useNavigate();
  const theme = useTheme();
  // Abaixo de `sm` a tabela de 5 colunas fica ilegível no celular — troca para
  // uma lista de cartões (ver docs/feature/20260901-usabilidade-mobile).
  const compact = useMediaQuery(theme.breakpoints.down('sm'));

  const toggleExpanded = (groupId: number) => {
    setExpandedGroupIds(prev => {
      const next = new Set(prev);
      if (next.has(groupId)) {
        next.delete(groupId);
      } else {
        next.add(groupId);
      }
      return next;
    });
  };

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

  const removeGroup = groups.find(group => group.id === removeGroupId) ?? null;

  const handleDeleteGroup = () => {
    if (removeGroupId === null) return;

    const token = localStorage.getItem('accessToken');
    axios
      .delete<{ message: string }>(`${API_BASE_URL}/api/groups/${removeGroupId}`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(res => {
        setGroups(prev => prev.filter(group => group.id !== removeGroupId));
        setRemoveGroupId(null);
        setRemoveSuccessMessage(res.data.message ?? 'Grupo excluído com sucesso.');
      })
      .catch(err => {
        console.error('Erro ao excluir grupo:', err);
        alert('Falha ao excluir grupo.');
      });
  };

  // Ações e toggle de pendências compartilhados entre a tabela (>= sm) e os
  // cartões (< sm), para os dois layouts nunca divergirem de comportamento.
  const renderExpandToggle = (group: Group, expanded: boolean) => (
    <IconButton
      size="small"
      onClick={() => toggleExpanded(group.id)}
      aria-label={expanded ? `Recolher pendências de ${group.name}` : `Ver pendências de ${group.name}`}
    >
      {expanded ? <ExpandLessIcon fontSize="small" /> : <ExpandMoreIcon fontSize="small" />}
    </IconButton>
  );

  const renderGroupActions = (group: Group) => (
    <>
      <IconButton onClick={() => navigate(`/groups/${group.id}/edit`)} aria-label="Editar grupo">
        <EditOutlinedIcon fontSize="small" />
      </IconButton>
      <IconButton onClick={() => navigate(`/groups/${group.id}/members`)} aria-label="Participantes">
        <PeopleOutlineOutlinedIcon fontSize="small" />
      </IconButton>
      <IconButton onClick={() => navigate(`/groups/${group.id}/expenses`)} aria-label="Despesas">
        <ReceiptLongOutlinedIcon fontSize="small" />
      </IconButton>
      <IconButton onClick={() => setRemoveGroupId(group.id)} aria-label="Excluir grupo">
        <DeleteOutlineIcon fontSize="small" />
      </IconButton>
    </>
  );

  const renderMembers = (group: Group) => (
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
  );

  const groupNameLink = (group: Group) => (
    <Typography
      component={Link}
      to={`/groups/${group.id}/summary`}
      color="primary"
      sx={{ textDecoration: 'none', fontWeight: 500 }}
    >
      {group.name}
    </Typography>
  );

  const renderGroupCards = () => (
    <Stack spacing={1.5}>
      {filteredGroups.map(group => {
        const expanded = expandedGroupIds.has(group.id);
        return (
          <Card key={group.id} variant="outlined">
            <CardContent sx={{ '&:last-child': { pb: 2 } }}>
              <Box display="flex" alignItems="center" justifyContent="space-between" gap={1}>
                {groupNameLink(group)}
                {renderExpandToggle(group, expanded)}
              </Box>
              <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
                Responsável: {group.creator?.email ?? '—'}
              </Typography>
              <Box display="flex" alignItems="center" gap={1} sx={{ mt: 1 }}>
                <Typography variant="body2" color="text.secondary">
                  Integrantes:
                </Typography>
                {renderMembers(group)}
              </Box>
              <Box display="flex" justifyContent="flex-end" flexWrap="wrap" sx={{ mt: 1 }}>
                {renderGroupActions(group)}
              </Box>
              <Collapse in={expanded} unmountOnExit>
                <Box sx={{ mt: 1 }}>
                  <GroupGrossDebtsPanel groupId={String(group.id)} />
                </Box>
              </Collapse>
            </CardContent>
          </Card>
        );
      })}
    </Stack>
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
      ) : compact ? (
        renderGroupCards()
      ) : (
        <TableContainer component={Paper} elevation={3} sx={{ borderRadius: 2, overflowX: 'auto' }}>
          <Table>
            <TableHead>
              <TableRow>
                <TableCell padding="checkbox" />
                <TableCell>Nome</TableCell>
                <TableCell>Responsável</TableCell>
                <TableCell>Integrantes</TableCell>
                <TableCell align="right">Ações</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {filteredGroups.map(group => {
                const expanded = expandedGroupIds.has(group.id);

                return (
                  <React.Fragment key={group.id}>
                    <TableRow hover>
                      <TableCell padding="checkbox">
                        {renderExpandToggle(group, expanded)}
                      </TableCell>
                      <TableCell>
                        {groupNameLink(group)}
                      </TableCell>
                      <TableCell>
                        <Typography variant="body2" color="text.secondary">
                          {group.creator?.email ?? '—'}
                        </Typography>
                      </TableCell>
                      <TableCell>
                        {renderMembers(group)}
                      </TableCell>
                      <TableCell align="right">
                        {renderGroupActions(group)}
                      </TableCell>
                    </TableRow>
                    <TableRow>
                      <TableCell colSpan={5} sx={{ py: 0, borderBottom: expanded ? undefined : 'none' }}>
                        <Collapse in={expanded} unmountOnExit>
                          <GroupGrossDebtsPanel groupId={String(group.id)} />
                        </Collapse>
                      </TableCell>
                    </TableRow>
                  </React.Fragment>
                );
              })}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      <Dialog
        open={removeGroupId !== null}
        onClose={() => setRemoveGroupId(null)}
        PaperProps={{ sx: { borderRadius: 2 } }}
      >
        <DialogTitle>Excluir grupo</DialogTitle>
        <DialogContent dividers>
          <Typography gutterBottom>
            {removeGroup
              ? `Tem certeza que deseja excluir o grupo "${removeGroup.name}"?`
              : 'Tem certeza que deseja excluir este grupo?'}
          </Typography>
          {removeGroup && (
            <Alert severity={removeGroup.cycle_snapshots_exists ? 'info' : 'warning'}>
              {removeGroup.cycle_snapshots_exists
                ? 'O histórico deste grupo (despesas, participantes e fechamentos) será preservado. Ele deixa de aparecer em "Meus Grupos" e não aceita mais novas despesas, participantes ou fechamentos.'
                : 'Esta ação é irreversível: o grupo e todas as despesas, participações e fechamentos associados serão apagados permanentemente.'}
            </Alert>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setRemoveGroupId(null)}>Cancelar</Button>
          <Button variant="contained" color="error" onClick={handleDeleteGroup}>
            Excluir
          </Button>
        </DialogActions>
      </Dialog>

      <Snackbar
        open={removeSuccessMessage !== null}
        autoHideDuration={4000}
        onClose={() => setRemoveSuccessMessage(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={() => setRemoveSuccessMessage(null)} severity="success" variant="filled">
          {removeSuccessMessage}
        </Alert>
      </Snackbar>
    </>
  );
};

export default Dashboard;
