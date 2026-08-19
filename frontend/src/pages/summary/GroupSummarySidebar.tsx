import React from 'react';
import Box from '@mui/material/Box';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemIcon from '@mui/material/ListItemIcon';
import ListItemText from '@mui/material/ListItemText';
import Typography from '@mui/material/Typography';
import { Link as RouterLink, useLocation } from 'react-router-dom';
import HomeOutlinedIcon from '@mui/icons-material/HomeOutlined';
import ReceiptLongOutlinedIcon from '@mui/icons-material/ReceiptLongOutlined';
import PeopleOutlineOutlinedIcon from '@mui/icons-material/PeopleOutlineOutlined';
import PaymentsOutlinedIcon from '@mui/icons-material/PaymentsOutlined';
import AssessmentOutlinedIcon from '@mui/icons-material/AssessmentOutlined';
import SettingsOutlinedIcon from '@mui/icons-material/SettingsOutlined';
import logoIcon from '../../assets/images/logo-expense.png';
import { brandColors } from '../../theme/brandColors';

interface GroupSummarySidebarProps {
  groupId: string;
}

type NavItem = {
  label: string;
  icon: React.ElementType;
  to?: string;
};

export default function GroupSummarySidebar({ groupId }: GroupSummarySidebarProps) {
  const location = useLocation();

  const navItems: NavItem[] = [
    { label: 'Resumo', icon: HomeOutlinedIcon, to: `/groups/${groupId}/summary` },
    { label: 'Despesas', icon: ReceiptLongOutlinedIcon, to: `/groups/${groupId}/expenses` },
    { label: 'Participantes', icon: PeopleOutlineOutlinedIcon, to: `/groups/${groupId}/members` },
    { label: 'Pagamentos', icon: PaymentsOutlinedIcon },
    { label: 'Relatórios', icon: AssessmentOutlinedIcon },
    { label: 'Configurações', icon: SettingsOutlinedIcon },
  ];

  return (
    <Box
      component="nav"
      data-group-id={groupId}
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
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, px: 3, mb: 3 }}>
        <Box component="img" src={logoIcon} alt="" sx={{ width: 36, height: 36, objectFit: 'contain' }} />
        <Typography sx={{ fontFamily: "'Poppins', sans-serif", fontWeight: 700, fontSize: '1.125rem' }}>
          <Box component="span" sx={{ color: brandColors.textDark }}>
            Shared
          </Box>{' '}
          <Box component="span" sx={{ color: brandColors.primary }}>
            Expense
          </Box>
        </Typography>
      </Box>

      <List sx={{ px: 1.5 }}>
        {navItems.map((item) => {
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
