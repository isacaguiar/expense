import React, { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  Box,
  Button,
  CircularProgress,
  Container,
  Grid,
  TextField,
  Typography,
  List,
  ListItem,
  ListItemText
} from '@mui/material';
import axios from 'axios';
import { API_BASE_URL } from '../config';

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

  if (loading) return <CircularProgress sx={{ mt: 4 }} />;

  return (
    <Container sx={{ mt: 4 }}>
      <Typography variant="h4" gutterBottom>
        Gerenciar Membros do Grupo
      </Typography>

      {error && <Typography color="error">{error}</Typography>}

      {group && (
        <Box sx={{ mb: 4 }}>
          <Typography variant="h6">{group.name}</Typography>
          <Typography variant="body1" color="text.secondary">
            {group.description}
          </Typography>
        </Box>
      )}

      <Grid container spacing={4}>
        
        <Grid size={{ xs: 12, md: 6 }}>
            <Typography variant="h6" gutterBottom>
                Membros atuais
            </Typography>
            <Box sx={{ overflowX: 'auto' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                    <tr>
                    <th style={{ borderBottom: '2px solid #ccc', textAlign: 'left', padding: '8px' }}>
                        <Typography variant="subtitle2">Email</Typography>
                    </th>
                    </tr>
                </thead>
                <tbody>
                    {members.map((user) => (
                    <tr key={user.id}>
                        <td style={{ borderBottom: '1px solid #eee', padding: '8px' }}>
                        <Typography variant="body2">{user.email}</Typography>
                        </td>
                    </tr>
                    ))}
                </tbody>
                </table>
            </Box>
            </Grid>


        <Grid size={{ xs: 12, md: 6 }}>
          <Typography variant="h6" gutterBottom>Adicionar novo membro</Typography>
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
        </Grid>
      </Grid>
    </Container>
  );
};

export default GroupMembersForm;
