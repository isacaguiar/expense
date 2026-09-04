import React from 'react';
import { Avatar, Tooltip } from '@mui/material';
import type { SxProps, Theme } from '@mui/material';
import { getInitials } from '../layouts/group/getInitials';
import { brandColors } from '../theme/brandColors';

type UserAvatarProps = {
  name: string;
  avatarUrl?: string | null;
  size?: number;
  sx?: SxProps<Theme>;
};

/**
 * Avatar de usuário reutilizado nas listagens (credor, saldo, à pagar,
 * pagamentos, entrada): foto cadastrada quando existe, círculo com iniciais
 * como fallback, nome em tooltip ao passar o mouse.
 */
const UserAvatar: React.FC<UserAvatarProps> = ({ name, avatarUrl, size, sx }) => (
  <Tooltip title={name}>
    <Avatar
      src={avatarUrl ?? undefined}
      alt={name}
      sx={{
        bgcolor: brandColors.primaryLight,
        color: brandColors.primary,
        fontSize: '0.85rem',
        ...(size ? { width: size, height: size } : {}),
        ...sx
      }}
    >
      {getInitials(name)}
    </Avatar>
  </Tooltip>
);

export default UserAvatar;
