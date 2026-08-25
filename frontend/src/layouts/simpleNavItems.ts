import HomeOutlinedIcon from '@mui/icons-material/HomeOutlined';
import ReceiptLongOutlinedIcon from '@mui/icons-material/ReceiptLongOutlined';
import AssessmentOutlinedIcon from '@mui/icons-material/AssessmentOutlined';
import PeopleOutlineOutlinedIcon from '@mui/icons-material/PeopleOutlineOutlined';
import PaymentsOutlinedIcon from '@mui/icons-material/PaymentsOutlined';
import SettingsOutlinedIcon from '@mui/icons-material/SettingsOutlined';
import LogoutOutlinedIcon from '@mui/icons-material/LogoutOutlined';
import type { NavigateFunction } from 'react-router-dom';
import type { GroupNavItem } from './group/GroupSidebar';
import { accountSettingsNavItems } from './accountSettingsNavItems';
import { logout } from '../auth/logout';

export function simpleNavItems(navigate: NavigateFunction): GroupNavItem[] {
  return [
    { label: 'Home', icon: HomeOutlinedIcon, to: '/summary' },
    { label: 'Despesas', icon: ReceiptLongOutlinedIcon, to: '/expenses' },
    { label: 'Participantes', icon: PeopleOutlineOutlinedIcon },
    { label: 'Pagamentos', icon: PaymentsOutlinedIcon, to: '/payments' },
    { label: 'Relatórios', icon: AssessmentOutlinedIcon },
    {
      label: 'Configurações',
      icon: SettingsOutlinedIcon,
      children: accountSettingsNavItems(),
    },
    { label: 'Sair', icon: LogoutOutlinedIcon, onAction: () => logout(navigate) },
  ];
}
