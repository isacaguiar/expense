import { createTheme, Theme } from '@mui/material/styles';
import { brandColors } from './brandColors';

// Tema com escopo restrito às telas de Despesas (ExpenseManager, ExpenseView,
// ExpenseForm, ExpensesEntry) — ver docs/feature/20260825-redesign-visual-despesas/plan.md §1.
//
// `createTheme(baseTheme, overrides)` (assinatura de dois argumentos do MUI)
// faz merge com o tema recebido em vez de substituí-lo — herda tipografia,
// breakpoints etc. do tema global e só sobrescreve cor de marca, radius e
// sombra dos componentes usados nessas telas. Não é aplicado no shell
// (Sidebar/GroupHeader): o `DespesasThemeScope` embrulha só o retorno de
// cada página, nunca o layout.
export function createDespesasTheme(baseTheme: Theme): Theme {
  return createTheme(baseTheme, {
    palette: {
      primary: {
        main: brandColors.primary,
        dark: brandColors.primaryDark,
        light: brandColors.primaryLight,
        contrastText: '#FFFFFF'
      }
    },
    shape: {
      borderRadius: 10
    },
    components: {
      MuiButton: {
        styleOverrides: {
          root: {
            borderRadius: 999,
            textTransform: 'none',
            fontWeight: 600
          }
        }
      },
      MuiChip: {
        styleOverrides: {
          root: {
            borderRadius: 999,
            fontWeight: 600
          }
        }
      },
      MuiToggleButton: {
        styleOverrides: {
          root: {
            borderRadius: 999,
            textTransform: 'none',
            border: 0,
            '&.Mui-selected, &.Mui-selected:hover': {
              backgroundColor: brandColors.primary,
              color: '#FFFFFF'
            }
          }
        }
      },
      MuiToggleButtonGroup: {
        styleOverrides: {
          root: {
            padding: 4,
            backgroundColor: brandColors.primaryLight,
            borderRadius: 999,
            gap: 4
          }
        }
      },
      MuiOutlinedInput: {
        styleOverrides: {
          root: {
            borderRadius: 999
          }
        }
      },
      MuiPaper: {
        styleOverrides: {
          root: {
            boxShadow: '0 1px 2px rgba(18, 43, 79, 0.06), 0 8px 24px rgba(18, 43, 79, 0.06)'
          }
        }
      },
      MuiDialog: {
        styleOverrides: {
          paper: {
            borderRadius: 16
          }
        }
      }
    }
  });
}
