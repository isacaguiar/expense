import React, { useState } from 'react';
import Box from '@mui/material/Box';
import Select, { SelectChangeEvent } from '@mui/material/Select';
import MenuItem from '@mui/material/MenuItem';
import LanguageOutlinedIcon from '@mui/icons-material/LanguageOutlined';
import { useNavigate } from 'react-router-dom';
import { API_BASE_URL } from '../config';
import LoginBrandingPanel from './login/LoginBrandingPanel';
import LoginFormCard from './login/LoginFormCard';
import LoginPageFooter from './login/LoginPageFooter';

export default function LoginPage() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [language, setLanguage] = useState('pt-BR');
  const navigate = useNavigate();

  const handleLanguageChange = (event: SelectChangeEvent) => {
    setLanguage(event.target.value);
  };

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
        <Select
          value={language}
          onChange={handleLanguageChange}
          size="small"
          startAdornment={<LanguageOutlinedIcon fontSize="small" sx={{ mr: 1 }} />}
          sx={{
            position: 'absolute',
            top: 24,
            right: 24,
            zIndex: 1,
            bgcolor: 'background.paper',
            fontSize: '0.875rem',
            '& .MuiSelect-select': { display: 'flex', alignItems: 'center', py: 0.75 },
          }}
        >
          <MenuItem value="pt-BR">Português (Brasil)</MenuItem>
          <MenuItem value="en-US">English (US)</MenuItem>
        </Select>

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
