import React, { useEffect, useState } from 'react';
import axios from 'axios';
import Box from '@mui/material/Box';
import Container from '@mui/material/Container';
import { SelectChangeEvent } from '@mui/material';
import { Outlet, useLocation, useNavigate, useParams } from 'react-router-dom';
import { API_BASE_URL } from '../config';
import GroupSidebar, { groupNavItems } from './group/GroupSidebar';
import GroupHeader from './group/GroupHeader';
import MobileNavDrawer from './MobileNavDrawer';
import { useUnreadNotificationsCount } from '../hooks/useUnreadNotificationsCount';

type GroupOption = {
  id: number;
  name: string;
};

export default function GroupShellLayout() {
  const { id: groupId } = useParams<{ id: string }>();
  const location = useLocation();
  const navigate = useNavigate();

  const [groups, setGroups] = useState<GroupOption[]>([]);
  const [userName, setUserName] = useState<string | null>(null);
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const { count: unreadCount } = useUnreadNotificationsCount();

  useEffect(() => {
    const token = localStorage.getItem('accessToken');
    axios
      .get<GroupOption[]>(`${API_BASE_URL}/api/groups`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(res => setGroups(res.data))
      .catch(err => console.error('Erro ao carregar grupos:', err));
  }, []);

  useEffect(() => {
    const token = localStorage.getItem('accessToken');
    axios
      .get<{ name: string; email: string }>(`${API_BASE_URL}/api/me`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(res => setUserName(res.data.name))
      .catch(err => console.error('Erro ao carregar usuário logado:', err));
  }, []);

  const navItems = groupNavItems(groupId ?? '', navigate);
  const activeItem = navItems.find(item => item.to === location.pathname);
  const title = activeItem?.label ?? '';

  const handleGroupChange = (event: SelectChangeEvent<number>) => {
    const newGroupId = String(event.target.value);
    const nextPath = groupId
      ? location.pathname.replace(`/groups/${groupId}`, `/groups/${newGroupId}`)
      : `/groups/${newGroupId}/summary`;
    navigate(nextPath);
  };

  return (
    <Box sx={{ display: 'flex', minHeight: '100vh' }}>
      <GroupSidebar groupId={groupId ?? ''} />
      <MobileNavDrawer items={navItems} open={mobileNavOpen} onClose={() => setMobileNavOpen(false)} />
      <Container component="main" sx={{ flex: 1, mt: { xs: 2, md: 4 }, mb: { xs: 2, md: 4 } }}>
        <GroupHeader
          title={title}
          groups={groups}
          groupId={groupId ?? ''}
          onGroupChange={handleGroupChange}
          userName={userName}
          onMenuClick={() => setMobileNavOpen(true)}
          unreadCount={unreadCount}
        />
        <Outlet />
      </Container>
    </Box>
  );
}
