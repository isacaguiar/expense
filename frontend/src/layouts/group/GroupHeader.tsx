import React, { useState } from 'react';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import Avatar from '@mui/material/Avatar';
import Badge from '@mui/material/Badge';
import IconButton from '@mui/material/IconButton';
import Select from '@mui/material/Select';
import MenuItem from '@mui/material/MenuItem';
import { SelectChangeEvent } from '@mui/material';
import MenuIcon from '@mui/icons-material/Menu';
import NotificationsNoneOutlinedIcon from '@mui/icons-material/NotificationsNoneOutlined';
import { brandColors } from '../../theme/brandColors';
import { getInitials } from './getInitials';
import NotificationsMenu from '../../components/NotificationsMenu';

type GroupOption = {
  id: number;
  name: string;
};

interface GroupHeaderProps {
  title: string;
  groups: GroupOption[];
  groupId: string;
  onGroupChange: (event: SelectChangeEvent<number>) => void;
  userName: string | null;
  onMenuClick: () => void;
  /** Notificações não-lidas do usuário logado (badge do sino). */
  unreadCount?: number;
  /** Chamado após o menu marcar algo como lido, para recarregar o contador. */
  onNotificationsRead?: () => void;
}

export default function GroupHeader({
  title,
  groups,
  groupId,
  onGroupChange,
  userName,
  onMenuClick,
  unreadCount = 0,
  onNotificationsRead = () => {},
}: GroupHeaderProps) {
  const [notifAnchor, setNotifAnchor] = useState<null | HTMLElement>(null);
  return (
    <Box
      component="header"
      sx={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        flexWrap: 'wrap',
        gap: 2,
        pb: 3,
      }}
    >
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
        <IconButton
          aria-label="Abrir menu de navegação"
          onClick={onMenuClick}
          sx={{ display: { xs: 'inline-flex', md: 'none' } }}
        >
          <MenuIcon />
        </IconButton>

        <Typography variant="h5" sx={{ fontWeight: 'bold' }}>
          {title}
        </Typography>
      </Box>

      <Box
        sx={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'flex-end',
          flexWrap: 'wrap',
          gap: 2,
          rowGap: 1,
        }}
      >
        {groups.length > 0 && groupId && (
          <Select
            value={Number(groupId)}
            onChange={onGroupChange}
            size="small"
            sx={{ minWidth: { xs: 132, sm: 180 }, maxWidth: { xs: 200, sm: 'none' } }}
          >
            {groups.map((group) => (
              <MenuItem key={group.id} value={group.id}>
                {group.name}
              </MenuItem>
            ))}
          </Select>
        )}

        <IconButton
          aria-label="Notificações"
          size="small"
          onClick={(event) => setNotifAnchor(event.currentTarget)}
        >
          <Badge badgeContent={unreadCount} color="error" max={99}>
            <NotificationsNoneOutlinedIcon />
          </Badge>
        </IconButton>

        <NotificationsMenu
          anchorEl={notifAnchor}
          open={Boolean(notifAnchor)}
          onClose={() => setNotifAnchor(null)}
          onRead={onNotificationsRead}
        />

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
            <Typography sx={{ fontSize: '0.85rem', display: { xs: 'none', sm: 'block' } }}>{userName}</Typography>
          </Box>
        )}
      </Box>
    </Box>
  );
}
