import React from 'react';
import Box from '@mui/material/Box';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemIcon from '@mui/material/ListItemIcon';
import ListItemText from '@mui/material/ListItemText';
import Typography from '@mui/material/Typography';
import { Link as RouterLink, useLocation } from 'react-router-dom';
import { brandColors } from '../theme/brandColors';
import BrandWordmark from './BrandWordmark';
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

      <List sx={{ px: 1.5 }}>
        {items.map((item) => {
          const active = item.to !== undefined && location.pathname === item.to;
          const Icon = item.icon;
          const label = <Typography sx={{ fontSize: '0.9rem' }}>{item.label}</Typography>;
          const sx = {
            borderRadius: 2,
            mb: 0.5,
            '&.Mui-selected': {
              bgcolor: brandColors.primaryLight,
              color: brandColors.primary,
              '&:hover': { bgcolor: brandColors.primaryLight },
            },
          };

          if (item.to) {
            return (
              <ListItemButton key={item.label} component={RouterLink} to={item.to} selected={active} sx={sx}>
                <ListItemIcon sx={{ minWidth: 36, color: active ? brandColors.primary : 'inherit' }}>
                  <Icon fontSize="small" />
                </ListItemIcon>
                <ListItemText primary={label} />
              </ListItemButton>
            );
          }

          return (
            <ListItemButton key={item.label} component="a" href="#" sx={{ ...sx, color: 'text.disabled' }}>
              <ListItemIcon sx={{ minWidth: 36, color: 'text.disabled' }}>
                <Icon fontSize="small" />
              </ListItemIcon>
              <ListItemText primary={label} />
            </ListItemButton>
          );
        })}
      </List>
    </Box>
  );
}
