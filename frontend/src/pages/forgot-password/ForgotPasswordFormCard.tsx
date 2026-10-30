import React from 'react';
import Alert from '@mui/material/Alert';
import Avatar from '@mui/material/Avatar';
import Button from '@mui/material/Button';
import TextField from '@mui/material/TextField';
import Box from '@mui/material/Box';
import InputAdornment from '@mui/material/InputAdornment';
import LockResetOutlinedIcon from '@mui/icons-material/LockResetOutlined';
import EmailOutlinedIcon from '@mui/icons-material/EmailOutlined';
import Typography from '@mui/material/Typography';
import Link from '@mui/material/Link';
import { Link as RouterLink } from 'react-router-dom';
import { brandColors } from '../../theme/brandColors';

interface ForgotPasswordFormCardProps {
  email: string;
  submitting: boolean;
  error?: string | null;
  success?: boolean;
  onEmailChange: (value: string) => void;
  onSubmit: (event: React.FormEvent<HTMLFormElement>) => void;
}

export default function ForgotPasswordFormCard({
  email,
  submitting,
  error,
  success,
  onEmailChange,
  onSubmit,
}: ForgotPasswordFormCardProps) {
  return (
    <Box
      sx={{
        width: '100%',
        maxWidth: 400,
        bgcolor: 'background.paper',
        borderRadius: 3,
        boxShadow: 3,
        p: 4,
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
      }}
    >
      <Avatar sx={{ width: 48, height: 48, mb: 1.5, bgcolor: brandColors.primaryLight }}>
        <LockResetOutlinedIcon sx={{ color: brandColors.primary }} />
      </Avatar>
      <Typography component="h1" sx={{ fontSize: '1.375rem', fontWeight: 'bold' }} textAlign="center">
        Esqueci minha senha
      </Typography>
      <Typography sx={{ fontSize: '0.85rem', color: 'text.secondary', mt: 0.5 }} textAlign="center">
        Informe seu e-mail para receber um link de recuperação
      </Typography>

      <Box component="form" onSubmit={onSubmit} noValidate sx={{ mt: 3, width: '100%' }}>
        {error && (
          <Alert severity="error" sx={{ mb: 1, fontSize: '0.8rem' }}>
            {error}
          </Alert>
        )}
        {success && (
          <Alert severity="success" sx={{ mb: 1, fontSize: '0.8rem' }}>
            Se esse e-mail estiver cadastrado, você vai receber um link de recuperação em instantes.
            Confira também a caixa de spam.
          </Alert>
        )}
        <TextField
          margin="normal"
          required
          fullWidth
          id="email"
          label="E-mail"
          name="email"
          placeholder="seu@email.com"
          autoComplete="email"
          autoFocus
          value={email}
          onChange={(e) => onEmailChange(e.target.value)}
          sx={{
            '& .MuiOutlinedInput-root': { height: 46, fontSize: '0.875rem' },
            '& .MuiOutlinedInput-input': { padding: '10px 12px' },
            '& .MuiInputLabel-root': { fontSize: '0.875rem' },
          }}
          slotProps={{
            input: {
              startAdornment: (
                <InputAdornment position="start">
                  <EmailOutlinedIcon fontSize="small" color="action" />
                </InputAdornment>
              ),
            },
          }}
        />

        <Button
          type="submit"
          fullWidth
          variant="contained"
          size="large"
          disabled={submitting}
          sx={{
            mt: 2,
            mb: 1,
            fontSize: '0.8rem',
            bgcolor: brandColors.primary,
            '&:hover': { bgcolor: brandColors.primaryDark },
          }}
        >
          {submitting ? 'Enviando...' : success ? 'Reenviar link' : 'Enviar link de recuperação'}
        </Button>
      </Box>

      <Typography sx={{ fontSize: '0.8rem', color: 'text.secondary', mt: 3 }}>
        Lembrou a senha?{' '}
        <Link component={RouterLink} to="/" underline="hover" sx={{ color: brandColors.primary }}>
          Voltar para o login
        </Link>
      </Typography>
    </Box>
  );
}
