import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { useNavigate, useSearchParams } from 'react-router-dom';
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Checkbox,
  CircularProgress,
  FormControlLabel,
  Snackbar,
  TextField,
  Typography
} from '@mui/material';
import { API_BASE_URL } from '../config';

const formatWhatsapp = (value: string): string => {
  const digits = value.replace(/\D/g, '').slice(0, 11);
  const ddd = digits.slice(0, 2);
  const firstPart = digits.slice(2, 7);
  const lastPart = digits.slice(7, 11);

  if (digits.length === 0) return '';
  if (digits.length <= 2) return `(${ddd}`;
  if (digits.length <= 7) return `(${ddd}) ${firstPart}`;
  return `(${ddd}) ${firstPart}-${lastPart}`;
};

const Profile: React.FC = () => {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();

  const [name, setName] = useState<string>('');
  const [email, setEmail] = useState<string>('');
  const [pix, setPix] = useState<string>('');
  const [whatsapp, setWhatsapp] = useState<string>('');
  const [notifyWhatsapp, setNotifyWhatsapp] = useState<boolean>(false);
  const [loading, setLoading] = useState<boolean>(true);
  const [submitting, setSubmitting] = useState<boolean>(false);
  const [linking, setLinking] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<boolean>(false);
  const [linkedOpen, setLinkedOpen] = useState<boolean>(false);
  const [linkedSeverity, setLinkedSeverity] = useState<'success' | 'error'>('success');
  const [linkedText, setLinkedText] = useState<string>('');

  useEffect(() => {
    const token = localStorage.getItem('accessToken');
    axios
      .get<{
        name: string;
        email: string;
        pix: string | null;
        whatsapp: string | null;
        notify_whatsapp: boolean;
      }>(`${API_BASE_URL}/api/me`, { headers: { Authorization: token ? `Bearer ${token}` : '' } })
      .then(res => {
        setName(res.data.name);
        setEmail(res.data.email);
        setPix(res.data.pix ?? '');
        setWhatsapp(res.data.whatsapp ?? '');
        setNotifyWhatsapp(res.data.notify_whatsapp ?? false);
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

  useEffect(() => {
    const linked = searchParams.get('linked');
    if (linked === 'success') {
      setLinkedSeverity('success');
      setLinkedText('Conta Google vinculada com sucesso.');
      setLinkedOpen(true);
      setSearchParams({}, { replace: true });
    } else if (linked === 'error') {
      setLinkedSeverity('error');
      setLinkedText('Não foi possível vincular sua conta Google.');
      setLinkedOpen(true);
      setSearchParams({}, { replace: true });
    }
  }, [searchParams, setSearchParams]);

  const handleLinkGoogle = async () => {
    setLinking(true);
    const token = localStorage.getItem('accessToken');
    try {
      const res = await axios.get<{ url: string }>(`${API_BASE_URL}/api/user/google/redirect-url`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      });
      window.location.href = res.data.url;
    } catch (err) {
      console.error('Erro ao iniciar vínculo com o Google:', err);
      setLinkedSeverity('error');
      setLinkedText('Não foi possível iniciar o vínculo com o Google.');
      setLinkedOpen(true);
      setLinking(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    const token = localStorage.getItem('accessToken');
    try {
      await axios.put(
        `${API_BASE_URL}/api/user/profile`,
        { name, email, pix: pix || null, whatsapp: whatsapp || null, notify_whatsapp: notifyWhatsapp },
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
            <TextField
              label="WhatsApp (opcional)"
              placeholder="(71) 99999-9999"
              value={whatsapp}
              onChange={e => setWhatsapp(formatWhatsapp(e.target.value))}
              fullWidth
              margin="normal"
            />
            <FormControlLabel
              control={
                <Checkbox
                  checked={notifyWhatsapp}
                  onChange={e => setNotifyWhatsapp(e.target.checked)}
                />
              }
              label="Receber notificações pelo WhatsApp"
            />
            <Box sx={{ mt: 3, display: 'flex', gap: 2 }}>
              <Button type="submit" variant="contained" color="primary" disabled={submitting}>
                Salvar
              </Button>
              <Button variant="outlined" onClick={handleLinkGoogle} disabled={linking}>
                Vincular conta Google
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

      <Snackbar
        open={linkedOpen}
        autoHideDuration={4000}
        onClose={() => setLinkedOpen(false)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={() => setLinkedOpen(false)} severity={linkedSeverity} variant="filled">
          {linkedText}
        </Alert>
      </Snackbar>
    </>
  );
};

export default Profile;
