import React from 'react';
import HomeOutlinedIcon from '@mui/icons-material/HomeOutlined';
import ReceiptLongOutlinedIcon from '@mui/icons-material/ReceiptLongOutlined';
import PeopleOutlineOutlinedIcon from '@mui/icons-material/PeopleOutlineOutlined';
import PaymentsOutlinedIcon from '@mui/icons-material/PaymentsOutlined';
import AssessmentOutlinedIcon from '@mui/icons-material/AssessmentOutlined';
import SettingsOutlinedIcon from '@mui/icons-material/SettingsOutlined';
import Sidebar from '../Sidebar';

interface GroupSidebarProps {
  groupId: string;
}

export type GroupNavItem = {
  label: string;
  icon: React.ElementType;
  to?: string;
  children?: GroupNavItem[];
};

export function groupNavItems(groupId: string): GroupNavItem[] {
  return [
    { label: 'Resumo', icon: HomeOutlinedIcon, to: `/groups/${groupId}/summary` },
    { label: 'Despesas', icon: ReceiptLongOutlinedIcon, to: `/groups/${groupId}/expenses` },
    { label: 'Participantes', icon: PeopleOutlineOutlinedIcon, to: `/groups/${groupId}/members` },
    { label: 'Pagamentos', icon: PaymentsOutlinedIcon },
    { label: 'Relatórios', icon: AssessmentOutlinedIcon },
    { label: 'Configurações', icon: SettingsOutlinedIcon, to: `/groups/${groupId}/edit` },
  ];
}

export default function GroupSidebar({ groupId }: GroupSidebarProps) {
  return <Sidebar items={groupNavItems(groupId)} />;
}
