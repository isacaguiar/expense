import GroupsOutlinedIcon from '@mui/icons-material/GroupsOutlined';
import PersonOutlineOutlinedIcon from '@mui/icons-material/PersonOutlineOutlined';
import LockOutlinedIcon from '@mui/icons-material/LockOutlined';
import type { GroupNavItem } from './group/GroupSidebar';

export function accountSettingsNavItems(): GroupNavItem[] {
  return [
    { label: 'Meus Grupos', icon: GroupsOutlinedIcon, to: '/dashboard' },
    { label: 'Minha Conta', icon: PersonOutlineOutlinedIcon, to: '/profile' },
    { label: 'Alterar Senha', icon: LockOutlinedIcon, to: '/change-password' },
  ];
}
