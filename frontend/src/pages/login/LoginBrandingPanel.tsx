import React from 'react';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import GroupsOutlinedIcon from '@mui/icons-material/GroupsOutlined';
import PieChartOutlineOutlinedIcon from '@mui/icons-material/PieChartOutlineOutlined';
import ShieldOutlinedIcon from '@mui/icons-material/ShieldOutlined';
import logoIcon from '../../assets/images/logo-expense.png';
import loginHero from '../../assets/illustrations/login-hero.png';
import { loginColors } from './colors';

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
        display: { xs: 'none', md: 'flex' },
        flexDirection: 'column',
        justifyContent: 'center',
        gap: 3,
        px: 6,
        py: 4,
        background: `linear-gradient(180deg, ${loginColors.primaryLight} 0%, #FFFFFF 100%)`,
      }}
    >
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
        <Box component="img" src={logoIcon} alt="" sx={{ width: 48, height: 48, objectFit: 'contain' }} />
        <Typography
          sx={{
            fontFamily: "'Poppins', sans-serif",
            fontWeight: 700,
            fontSize: '1.5rem',
          }}
        >
          <Box component="span" sx={{ color: loginColors.textDark }}>
            Shared
          </Box>{' '}
          <Box component="span" sx={{ color: loginColors.primary }}>
            Expense
          </Box>
        </Typography>
      </Box>

      <Box>
        <Typography variant="h4" fontWeight="bold" sx={{ color: loginColors.textDark }}>
          Despesas compartilhadas,
        </Typography>
        <Typography variant="h4" fontWeight="bold" sx={{ color: loginColors.primary }}>
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
              <Icon sx={{ color: loginColors.primary }} />
            </Box>
            <Box>
              <Typography variant="subtitle1" fontWeight="bold" sx={{ color: loginColors.textDark }}>
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
