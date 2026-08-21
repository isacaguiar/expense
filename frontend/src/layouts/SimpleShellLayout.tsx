import React, { useEffect, useState } from 'react';
import axios from 'axios';
import Box from '@mui/material/Box';
import Container from '@mui/material/Container';
import Avatar from '@mui/material/Avatar';
import Typography from '@mui/material/Typography';
import { Outlet } from 'react-router-dom';
import { API_BASE_URL } from '../config';
import { brandColors } from '../theme/brandColors';
import { getInitials } from './group/getInitials';
import BrandWordmark from './BrandWordmark';

export default function SimpleShellLayout() {
  const [userName, setUserName] = useState<string | null>(null);

  useEffect(() => {
    const token = localStorage.getItem('accessToken');
    axios
      .get<{ name: string; email: string }>(`${API_BASE_URL}/api/me`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(res => setUserName(res.data.name))
      .catch(err => console.error('Erro ao carregar usuário logado:', err));
  }, []);

  return (
    <Box sx={{ minHeight: '100vh' }}>
      <Box
        component="header"
        sx={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          flexWrap: 'wrap',
          gap: 2,
          px: { xs: 2, md: 4 },
          py: 2,
          borderBottom: '1px solid',
          borderColor: 'divider',
          bgcolor: 'background.paper',
        }}
      >
        <BrandWordmark />

        {userName && (
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <Avatar
              sx={{
                width: 32,
                height: 32,
                bgcolor: brandColors.primaryLight,
                color: brandColors.primary,
                fontSize: '0.8rem',
                fontWeight: 'bold',
              }}
            >
              {getInitials(userName)}
            </Avatar>
            <Typography sx={{ fontSize: '0.85rem' }}>{userName}</Typography>
          </Box>
        )}
      </Box>

      <Container component="main" sx={{ mt: 4, mb: 4 }}>
        <Outlet />
      </Container>
    </Box>
  );
}
