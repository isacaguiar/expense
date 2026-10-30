import React from 'react';
import { Card, CardContent, Grid, Typography } from '@mui/material';
import type { SummaryCycle, SummaryTotals } from '../hooks/useGroupCycle';

// new Date('YYYY-MM-DD') interpreta a string como UTC-meia-noite, o que desloca
// a data em 1 dia para trás em fusos negativos (ex.: America/Sao_Paulo) —
// construímos a partir dos componentes locais para evitar isso.
const formatDate = (dateStr: string): string => {
  const [year, month, day] = dateStr.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' });
};

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const percentOf = (part: number, total: number): number =>
  total > 0 ? Math.round((part / total) * 100) : 0;

type CycleTotalsCardsProps = {
  totals: SummaryTotals;
  cycle: Pick<SummaryCycle, 'start' | 'end'>;
};

/**
 * Os três cartões de totais de um ciclo (total, pago e a pagar) — extraídos de
 * `CycleDetailPanel.tsx` para serem reaproveitados pelo Relatório de Fechamento
 * sem copiar o markup.
 */
const CycleTotalsCards: React.FC<CycleTotalsCardsProps> = ({ totals, cycle }) => (
  <Grid container spacing={2} mb={3}>
    <Grid size={{ xs: 12, md: 4 }}>
      <Card>
        <CardContent>
          <Typography variant="body2" color="text.secondary">
            Total de despesas
          </Typography>
          <Typography variant="h5">R$ {formatMoney(totals.total)}</Typography>
          <Typography variant="caption" color="text.secondary" textTransform="capitalize">
            {formatDate(cycle.start)} – {formatDate(cycle.end)}
          </Typography>
        </CardContent>
      </Card>
    </Grid>
    <Grid size={{ xs: 12, md: 4 }}>
      <Card>
        <CardContent>
          <Typography variant="body2" color="text.secondary">
            Pago
          </Typography>
          <Typography variant="h5" color="success.main">
            R$ {formatMoney(totals.paid)}
          </Typography>
          <Typography variant="caption" color="text.secondary">
            {percentOf(totals.paid, totals.total)}% do total
          </Typography>
        </CardContent>
      </Card>
    </Grid>
    <Grid size={{ xs: 12, md: 4 }}>
      <Card>
        <CardContent>
          <Typography variant="body2" color="text.secondary">
            A pagar
          </Typography>
          <Typography variant="h5" color="warning.main">
            R$ {formatMoney(totals.pending)}
          </Typography>
          <Typography variant="caption" color="text.secondary">
            {percentOf(totals.pending, totals.total)}% do total
          </Typography>
        </CardContent>
      </Card>
    </Grid>
  </Grid>
);

export default CycleTotalsCards;
