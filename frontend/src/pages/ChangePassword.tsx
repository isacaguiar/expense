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
import { API_BASE_URL } from '../config';

const ChangePassword: React.FC = () => {
  const [currentPassword, setCurrentPassword] = useState<string>('');
  const [newPassword, setNewPassword] = useState<string>('');
  const [confirmPassword, setConfirmPassword] = useState<string>('');
  const [submitting, setSubmitting] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<boolean>(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    const token = localStorage.getItem('accessToken');
    try {
      await axios.put(
        `${API_BASE_URL}/api/user/password`,
        {
          current_password: currentPassword,
          new_password: newPassword,
          new_password_confirmation: confirmPassword
        },
        { headers: { Authorization: token ? `Bearer ${token}` : '' } }
      );
      setSuccess(true);
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
    } catch (err) {
      const message = (err as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors;
      setError(message ? Object.values(message).flat().join(' ') : 'Erro ao trocar a senha. Tente novamente.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <>
      <Card elevation={3} sx={{ borderRadius: 2, maxWidth: 520, mx: 'auto' }}>
        <CardContent sx={{ p: 4 }}>
          {error && <Typography color="error" sx={{ mb: 2 }}>{error}</Typography>}

          <Box component="form" onSubmit={handleSubmit}>
            <TextField
              label="Senha atual"
              type="password"
              value={currentPassword}
              onChange={e => setCurrentPassword(e.target.value)}
              fullWidth
              margin="normal"
              required
            />
            <TextField
              label="Nova senha"
              type="password"
              value={newPassword}
              onChange={e => setNewPassword(e.target.value)}
              fullWidth
              margin="normal"
              required
              helperText="Mínimo de 8 caracteres."
            />
            <TextField
              label="Confirmar nova senha"
              type="password"
              value={confirmPassword}
              onChange={e => setConfirmPassword(e.target.value)}
              fullWidth
              margin="normal"
              required
            />
            <Box sx={{ mt: 3, display: 'flex', gap: 2 }}>
              <Button type="submit" variant="contained" color="primary" disabled={submitting}>
                Alterar senha
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
          Senha atualizada com sucesso.
        </Alert>
      </Snackbar>
    </>
  );
};

export default ChangePassword;
