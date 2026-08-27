import React from 'react';
import HomeOutlinedIcon from '@mui/icons-material/HomeOutlined';
import ReceiptLongOutlinedIcon from '@mui/icons-material/ReceiptLongOutlined';
import PeopleOutlineOutlinedIcon from '@mui/icons-material/PeopleOutlineOutlined';
import PaymentsOutlinedIcon from '@mui/icons-material/PaymentsOutlined';
import AssessmentOutlinedIcon from '@mui/icons-material/AssessmentOutlined';
import SettingsOutlinedIcon from '@mui/icons-material/SettingsOutlined';
import LogoutOutlinedIcon from '@mui/icons-material/LogoutOutlined';
import type { NavigateFunction } from 'react-router-dom';
import { useNavigate } from 'react-router-dom';
import Sidebar from '../Sidebar';
import { accountSettingsNavItems } from '../accountSettingsNavItems';
import { logout } from '../../auth/logout';

interface GroupSidebarProps {
  groupId: string;
}

export type GroupNavItem = {
  label: string;
  icon: React.ElementType;
  to?: string;
  children?: GroupNavItem[];
  onAction?: () => void;
};

export function groupNavItems(groupId: string, navigate: NavigateFunction): GroupNavItem[] {
  return [
    { label: 'Home', icon: HomeOutlinedIcon, to: `/groups/${groupId}/summary` },
    { label: 'Despesas', icon: ReceiptLongOutlinedIcon, to: `/groups/${groupId}/expenses` },
    { label: 'Participantes', icon: PeopleOutlineOutlinedIcon, to: `/groups/${groupId}/members` },
    { label: 'Pagamentos', icon: PaymentsOutlinedIcon, to: `/groups/${groupId}/payments` },
    { label: 'Relatórios', icon: AssessmentOutlinedIcon, to: `/groups/${groupId}/reports` },
    {
      label: 'Configurações',
      icon: SettingsOutlinedIcon,
      children: accountSettingsNavItems(),
    },
    { label: 'Sair', icon: LogoutOutlinedIcon, onAction: () => logout(navigate) },
  ];
}

export default function GroupSidebar({ groupId }: GroupSidebarProps) {
  const navigate = useNavigate();
  return <Sidebar items={groupNavItems(groupId, navigate)} />;
}
