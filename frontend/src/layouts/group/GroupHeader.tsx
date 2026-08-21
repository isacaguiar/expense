import React from 'react';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import Avatar from '@mui/material/Avatar';
import IconButton from '@mui/material/IconButton';
import Select from '@mui/material/Select';
import MenuItem from '@mui/material/MenuItem';
import { SelectChangeEvent } from '@mui/material';
import NotificationsNoneOutlinedIcon from '@mui/icons-material/NotificationsNoneOutlined';
import { brandColors } from '../../theme/brandColors';
import { getInitials } from './getInitials';

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
}

export default function GroupHeader({ title, groups, groupId, onGroupChange, userName }: GroupHeaderProps) {
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
      <Typography variant="h5" sx={{ fontWeight: 'bold' }}>
        {title}
      </Typography>

      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
        {groups.length > 0 && groupId && (
          <Select value={Number(groupId)} onChange={onGroupChange} size="small" sx={{ minWidth: 180 }}>
            {groups.map((group) => (
              <MenuItem key={group.id} value={group.id}>
                {group.name}
              </MenuItem>
            ))}
          </Select>
        )}

        <IconButton aria-label="Notificações" size="small">
          <NotificationsNoneOutlinedIcon />
        </IconButton>

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
    </Box>
  );
}
