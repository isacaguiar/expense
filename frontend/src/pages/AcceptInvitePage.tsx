import React, { useState } from 'react';
import axios from 'axios';
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Snackbar,
  TextField,
  Typography
} from '@mui/material';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { API_BASE_URL } from '../config';

const AcceptInvitePage: React.FC = () => {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();

  const email = searchParams.get('email') ?? '';
  const token = searchParams.get('token') ?? '';
  const missingParams = !email || !token;

  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      await axios.post(`${API_BASE_URL}/api/invitations/verify`, {
        email,
        token,
        password,
        password_confirmation: confirmPassword
      });
      setSuccess(true);
      setTimeout(() => navigate('/'), 2000);
    } catch (err) {
      const response = (err as {
        response?: { data?: { message?: string; errors?: Record<string, string[]> } };
      })?.response;

      if (response?.data?.errors) {
        setError(Object.values(response.data.errors).flat().join(' '));
      } else if (response?.data?.message) {
        setError(response.data.message);
      } else {
        setError('Não foi possível confirmar o convite. Tente novamente.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Box
      sx={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        p: 3
      }}
    >
      <Card elevation={3} sx={{ borderRadius: 2, maxWidth: 480, width: '100%' }}>
        <CardContent sx={{ p: 4 }}>
          <Typography variant="h6" gutterBottom>
            Criar senha de acesso
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>
            {email
              ? `Defina uma senha para ativar sua conta (${email}).`
              : 'Defina uma senha para ativar sua conta.'}
          </Typography>

          {missingParams && (
            <Alert severity="error" sx={{ mb: 2 }}>
              Link de convite inválido — faltam informações. Solicite um novo convite.
            </Alert>
          )}

          {error && <Typography color="error" sx={{ mb: 2 }}>{error}</Typography>}

          <Box component="form" onSubmit={handleSubmit}>
            <TextField
              label="Nova senha"
              type="password"
              value={password}
              onChange={e => setPassword(e.target.value)}
              fullWidth
              margin="normal"
              required
              disabled={missingParams}
              helperText="Mínimo de 6 caracteres."
            />
            <TextField
              label="Confirmar senha"
              type="password"
              value={confirmPassword}
              onChange={e => setConfirmPassword(e.target.value)}
              fullWidth
              margin="normal"
              required
              disabled={missingParams}
            />
            <Box sx={{ mt: 3 }}>
              <Button
                type="submit"
                variant="contained"
                color="primary"
                fullWidth
                disabled={submitting || missingParams}
              >
                Ativar conta
              </Button>
            </Box>
          </Box>
        </CardContent>
      </Card>

      <Snackbar
        open={success}
        autoHideDuration={2000}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert severity="success" variant="filled">
          Senha definida com sucesso! Redirecionando para o login...
        </Alert>
      </Snackbar>
    </Box>
  );
};

export default AcceptInvitePage;
