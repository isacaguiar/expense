import React, { useState } from 'react';
import Alert from '@mui/material/Alert';
import Avatar from '@mui/material/Avatar';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import Link from '@mui/material/Link';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { Link as RouterLink } from 'react-router-dom';
import EmailOutlinedIcon from '@mui/icons-material/EmailOutlined';
import LockOutlinedIcon from '@mui/icons-material/LockOutlined';
import PersonAddAltOutlinedIcon from '@mui/icons-material/PersonAddAltOutlined';
import PersonOutlineIcon from '@mui/icons-material/PersonOutline';
import PhoneIphoneOutlinedIcon from '@mui/icons-material/PhoneIphoneOutlined';
import VisibilityIcon from '@mui/icons-material/Visibility';
import VisibilityOffIcon from '@mui/icons-material/VisibilityOff';
import { brandColors } from '../../theme/brandColors';

export type RegisterFormValues = {
  name: string;
  email: string;
  emailConfirmation: string;
  whatsapp: string;
  password: string;
  passwordConfirmation: string;
};

export type RegisterFormErrors = Partial<Record<keyof RegisterFormValues, string>>;

interface RegisterFormCardProps {
  values: RegisterFormValues;
  errors: RegisterFormErrors;
  generalError?: string | null;
  submitting: boolean;
  onChange: (field: keyof RegisterFormValues, value: string) => void;
  onSubmit: (event: React.FormEvent<HTMLFormElement>) => void;
}

const fieldSx = {
  '& .MuiOutlinedInput-root': { height: 46, fontSize: '0.875rem' },
  '& .MuiOutlinedInput-input': { padding: '10px 12px' },
  '& .MuiInputLabel-root': { fontSize: '0.875rem' },
};

export default function RegisterFormCard({
  values,
  errors,
  generalError,
  submitting,
  onChange,
  onSubmit,
}: RegisterFormCardProps) {
  const [showPassword, setShowPassword] = useState(false);

  const adornment = (Icon: typeof EmailOutlinedIcon) => ({
    startAdornment: (
      <InputAdornment position="start">
        <Icon fontSize="small" color="action" />
      </InputAdornment>
    ),
  });

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
        <PersonAddAltOutlinedIcon sx={{ color: brandColors.primary }} />
      </Avatar>
      <Typography component="h1" sx={{ fontSize: '1.375rem', fontWeight: 'bold' }} textAlign="center">
        Crie sua conta
      </Typography>
      <Typography sx={{ fontSize: '0.85rem', color: 'text.secondary', mt: 0.5 }} textAlign="center">
        Leva menos de um minuto. Confirmamos seu e-mail por um código.
      </Typography>

      <Box component="form" onSubmit={onSubmit} noValidate sx={{ mt: 3, width: '100%' }}>
        {generalError && (
          <Alert severity="error" sx={{ mb: 1, fontSize: '0.8rem' }}>
            {generalError}
          </Alert>
        )}

        <TextField
          margin="normal"
          required
          fullWidth
          id="name"
          name="name"
          label="Nome"
          placeholder="Seu nome completo"
          autoComplete="name"
          autoFocus
          value={values.name}
          onChange={(e) => onChange('name', e.target.value)}
          error={Boolean(errors.name)}
          helperText={errors.name}
          sx={fieldSx}
          slotProps={{ input: adornment(PersonOutlineIcon) }}
        />

        <TextField
          margin="normal"
          required
          fullWidth
          id="email"
          name="email"
          label="E-mail"
          placeholder="seu@email.com"
          autoComplete="email"
          value={values.email}
          onChange={(e) => onChange('email', e.target.value)}
          error={Boolean(errors.email)}
          helperText={errors.email}
          sx={fieldSx}
          slotProps={{ input: adornment(EmailOutlinedIcon) }}
        />

        <TextField
          margin="normal"
          required
          fullWidth
          id="emailConfirmation"
          name="emailConfirmation"
          label="Confirmar e-mail"
          placeholder="Repita seu e-mail"
          value={values.emailConfirmation}
          onChange={(e) => onChange('emailConfirmation', e.target.value)}
          error={Boolean(errors.emailConfirmation)}
          helperText={errors.emailConfirmation}
          sx={fieldSx}
          slotProps={{ input: adornment(EmailOutlinedIcon) }}
        />

        <TextField
          margin="normal"
          fullWidth
          id="whatsapp"
          name="whatsapp"
          label="Telefone (opcional)"
          placeholder="(11) 91234-5678"
          autoComplete="tel-national"
          value={values.whatsapp}
          onChange={(e) => onChange('whatsapp', e.target.value)}
          error={Boolean(errors.whatsapp)}
          helperText={errors.whatsapp ?? 'Celular com DDD. Usado para avisos de pagamento.'}
          sx={fieldSx}
          slotProps={{ input: adornment(PhoneIphoneOutlinedIcon) }}
        />

        <TextField
          margin="normal"
          required
          fullWidth
          id="password"
          name="password"
          label="Senha"
          placeholder="Mínimo de 6 caracteres"
          type={showPassword ? 'text' : 'password'}
          autoComplete="new-password"
          value={values.password}
          onChange={(e) => onChange('password', e.target.value)}
          error={Boolean(errors.password)}
          helperText={errors.password}
          sx={fieldSx}
          slotProps={{
            input: {
              ...adornment(LockOutlinedIcon),
              endAdornment: (
                <InputAdornment position="end">
                  <IconButton
                    aria-label={showPassword ? 'Ocultar senha' : 'Mostrar senha'}
                    onClick={() => setShowPassword((show) => !show)}
                    edge="end"
                    size="small"
                  >
                    {showPassword ? <VisibilityOffIcon fontSize="small" /> : <VisibilityIcon fontSize="small" />}
                  </IconButton>
                </InputAdornment>
              ),
            },
          }}
        />

        <TextField
          margin="normal"
          required
          fullWidth
          id="passwordConfirmation"
          name="passwordConfirmation"
          label="Confirmar senha"
          placeholder="Repita sua senha"
          type={showPassword ? 'text' : 'password'}
          autoComplete="new-password"
          value={values.passwordConfirmation}
          onChange={(e) => onChange('passwordConfirmation', e.target.value)}
          error={Boolean(errors.passwordConfirmation)}
          helperText={errors.passwordConfirmation}
          sx={fieldSx}
          slotProps={{ input: adornment(LockOutlinedIcon) }}
        />

        <Button
          type="submit"
          fullWidth
          variant="contained"
          size="large"
          disabled={submitting}
          sx={{
            mt: 3,
            mb: 1,
            fontSize: '0.8rem',
            bgcolor: brandColors.primary,
            '&:hover': { bgcolor: brandColors.primaryDark },
          }}
        >
          {submitting ? 'Enviando código...' : 'Criar conta'}
        </Button>
      </Box>

      <Typography sx={{ fontSize: '0.8rem', color: 'text.secondary', mt: 2 }}>
        Já tem uma conta?{' '}
        <Link component={RouterLink} to="/" underline="hover" sx={{ color: brandColors.primary }}>
          Entrar
        </Link>
      </Typography>
    </Box>
  );
}
