import React from 'react';
import { Box, Typography, Grid, Paper, List, ListItem, ListItemIcon, ListItemText, Chip } from '@mui/material';
import AutorenewOutlinedIcon from '@mui/icons-material/AutorenewOutlined';
import ReceiptOutlinedIcon from '@mui/icons-material/ReceiptOutlined';
import { cycleStatusChip, Summary } from '../hooks/useGroupCycle';
import CycleTotalsCards from './CycleTotalsCards';
import SummarySidePanel from './SummarySidePanel';
import UserAvatar from './UserAvatar';

// new Date('YYYY-MM-DD') interpreta a string como UTC-meia-noite, o que desloca
// a data em 1 dia para trás em fusos negativos (ex.: America/Sao_Paulo) —
// construímos a partir dos componentes locais para evitar isso.
const formatDate = (dateStr: string): string => {
  const [year, month, day] = dateStr.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' });
};

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

type CycleDetailPanelProps = {
  summary: Summary;
};

/**
 * Chip de status + cards de totais + lista de despesas + painel lateral
 * (saldo/liquidação) de um ciclo — extraído de `GroupSummary.tsx` para ser
 * reaproveitado também pela tela de Relatórios (histórico de ciclos
 * fechados), que mostra o mesmo detalhe para um ciclo passado selecionado,
 * sem o cabeçalho de navegação por seta (que só faz sentido para a
 * competência vigente).
 */
const CycleDetailPanel: React.FC<CycleDetailPanelProps> = ({ summary }) => (
  <>
    <Box display="flex" justifyContent="center" mb={3}>
      <Chip
        label={cycleStatusChip[summary.cycle.status].label}
        color={cycleStatusChip[summary.cycle.status].color}
        variant={cycleStatusChip[summary.cycle.status].variant}
        size="small"
      />
    </Box>

    <CycleTotalsCards totals={summary.totals} cycle={summary.cycle} />

    <Grid container spacing={3}>
      <Grid size={{ xs: 12, sm: 12, lg: 8 }}>
        <Typography variant="h6" gutterBottom>
          Despesas do ciclo
        </Typography>
        {summary.expenses.length === 0 ? (
          <Typography color="text.secondary" mb={3}>
            Nenhuma despesa neste ciclo.
          </Typography>
        ) : (
          <Paper elevation={3} sx={{ mb: 3 }}>
            <List disablePadding>
              {summary.expenses.map(expense => (
                <ListItem key={expense.id} divider sx={{ gap: 1 }}>
                  <ListItemIcon sx={{ minWidth: 40 }}>
                    {expense.isFixed ? (
                      <AutorenewOutlinedIcon color="action" fontSize="small" />
                    ) : (
                      <ReceiptOutlinedIcon color="action" fontSize="small" />
                    )}
                  </ListItemIcon>
                  <ListItemText
                    primary={`${expense.description} — R$ ${formatMoney(expense.value)}`}
                    secondary={
                      `${formatDate(expense.date)} · Pago por ${expense.payerName ?? '-'} · ` +
                      `Dividido entre ${expense.participants.length} pessoa${expense.participants.length === 1 ? '' : 's'}`
                    }
                  />
                  <UserAvatar
                    name={expense.payerName ?? '-'}
                    avatarUrl={expense.payerAvatarUrl}
                    sx={{ width: 28, height: 28, fontSize: '0.75rem' }}
                  />
                  <Chip
                    label={expense.paid ? 'Paga' : 'Pendente'}
                    color={expense.paid ? 'success' : 'warning'}
                    size="small"
                  />
                </ListItem>
              ))}
            </List>
          </Paper>
        )}
      </Grid>

      <Grid size={{ xs: 12, sm: 12, lg: 4 }}>
        <SummarySidePanel
          balances={summary.balances}
          settlements={summary.settlements}
          cycleStatus={summary.cycle.status}
        />
      </Grid>
    </Grid>
  </>
);

export default CycleDetailPanel;
