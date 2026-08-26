import React from 'react';
import { ThemeProvider, useTheme } from '@mui/material/styles';
import { createDespesasTheme } from './despesasTheme';

interface DespesasThemeScopeProps {
  children: React.ReactNode;
}

// Aplica o tema visual de Despesas (docs/feature/20260825-redesign-visual-despesas)
// só à árvore de componentes que está dentro dele — usado no retorno de cada
// página de Despesas, nunca no shell (Sidebar/GroupHeader ficam fora, com o
// tema global inalterado).
export default function DespesasThemeScope({ children }: DespesasThemeScopeProps) {
  const outerTheme = useTheme();
  return <ThemeProvider theme={createDespesasTheme(outerTheme)}>{children}</ThemeProvider>;
}
