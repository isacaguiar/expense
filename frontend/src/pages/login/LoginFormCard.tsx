import React, { useState } from 'react';
import Avatar from '@mui/material/Avatar';
import Button from '@mui/material/Button';
import TextField from '@mui/material/TextField';
import FormControlLabel from '@mui/material/FormControlLabel';
import Checkbox from '@mui/material/Checkbox';
import Box from '@mui/material/Box';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import LockOutlinedIcon from '@mui/icons-material/LockOutlined';
import EmailOutlinedIcon from '@mui/icons-material/EmailOutlined';
import VisibilityIcon from '@mui/icons-material/Visibility';
import VisibilityOffIcon from '@mui/icons-material/VisibilityOff';
import Typography from '@mui/material/Typography';
import Link from '@mui/material/Link';

interface LoginFormCardProps {
  email: string;
  password: string;
  onEmailChange: (value: string) => void;
  onPasswordChange: (value: string) => void;
  onSubmit: (event: React.FormEvent<HTMLFormElement>) => void;
}

export default function LoginFormCard({
  email,
  password,
  onEmailChange,
  onPasswordChange,
  onSubmit,
}: LoginFormCardProps) {
  const [showPassword, setShowPassword] = useState(false);

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
      <Avatar sx={{ width: 56, height: 56, mb: 2, bgcolor: 'secondary.light' }}>
        <LockOutlinedIcon color="secondary" />
      </Avatar>
      <Typography component="h1" variant="h5" fontWeight="bold" textAlign="center">
        Bem-vindo de volta!
      </Typography>
      <Typography variant="body2" color="text.secondary" textAlign="center" sx={{ mt: 0.5 }}>
        Faça login para acessar sua conta
      </Typography>

      <Box component="form" onSubmit={onSubmit} noValidate sx={{ mt: 3, width: '100%' }}>
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
        <TextField
          margin="normal"
          required
          fullWidth
          name="password"
          label="Senha"
          placeholder="Sua senha"
          type={showPassword ? 'text' : 'password'}
          id="password"
          autoComplete="current-password"
          value={password}
          onChange={(e) => onPasswordChange(e.target.value)}
          slotProps={{
            input: {
              startAdornment: (
                <InputAdornment position="start">
                  <LockOutlinedIcon fontSize="small" color="action" />
                </InputAdornment>
              ),
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

        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mt: 1 }}>
          <FormControlLabel
            control={<Checkbox value="remember" color="primary" defaultChecked />}
            label="Lembrar de mim"
          />
          <Link href="#" variant="body2" underline="hover">
            Esqueci minha senha
          </Link>
        </Box>

        <Button type="submit" fullWidth variant="contained" size="large" sx={{ mt: 2, mb: 1 }}>
          Entrar
        </Button>
      </Box>
    </Box>
  );
}
