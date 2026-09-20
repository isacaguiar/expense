import React from 'react';
import Alert from '@mui/material/Alert';
import Avatar from '@mui/material/Avatar';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Link from '@mui/material/Link';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import MarkEmailReadOutlinedIcon from '@mui/icons-material/MarkEmailReadOutlined';
import { brandColors } from '../../theme/brandColors';

interface ConfirmCodeCardProps {
  /** E-mail para onde o código foi enviado — mostrado para a pessoa conferir. */
  email: string;
  code: string;
  error?: string | null;
  notice?: string | null;
  submitting: boolean;
  resending: boolean;
  /** Segundos que faltam para liberar o reenvio. 0 = liberado. */
  resendCountdown: number;
  onCodeChange: (value: string) => void;
  onSubmit: (event: React.FormEvent<HTMLFormElement>) => void;
  onResend: () => void;
  onBack: () => void;
}

export default function ConfirmCodeCard({
  email,
  code,
  error,
  notice,
  submitting,
  resending,
  resendCountdown,
  onCodeChange,
  onSubmit,
  onResend,
  onBack,
}: ConfirmCodeCardProps) {
  return (
    <Box
      sx={{
        width: '100%',
        maxWidth: 440,
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
        <MarkEmailReadOutlinedIcon sx={{ color: brandColors.primary }} />
      </Avatar>
      <Typography component="h1" sx={{ fontSize: '1.375rem', fontWeight: 'bold' }} textAlign="center">
        Confirme seu e-mail
      </Typography>
      <Typography sx={{ fontSize: '0.85rem', color: 'text.secondary', mt: 0.5 }} textAlign="center">
        Enviamos um código de 6 dígitos para <strong>{email}</strong>. Ele vale por 15 minutos.
      </Typography>

      <Box component="form" onSubmit={onSubmit} noValidate sx={{ mt: 3, width: '100%' }}>
        {error && (
          <Alert severity="error" sx={{ mb: 1, fontSize: '0.8rem' }}>
            {error}
          </Alert>
        )}
        {notice && !error && (
          <Alert severity="success" sx={{ mb: 1, fontSize: '0.8rem' }}>
            {notice}
          </Alert>
        )}

        <TextField
          margin="normal"
          required
          fullWidth
          id="code"
          name="code"
          label="Código de confirmação"
          placeholder="000000"
          autoComplete="one-time-code"
          inputMode="numeric"
          autoFocus
          value={code}
          onChange={(e) => onCodeChange(e.target.value)}
          error={Boolean(error)}
          sx={{
            '& .MuiOutlinedInput-input': {
              textAlign: 'center',
              fontSize: '1.5rem',
              letterSpacing: '0.6rem',
              fontWeight: 'bold',
            },
          }}
        />

        <Button
          type="submit"
          fullWidth
          variant="contained"
          size="large"
          disabled={submitting || code.length < 6}
          sx={{
            mt: 2,
            mb: 1,
            fontSize: '0.8rem',
            bgcolor: brandColors.primary,
            '&:hover': { bgcolor: brandColors.primaryDark },
          }}
        >
          {submitting ? 'Confirmando...' : 'Confirmar e entrar'}
        </Button>

        <Button
          fullWidth
          variant="text"
          onClick={onResend}
          disabled={resending || resendCountdown > 0}
          sx={{ fontSize: '0.8rem', color: brandColors.primary }}
        >
          {resendCountdown > 0 ? `Reenviar código em ${resendCountdown}s` : 'Reenviar código'}
        </Button>
      </Box>

      <Typography sx={{ fontSize: '0.8rem', color: 'text.secondary', mt: 2 }}>
        Digitou o e-mail errado?{' '}
        <Link
          component="button"
          type="button"
          onClick={onBack}
          underline="hover"
          sx={{ color: brandColors.primary, fontSize: '0.8rem' }}
        >
          Voltar e corrigir
        </Link>
      </Typography>
    </Box>
  );
}
