import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  CircularProgress,
  Snackbar,
  TextField,
  Typography
} from '@mui/material';
import { API_BASE_URL } from '../config';

const Profile: React.FC = () => {
  const navigate = useNavigate();

  const [name, setName] = useState<string>('');
  const [email, setEmail] = useState<string>('');
  const [pix, setPix] = useState<string>('');
  const [loading, setLoading] = useState<boolean>(true);
  const [submitting, setSubmitting] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<boolean>(false);

  useEffect(() => {
    const token = localStorage.getItem('accessToken');
    axios
      .get<{ name: string; email: string; pix: string | null }>(`${API_BASE_URL}/api/me`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(res => {
        setName(res.data.name);
        setEmail(res.data.email);
        setPix(res.data.pix ?? '');
      })
      .catch(err => {
        console.error('Erro ao carregar perfil:', err);
        if (err.response?.status === 401) {
          navigate('/', { replace: true });
          return;
        }
        setError('Falha ao carregar seus dados.');
      })
      .finally(() => setLoading(false));
  }, [navigate]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    const token = localStorage.getItem('accessToken');
    try {
      await axios.put(
        `${API_BASE_URL}/api/user/profile`,
        { name, email, pix: pix || null },
        { headers: { Authorization: token ? `Bearer ${token}` : '' } }
      );
      setSuccess(true);
    } catch (err) {
      const message = (err as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors;
      setError(message ? Object.values(message).flat().join(' ') : 'Erro ao salvar perfil. Tente novamente.');
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
      <Card elevation={3} sx={{ borderRadius: 2, maxWidth: 520, mx: 'auto' }}>
        <CardContent sx={{ p: 4 }}>
          {error && <Typography color="error" sx={{ mb: 2 }}>{error}</Typography>}

          <Box component="form" onSubmit={handleSubmit}>
            <TextField
              label="Nome"
              value={name}
              onChange={e => setName(e.target.value)}
              fullWidth
              margin="normal"
              required
            />
            <TextField
              label="E-mail"
              type="email"
              value={email}
              onChange={e => setEmail(e.target.value)}
              fullWidth
              margin="normal"
              required
            />
            <TextField
              label="Chave Pix (opcional)"
              value={pix}
              onChange={e => setPix(e.target.value)}
              fullWidth
              margin="normal"
            />
            <Box sx={{ mt: 3, display: 'flex', gap: 2 }}>
              <Button type="submit" variant="contained" color="primary" disabled={submitting}>
                Salvar
              </Button>
            </Box>
          </Box>
        </CardContent>
      </Card>

      <Snackbar
        open={success}
        autoHideDuration={4000}
        onClose={() => setSuccess(false)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={() => setSuccess(false)} severity="success" variant="filled">
          Perfil atualizado com sucesso.
        </Alert>
      </Snackbar>
    </>
  );
};

export default Profile;
