import React, { useState } from 'react';
import Box from '@mui/material/Box';
import Collapse from '@mui/material/Collapse';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemIcon from '@mui/material/ListItemIcon';
import ListItemText from '@mui/material/ListItemText';
import Typography from '@mui/material/Typography';
import ExpandLessIcon from '@mui/icons-material/ExpandLess';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import { Link as RouterLink, useLocation } from 'react-router-dom';
import { brandColors } from '../theme/brandColors';
import BrandWordmark from './BrandWordmark';
import type { GroupNavItem } from './group/GroupSidebar';

interface SidebarProps {
  items: GroupNavItem[];
}

const itemSx = {
  borderRadius: 2,
  mb: 0.5,
  '&.Mui-selected': {
    bgcolor: brandColors.primaryLight,
    color: brandColors.primary,
    '&:hover': { bgcolor: brandColors.primaryLight },
  },
};

function containsActiveChild(item: GroupNavItem, pathname: string): boolean {
  return (item.children ?? []).some(child => child.to === pathname);
}

interface SidebarNavItemProps {
  item: GroupNavItem;
  pathname: string;
  indent?: boolean;
}

function SidebarNavItem({ item, pathname, indent = false }: SidebarNavItemProps) {
  const [open, setOpen] = useState(() => containsActiveChild(item, pathname));
  const Icon = item.icon;
  const label = <Typography sx={{ fontSize: '0.9rem' }}>{item.label}</Typography>;
  const active = item.to !== undefined && pathname === item.to;
  const sx = indent ? { ...itemSx, pl: 4 } : itemSx;

  if (item.children) {
    return (
      <>
        <ListItemButton onClick={() => setOpen(!open)} sx={sx}>
          <ListItemIcon sx={{ minWidth: 36 }}>
            <Icon fontSize="small" />
          </ListItemIcon>
          <ListItemText primary={label} />
          {open ? <ExpandLessIcon fontSize="small" /> : <ExpandMoreIcon fontSize="small" />}
        </ListItemButton>
        <Collapse in={open} timeout="auto" unmountOnExit>
          <List component="div" disablePadding>
            {item.children.map(child => (
              <SidebarNavItem key={child.label} item={child} pathname={pathname} indent />
            ))}
          </List>
        </Collapse>
      </>
    );
  }

  if (item.to) {
    return (
      <ListItemButton component={RouterLink} to={item.to} selected={active} sx={sx}>
        <ListItemIcon sx={{ minWidth: 36, color: active ? brandColors.primary : 'inherit' }}>
          <Icon fontSize="small" />
        </ListItemIcon>
        <ListItemText primary={label} />
      </ListItemButton>
    );
  }

  return (
    <ListItemButton component="a" href="#" sx={{ ...sx, color: 'text.disabled' }}>
      <ListItemIcon sx={{ minWidth: 36, color: 'text.disabled' }}>
        <Icon fontSize="small" />
      </ListItemIcon>
      <ListItemText primary={label} />
    </ListItemButton>
  );
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
        {items.map((item) => (
          <SidebarNavItem key={item.label} item={item} pathname={location.pathname} />
        ))}
      </List>
    </Box>
  );
}
