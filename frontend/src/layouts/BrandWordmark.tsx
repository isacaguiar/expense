import React from 'react';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import logoIcon from '../assets/images/logo-expense.png';
import { brandColors } from '../theme/brandColors';

interface BrandWordmarkProps {
  size?: number;
  fontSize?: string;
}

export default function BrandWordmark({ size = 36, fontSize = '1.125rem' }: BrandWordmarkProps) {
  return (
    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
      <Box component="img" src={logoIcon} alt="" sx={{ width: size, height: size, objectFit: 'contain' }} />
      <Typography sx={{ fontFamily: "'Poppins', sans-serif", fontWeight: 700, fontSize }}>
        <Box component="span" sx={{ color: brandColors.textDark }}>
          Shared
        </Box>{' '}
        <Box component="span" sx={{ color: brandColors.primary }}>
          Expense
        </Box>
      </Typography>
    </Box>
  );
}
