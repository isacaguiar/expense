import React, { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  Avatar,
  Box,
  Button,
  Card,
  CardContent,
  CircularProgress,
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
import { getInitials } from '../layouts/group/getInitials';
import { brandColors } from '../theme/brandColors';

type User = { id: number; email: string };
type Group = { id: number; name: string; description: string };

const GroupMembersForm: React.FC = () => {
  const { id } = useParams<{ id?: string }>();
  const navigate = useNavigate();

  const [group, setGroup] = useState<Group | null>(null);
  const [members, setMembers] = useState<User[]>([]);
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const token = localStorage.getItem('accessToken');
  const config = {
    headers: { Authorization: token ? `Bearer ${token}` : '' }
  };

  useEffect(() => {
    if (!id) return;

    const fetchData = async () => {
      try {
        const [groupRes, membersRes] = await Promise.all([
          axios.get<Group>(`${API_BASE_URL}/api/groups/${id}`, config),
          axios.get<User[]>(`${API_BASE_URL}/api/groups/${id}/members`, config)
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
      const membersRes = await axios.get<User[]>(`${API_BASE_URL}/api/groups/${id}/members`, config);
      setMembers(membersRes.data);
    } catch {
      setError('Erro ao adicionar membro.');
    } finally {
      setSubmitting(false);
    }
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
                      <ListItemText primary={user.email} />
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
                    onClick={() => navigate('/dashboard')}
                  >
                    Voltar
                  </Button>
                </Box>
              </Box>
            </CardContent>
          </Card>
        </Grid>
      </Grid>
    </>
  );
};

export default GroupMembersForm;
