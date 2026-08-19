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
        display: { xs: 'none', md: 'flex' },
        flexBasis: { md: '42%' },
        flexGrow: 0,
        flexShrink: 0,
        minWidth: 0,
        position: 'relative',
        overflow: 'hidden',
        background: `linear-gradient(180deg, ${loginColors.primaryLight} 0%, #FFFFFF 100%)`,
      }}
    >
      <Box
        aria-hidden
        sx={{
          position: 'absolute',
          left: 0,
          right: 0,
          bottom: 0,
          height: '55%',
          backgroundImage: `url(${loginHero})`,
          backgroundRepeat: 'no-repeat',
          backgroundPosition: 'bottom center',
          backgroundSize: '100% auto',
          zIndex: 0,
        }}
      />

      <Box
        sx={{
          position: 'relative',
          zIndex: 1,
          display: 'flex',
          flexDirection: 'column',
          justifyContent: 'flex-start',
          gap: 2.5,
          px: 5,
          pt: 6,
          pb: 4,
          height: '100%',
        }}
      >
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
        <Box component="img" src={logoIcon} alt="" sx={{ width: 64, height: 64, objectFit: 'contain' }} />
        <Typography
          sx={{
            fontFamily: "'Poppins', sans-serif",
            fontWeight: 700,
            fontSize: '1.875rem',
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
        <Typography sx={{ fontSize: '1.375rem', fontWeight: 'bold', lineHeight: 1.3, color: loginColors.textDark }}>
          Despesas compartilhadas,
        </Typography>
        <Typography sx={{ fontSize: '1.375rem', fontWeight: 'bold', lineHeight: 1.3, color: loginColors.primary }}>
          contas em dia.
        </Typography>
      </Box>

      <Typography sx={{ fontSize: '0.9rem', color: 'text.secondary', maxWidth: 400 }}>
        Controle de despesas mensais fixas e variáveis entre grupos de usuários, com
        divisão igualitária dos valores entre os pagadores designados.
      </Typography>

      <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1.5 }}>
        {differentials.map(({ icon: Icon, title, description }) => (
          <Box key={title} sx={{ display: 'flex', alignItems: 'flex-start', gap: 1.5 }}>
            <Box
              sx={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                width: 38,
                height: 38,
                borderRadius: 2,
                bgcolor: 'background.paper',
                boxShadow: 1,
                flexShrink: 0,
              }}
            >
              <Icon sx={{ color: loginColors.primary, fontSize: '1.25rem' }} />
            </Box>
            <Box>
              <Typography sx={{ fontSize: '0.9rem', fontWeight: 'bold', color: loginColors.textDark }}>
                {title}
              </Typography>
              <Typography sx={{ fontSize: '0.8rem', color: 'text.secondary' }}>
                {description}
              </Typography>
            </Box>
          </Box>
        ))}
      </Box>

      </Box>
    </Box>
  );
}
