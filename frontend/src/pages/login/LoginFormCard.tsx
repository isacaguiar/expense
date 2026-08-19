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
import Divider from '@mui/material/Divider';
import googleLogo from '../../assets/illustrations/google-logo.svg';
import microsoftLogo from '../../assets/illustrations/microsoft-logo.svg';
import { loginColors } from './colors';

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
      <Avatar sx={{ width: 48, height: 48, mb: 1.5, bgcolor: loginColors.primaryLight }}>
        <LockOutlinedIcon sx={{ color: loginColors.primary }} />
      </Avatar>
      <Typography component="h1" sx={{ fontSize: '1.375rem', fontWeight: 'bold' }} textAlign="center">
        Bem-vindo de volta!
      </Typography>
      <Typography sx={{ fontSize: '0.85rem', color: 'text.secondary', mt: 0.5 }} textAlign="center">
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
          sx={{ '& .MuiOutlinedInput-root': { height: 46 } }}
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
          sx={{ '& .MuiOutlinedInput-root': { height: 46 } }}
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
            control={
              <Checkbox
                value="remember"
                defaultChecked
                sx={{ color: loginColors.primary, '&.Mui-checked': { color: loginColors.primary } }}
              />
            }
            label="Lembrar de mim"
          />
          <Link href="#" variant="body2" underline="hover" sx={{ color: loginColors.primary }}>
            Esqueci minha senha
          </Link>
        </Box>

        <Button
          type="submit"
          fullWidth
          variant="contained"
          size="large"
          sx={{
            mt: 2,
            mb: 1,
            bgcolor: loginColors.primary,
            '&:hover': { bgcolor: loginColors.primaryDark },
          }}
        >
          Entrar
        </Button>

        <Divider sx={{ my: 2 }}>
          <Typography variant="caption" color="text.secondary">
            ou continue com
          </Typography>
        </Divider>

        <Box sx={{ display: 'flex', gap: 2 }}>
          <Button
            href="#"
            variant="outlined"
            fullWidth
            startIcon={<img src={googleLogo} alt="" width={18} height={18} />}
          >
            Google
          </Button>
          <Button
            href="#"
            variant="outlined"
            fullWidth
            startIcon={<img src={microsoftLogo} alt="" width={18} height={18} />}
          >
            Microsoft
          </Button>
        </Box>
      </Box>

      <Typography variant="body2" color="text.secondary" sx={{ mt: 3 }}>
        Ainda não tem uma conta?{' '}
        <Link href="#" underline="hover" sx={{ color: loginColors.primary }}>
          Cadastre-se
        </Link>
      </Typography>
    </Box>
  );
}
