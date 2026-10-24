import React from 'react';
import { Box, Grid, Chip } from '@mui/material';
import { cycleStatusChip, Summary } from '../hooks/useGroupCycle';
import CycleExpenseList from './CycleExpenseList';
import CycleTotalsCards from './CycleTotalsCards';
import SummarySidePanel from './SummarySidePanel';

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
        <CycleExpenseList expenses={summary.expenses} />
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
