import HomeOutlinedIcon from '@mui/icons-material/HomeOutlined';
import ReceiptLongOutlinedIcon from '@mui/icons-material/ReceiptLongOutlined';
import AssessmentOutlinedIcon from '@mui/icons-material/AssessmentOutlined';
import PeopleOutlineOutlinedIcon from '@mui/icons-material/PeopleOutlineOutlined';
import PaymentsOutlinedIcon from '@mui/icons-material/PaymentsOutlined';
import SettingsOutlinedIcon from '@mui/icons-material/SettingsOutlined';
import type { GroupNavItem } from './group/GroupSidebar';

export function simpleNavItems(): GroupNavItem[] {
  return [
    { label: 'Meus Grupos', icon: HomeOutlinedIcon, to: '/dashboard' },
    { label: 'Resumo', icon: AssessmentOutlinedIcon, to: '/summary' },
    { label: 'Despesas', icon: ReceiptLongOutlinedIcon, to: '/expenses' },
    { label: 'Participantes', icon: PeopleOutlineOutlinedIcon },
    { label: 'Pagamentos', icon: PaymentsOutlinedIcon },
    { label: 'Configurações', icon: SettingsOutlinedIcon },
  ];
}
