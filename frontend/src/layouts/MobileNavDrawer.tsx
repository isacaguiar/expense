import React from 'react';
import Box from '@mui/material/Box';
import Drawer from '@mui/material/Drawer';
import { useLocation } from 'react-router-dom';
import BrandWordmark from './BrandWordmark';
import NavList from './NavList';
import type { GroupNavItem } from './group/GroupSidebar';

interface MobileNavDrawerProps {
  items: GroupNavItem[];
  open: boolean;
  onClose: () => void;
}

export default function MobileNavDrawer({ items, open, onClose }: MobileNavDrawerProps) {
  const location = useLocation();

  return (
    <Drawer variant="temporary" open={open} onClose={onClose} sx={{ display: { xs: 'block', md: 'none' } }}>
      <Box sx={{ width: 280, display: 'flex', flexDirection: 'column', py: 3 }}>
        <Box sx={{ px: 3, mb: 3 }}>
          <BrandWordmark />
        </Box>

        <NavList items={items} pathname={location.pathname} onNavigate={onClose} />
      </Box>
    </Drawer>
  );
}
