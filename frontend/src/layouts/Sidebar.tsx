import React from 'react';
import Box from '@mui/material/Box';
import { useLocation } from 'react-router-dom';
import BrandWordmark from './BrandWordmark';
import NavList from './NavList';
import type { GroupNavItem } from './group/GroupSidebar';

interface SidebarProps {
  items: GroupNavItem[];
}

export default function Sidebar({ items }: SidebarProps) {
  const location = useLocation();

  return (
    <Box
      component="nav"
      sx={{
        width: 280,
        flexShrink: 0,
        borderRight: '1px solid',
        borderColor: 'divider',
        bgcolor: 'background.paper',
        display: { xs: 'none', md: 'flex' },
        flexDirection: 'column',
        py: 3,
      }}
    >
      <Box sx={{ px: 3, mb: 3 }}>
        <BrandWordmark />
      </Box>

      <NavList items={items} pathname={location.pathname} />
    </Box>
  );
}
