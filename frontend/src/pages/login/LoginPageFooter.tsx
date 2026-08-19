import React from 'react';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import Link from '@mui/material/Link';

export default function LoginPageFooter() {
  return (
    <Box
      component="footer"
      sx={{
        display: 'flex',
        justifyContent: 'center',
        alignItems: 'center',
        flexWrap: 'wrap',
        gap: 2,
        py: 2,
        px: 3,
        borderTop: '1px solid',
        borderColor: 'divider',
      }}
    >
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
  );
}
