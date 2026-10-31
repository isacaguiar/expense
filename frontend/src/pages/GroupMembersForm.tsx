import React, { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  Alert,
  Avatar,
  Box,
  Button,
  Card,
  CardContent,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle,
  Grid,
  List,
  ListItem,
  ListItemAvatar,
  ListItemText,
  TextField,
  Typography
} from '@mui/material';
import axios from 'axios';
import { API_BASE_URL } from '../config';
import { useCurrentUserId } from '../hooks/useCurrentUserId';
import { getInitials } from '../layouts/group/getInitials';
import { brandColors } from '../theme/brandColors';
import type { GroupDetail, GroupMember, MemberExitBlocker } from '../types/group';

/** Motivo (`MemberExitBlocker.reason`) em linguagem de tela. */
const BLOCKER_REASONS: Record<string, string> = {
  creditor_open_quotas: 'parcelas a receber em aberto',
  active_fixed: 'despesa fixa ativa'
};

type ExitErrorResponse = { status?: number; data?: { message?: string; blocking?: MemberExitBlocker[] } };

const GroupMembersForm: React.FC = () => {
  const { id } = useParams<{ id?: string }>();
  const navigate = useNavigate();

  const [group, setGroup] = useState<GroupDetail | null>(null);
  const [members, setMembers] = useState<GroupMember[]>([]);
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Sair do grupo / remover membro (item 086): `target` é quem a confirmação está tratando.
  const currentUserId = useCurrentUserId();
  const [target, setTarget] = useState<GroupMember | null>(null);
  const [leaving, setLeaving] = useState(false);
  const [exitError, setExitError] = useState<string | null>(null);
  const [blocking, setBlocking] = useState<MemberExitBlocker[]>([]);

  const isCreator = group?.created_by != null && group.created_by === currentUserId;
  const targetIsSelf = target !== null && target.id === currentUserId;

  const token = localStorage.getItem('accessToken');
  const config = {
    headers: { Authorization: token ? `Bearer ${token}` : '' }
  };

  useEffect(() => {
    if (!id) return;

    const fetchData = async () => {
      try {
        const [groupRes, membersRes] = await Promise.all([
          axios.get<GroupDetail>(`${API_BASE_URL}/api/groups/${id}`, config),
          axios.get<GroupMember[]>(`${API_BASE_URL}/api/groups/${id}/members`, config)
        ]);
        setGroup(groupRes.data);
        setMembers(membersRes.data);
      } catch {
        setError('Erro ao carregar informações do grupo.');
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, [id]);

  const handleAddMember = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email) return;

    setSubmitting(true);
    try {
      await axios.post(
        `${API_BASE_URL}/api/groups/${id}/members`,
        { email },
        config
      );
      setEmail('');
      const membersRes = await axios.get<GroupMember[]>(`${API_BASE_URL}/api/groups/${id}/members`, config);
      setMembers(membersRes.data);
    } catch {
      setError('Erro ao adicionar membro.');
    } finally {
      setSubmitting(false);
    }
  };

  const openConfirmation = (user: GroupMember) => {
    setExitError(null);
    setBlocking([]);
    setTarget(user);
  };

  const closeConfirmation = () => {
    if (!leaving) setTarget(null);
  };

  const handleConfirmExit = async () => {
    if (!target || leaving) return;

    const self = target.id === currentUserId;
    setLeaving(true);
    setExitError(null);
    setBlocking([]);

    try {
      await axios.delete(`${API_BASE_URL}/api/groups/${id}/members/${target.id}`, config);
    } catch (err) {
      const response = (err as { response?: ExitErrorResponse } | null)?.response;
      if (response?.status === 422 && response.data?.message) {
        // A API explica o que impede (D3-B) e lista as despesas: a tela só repassa.
        setExitError(response.data.message);
        setBlocking(Array.isArray(response.data.blocking) ? response.data.blocking : []);
      } else {
        setExitError(
          self
            ? 'Não foi possível sair do grupo agora. Tente novamente.'
            : 'Não foi possível remover o membro agora. Tente novamente.'
        );
      }
      setLeaving(false);
      return;
    }

    if (self) {
      navigate('/meus-grupos');
      return;
    }

    try {
      const membersRes = await axios.get<GroupMember[]>(`${API_BASE_URL}/api/groups/${id}/members`, config);
      setMembers(membersRes.data);
    } catch {
      setError('Membro removido, mas não foi possível atualizar a lista. Recarregue a página.');
    }
    setLeaving(false);
    setTarget(null);
  };

  if (loading) {
    return (
      <Box display="flex" justifyContent="center" mt={4}>
        <CircularProgress />
      </Box>
    );
  }

  return (
    <>
      {error && <Typography color="error" sx={{ mb: 2 }}>{error}</Typography>}

      {group && (
        <Box sx={{ mb: 3 }}>
          <Typography variant="h6">{group.name}</Typography>
          <Typography variant="body2" color="text.secondary">
            {group.description}
          </Typography>
          <Typography variant="body2" color="text.secondary">
            Responsável: {group.creator?.email ?? '—'}
          </Typography>
        </Box>
      )}

      <Grid container spacing={3}>
        <Grid size={{ xs: 12, md: 6 }}>
          <Card elevation={3} sx={{ borderRadius: 2 }}>
            <CardContent>
              <Typography variant="h6" gutterBottom>
                Membros atuais
              </Typography>
              {members.length === 0 ? (
                <Typography color="text.secondary">Nenhum membro cadastrado.</Typography>
              ) : (
                <List disablePadding>
                  {members.map(user => (
                    <ListItem key={user.id} divider disableGutters>
                      <ListItemAvatar>
                        <Avatar sx={{ bgcolor: brandColors.primaryLight, color: brandColors.primary, fontSize: '0.85rem' }}>
                          {getInitials(user.email)}
                        </Avatar>
                      </ListItemAvatar>
                      <ListItemText primary={user.email} sx={{ minWidth: 0, overflowWrap: 'anywhere' }} />
                      {user.id === currentUserId ? (
                        <Button size="small" color="error" onClick={() => openConfirmation(user)} sx={{ flexShrink: 0 }}>
                          Sair do grupo
                        </Button>
                      ) : isCreator ? (
                        <Button size="small" color="error" onClick={() => openConfirmation(user)} sx={{ flexShrink: 0 }}>
                          Remover
                        </Button>
                      ) : null}
                    </ListItem>
                  ))}
                </List>
              )}
            </CardContent>
          </Card>
        </Grid>

        <Grid size={{ xs: 12, md: 6 }}>
          <Card elevation={3} sx={{ borderRadius: 2 }}>
            <CardContent>
              <Typography variant="h6" gutterBottom>
                Adicionar novo membro
              </Typography>
              <Box component="form" onSubmit={handleAddMember} sx={{ mt: 2 }}>
                <TextField
                  label="E-mail do membro"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  fullWidth
                  required
                  type="email"
                />
                <Box sx={{ mt: 2, display: 'flex', gap: 2 }}>
                  <Button
                    type="submit"
                    variant="contained"
                    disabled={submitting}
                  >
                    Adicionar
                  </Button>
                  <Button
                    variant="outlined"
                    onClick={() => navigate('/meus-grupos')}
                  >
                    Voltar
                  </Button>
                </Box>
              </Box>
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Dialog open={target !== null} onClose={leaving ? undefined : closeConfirmation} fullWidth maxWidth="xs">
        <DialogTitle>{targetIsSelf ? 'Sair do grupo' : 'Remover membro'}</DialogTitle>
        <DialogContent>
          <DialogContentText>
            {targetIsSelf
              ? `Sair do grupo "${group?.name}" não apaga as suas despesas nem as suas dívidas neste grupo. Quem for credor continua vendo o que você deve.`
              : `Remover ${target?.email} do grupo "${group?.name}" não apaga as despesas nem as dívidas dessa pessoa neste grupo. Quem for credor continua vendo o que ela deve.`}
          </DialogContentText>
          {exitError && (
            <Alert severity="error" sx={{ mt: 2 }}>
              {exitError}
            </Alert>
          )}
          {blocking.length > 0 && (
            <List dense disablePadding sx={{ mt: 1 }}>
              {blocking.map(item => (
                <ListItem key={item.expense_id} disableGutters>
                  <ListItemText primary={item.description} secondary={BLOCKER_REASONS[item.reason]} />
                </ListItem>
              ))}
            </List>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={closeConfirmation} disabled={leaving}>
            Cancelar
          </Button>
          <Button onClick={handleConfirmExit} color="error" variant="contained" disabled={leaving}>
            {targetIsSelf ? 'Confirmar saída' : 'Confirmar remoção'}
          </Button>
        </DialogActions>
      </Dialog>
    </>
  );
};

export default GroupMembersForm;
