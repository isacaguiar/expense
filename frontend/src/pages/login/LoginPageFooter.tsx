import React from 'react';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import Link from '@mui/material/Link';
import ShieldOutlinedIcon from '@mui/icons-material/ShieldOutlined';

export default function LoginPageFooter() {
  return (
    <Box
      component="footer"
      sx={{
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'center',
        flexWrap: 'wrap',
        gap: 1.5,
        py: 2,
        px: 3,
        borderTop: '1px solid',
        borderColor: 'divider',
      }}
    >
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
        <ShieldOutlinedIcon fontSize="small" color="action" />
        <Typography variant="caption" color="text.secondary">
          Seguro e confiável · Seus dados protegidos
        </Typography>
      </Box>

      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, flexWrap: 'wrap' }}>
        <Typography variant="caption" color="text.secondary">
          © 2026 Shared Expense. Todos os direitos reservados.
        </Typography>
        <Link href="#" variant="caption" color="text.secondary" underline="hover">
          Termos de uso
        </Link>
        <Link href="#" variant="caption" color="text.secondary" underline="hover">
          Política de privacidade
        </Link>
      </Box>
    </Box>
  );
}
