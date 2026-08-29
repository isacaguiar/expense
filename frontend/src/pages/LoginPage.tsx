import React, { useState } from 'react';
import Box from '@mui/material/Box';
import { useNavigate } from 'react-router-dom';
import { API_BASE_URL } from '../config';
import LoginBrandingPanel from './login/LoginBrandingPanel';
import LoginFormCard from './login/LoginFormCard';
import LoginPageFooter from './login/LoginPageFooter';

export default function LoginPage() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const navigate = useNavigate();

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    try {
      const res = await fetch(`${API_BASE_URL}/api/login`, {
        method: 'POST',
        credentials: 'include',    // para mandar cookies/token de sessão
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password }),
      });

      if (!res.ok) {
        setError(
          res.status === 401 || res.status === 422
            ? 'E-mail ou senha inválidos.'
            : 'Não foi possível fazer login. Tente novamente em instantes.'
        );
        return;
      }

      const data = await res.json();
      localStorage.setItem('accessToken', data.access_token);
      localStorage.setItem('refreshToken', data.refresh_token);

      navigate('/meus-grupos');
    } catch (err) {
      console.error('Falha no login:', err);
      setError('Não foi possível fazer login. Verifique sua conexão e tente novamente.');
    }
  };

  return (
    <Box sx={{ minHeight: '100vh', display: 'flex', flexDirection: 'column' }}>
      <Box sx={{ display: 'flex', flex: 1, position: 'relative' }}>
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
            error={error}
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
