import React from 'react';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import GroupsOutlinedIcon from '@mui/icons-material/GroupsOutlined';
import PieChartOutlineOutlinedIcon from '@mui/icons-material/PieChartOutlineOutlined';
import ShieldOutlinedIcon from '@mui/icons-material/ShieldOutlined';
import logo from '../../assets/images/expense.png';
import loginHero from '../../assets/illustrations/login-hero.png';

const differentials = [
  {
    icon: GroupsOutlinedIcon,
    title: 'Grupos organizados',
    description: 'Crie grupos e convide amigos, familiares ou colegas.',
  },
  {
    icon: PieChartOutlineOutlinedIcon,
    title: 'Divisão igualitária',
    description: 'O sistema divide os valores igualmente entre os membros.',
  },
  {
    icon: ShieldOutlinedIcon,
    title: 'Seguro e confiável',
    description: 'Seus dados são protegidos com segurança e privacidade garantida.',
  },
];

export default function LoginBrandingPanel() {
  return (
    <Box
      sx={{
        flex: 1,
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'center',
        gap: 3,
        px: 6,
        py: 4,
        background: 'linear-gradient(180deg, #E8F5E9 0%, #FFFFFF 100%)',
      }}
    >
      <Box
        component="img"
        src={logo}
        alt="Shared Expense"
        sx={{ width: 220, objectFit: 'contain' }}
      />

      <Box>
        <Typography variant="h4" fontWeight="bold" color="text.primary">
          Despesas compartilhadas,
        </Typography>
        <Typography variant="h4" fontWeight="bold" color="primary.main">
          contas em dia.
        </Typography>
      </Box>

      <Typography variant="body1" color="text.secondary" sx={{ maxWidth: 440 }}>
        Controle de despesas mensais fixas e variáveis entre grupos de usuários, com
        divisão igualitária dos valores entre os pagadores designados.
      </Typography>

      <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
        {differentials.map(({ icon: Icon, title, description }) => (
          <Box key={title} sx={{ display: 'flex', alignItems: 'flex-start', gap: 2 }}>
            <Box
              sx={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                width: 44,
                height: 44,
                borderRadius: 2,
                bgcolor: 'background.paper',
                boxShadow: 1,
                flexShrink: 0,
              }}
            >
              <Icon color="primary" />
            </Box>
            <Box>
              <Typography variant="subtitle1" fontWeight="bold">
                {title}
              </Typography>
              <Typography variant="body2" color="text.secondary">
                {description}
              </Typography>
            </Box>
          </Box>
        ))}
      </Box>

      <Box
        component="img"
        src={loginHero}
        alt=""
        sx={{ width: '100%', maxWidth: 420, alignSelf: 'center' }}
      />

      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
        <ShieldOutlinedIcon fontSize="small" color="action" />
        <Typography variant="caption" color="text.secondary">
          Seguro e confiável · Seus dados protegidos
        </Typography>
      </Box>
    </Box>
  );
}
