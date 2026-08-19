import React, { useState } from 'react';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import LanguageOutlinedIcon from '@mui/icons-material/LanguageOutlined';
import { useNavigate } from 'react-router-dom';
import { API_BASE_URL } from '../config';
import LoginBrandingPanel from './login/LoginBrandingPanel';
import LoginFormCard from './login/LoginFormCard';
import LoginPageFooter from './login/LoginPageFooter';

export default function LoginPage() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const navigate = useNavigate();

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    try {
      const res = await fetch(`${API_BASE_URL}/api/login`, {
        method: 'POST',
        credentials: 'include',    // para mandar cookies/token de sessão
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password }),
      });

      if (!res.ok) throw new Error(`Erro ${res.status}`);

      const data = await res.json();
      localStorage.setItem('accessToken', data.access_token);
      localStorage.setItem('refreshToken', data.refresh_token);

      navigate('/dashboard');
    } catch (err) {
      console.error('Falha no login:', err);
    }
  };

  return (
    <Box sx={{ minHeight: '100vh', display: 'flex', flexDirection: 'column' }}>
      <Box sx={{ display: 'flex', flex: 1, position: 'relative' }}>
        <Box
          sx={{
            position: 'absolute',
            top: 24,
            right: 24,
            display: 'flex',
            alignItems: 'center',
            gap: 0.5,
            color: 'text.secondary',
            zIndex: 1,
          }}
        >
          <LanguageOutlinedIcon fontSize="small" />
          <Typography variant="body2">Português (Brasil)</Typography>
        </Box>

        <LoginBrandingPanel />

        <Box
          sx={{
            flex: 1,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            p: 3,
          }}
        >
          <LoginFormCard
            email={email}
            password={password}
            onEmailChange={setEmail}
            onPasswordChange={setPassword}
            onSubmit={handleSubmit}
          />
        </Box>
      </Box>

      <LoginPageFooter />
    </Box>
  );
}
