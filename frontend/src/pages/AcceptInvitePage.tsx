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

type AcceptInvitePageProps = {
  /**
   * `invite` (default): convite de grupo, aceito via /aceitar-convite.
   * `reset`: recuperação de senha, aceita via /recuperar-senha. Mesma tela e
   * mesmo endpoint (POST /api/invitations/verify, que já aceita os dois tipos
   * de token) — só a copy muda, para não confundir quem só quer redefinir a
   * senha com linguagem de "convite".
   */
  mode?: 'invite' | 'reset';
};

const AcceptInvitePage: React.FC<AcceptInvitePageProps> = ({ mode = 'invite' }) => {
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

  const isReset = mode === 'reset';

  const copy = isReset
    ? {
        title: 'Redefinir senha',
        subtitle: email
          ? `Defina uma nova senha de acesso (${email}).`
          : 'Defina uma nova senha de acesso.',
        missingParamsAlert:
          'Link de recuperação inválido — faltam informações. Solicite uma nova recuperação de senha.',
        submitLabel: 'Redefinir senha',
        genericError: 'Não foi possível redefinir a senha. Tente novamente.',
        successMessage: 'Senha redefinida com sucesso! Redirecionando para o login...'
      }
    : {
        title: 'Criar senha de acesso',
        subtitle: email
          ? `Defina uma senha para ativar sua conta (${email}).`
          : 'Defina uma senha para ativar sua conta.',
        missingParamsAlert: 'Link de convite inválido — faltam informações. Solicite um novo convite.',
        submitLabel: 'Ativar conta',
        genericError: 'Não foi possível confirmar o convite. Tente novamente.',
        successMessage: 'Senha definida com sucesso! Redirecionando para o login...'
      };

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
        setError(copy.genericError);
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
            {copy.title}
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>
            {copy.subtitle}
          </Typography>

          {missingParams && (
            <Alert severity="error" sx={{ mb: 2 }}>
              {copy.missingParamsAlert}
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
                {copy.submitLabel}
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
          {copy.successMessage}
        </Alert>
      </Snackbar>
    </Box>
  );
};

export default AcceptInvitePage;
