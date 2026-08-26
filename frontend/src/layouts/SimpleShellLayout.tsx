import React, { useEffect, useState } from 'react';
import axios from 'axios';
import Box from '@mui/material/Box';
import Container from '@mui/material/Container';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import { API_BASE_URL } from '../config';
import Sidebar from './Sidebar';
import { simpleNavItems } from './simpleNavItems';
import GroupHeader from './group/GroupHeader';

export default function SimpleShellLayout() {
  const location = useLocation();
  const navigate = useNavigate();
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

  const flatItems = simpleNavItems(navigate).flatMap(item => [item, ...(item.children ?? [])]);
  const activeItem = flatItems.find(item => item.to === location.pathname);
  const title = activeItem?.label ?? '';

  return (
    <Box sx={{ display: 'flex', minHeight: '100vh' }}>
      <Sidebar items={simpleNavItems(navigate)} />
      <Container component="main" sx={{ flex: 1, mt: 4, mb: 4 }}>
        <GroupHeader
          title={title}
          groups={[]}
          groupId=""
          onGroupChange={() => {}}
          userName={userName}
          onMenuClick={() => {}}
        />
        <Outlet />
      </Container>
    </Box>
  );
}
