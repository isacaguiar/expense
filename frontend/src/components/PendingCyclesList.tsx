import React from 'react';
import { Box, Button, Card, Chip, CircularProgress, LinearProgress, Stack, Typography } from '@mui/material';
import { cycleStatusChip } from '../hooks/useGroupCycle';
import { useGroupPendingCycles, type PendingCycle } from '../hooks/useGroupPendingCycles';

// new Date('YYYY-MM-DD') interpreta a string como UTC-meia-noite, o que desloca
// a data em 1 dia para trás em fusos negativos (ex.: America/Sao_Paulo) —
// construímos a partir dos componentes locais para evitar isso.
const formatDate = (dateStr: string): string => {
  const [year, month, day] = dateStr.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' });
};

// 'YYYY-MM-DD' → 'DD/MM' sem passar por Date, pelo mesmo motivo.
const formatDayMonth = (dateStr: string): string => {
  const [, month, day] = dateStr.split('-');
  return `${day}/${month}`;
};

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const percentPaid = ({ total, paid }: PendingCycle['totals']): number =>
  total > 0 ? Math.min(100, Math.round((paid / total) * 100)) : 0;

type StatusChip = {
  label: string;
  color: 'default' | 'info' | 'warning' | 'success';
  variant?: 'outlined';
};

/**
 * Na carência o ciclo ainda pode mudar (despesa nova, pagamento desfeito), então
 * "Fecha em" vem antes de "Quitado" — mesma ordem de `ClosingReportPanel`, em que
 * a Prévia vem antes do Quitado. Fora da carência, `awaiting_seal` é o ciclo todo
 * pago que só espera o selo; os demais usam o rótulo compartilhado do status.
 */
const statusChip = ({ cycle, awaiting_seal }: PendingCycle): StatusChip => {
  if (cycle.in_grace) return { label: `Fecha em ${formatDayMonth(cycle.closes_at)}`, color: 'info' };
  if (awaiting_seal) return { label: 'Quitado', color: 'success' };
  return cycleStatusChip[cycle.status];
};

const pendingExpensesLabel = (count: number): string => {
  if (count === 0) return 'Nenhuma despesa pendente';
  return count === 1 ? '1 despesa pendente' : `${count} despesas pendentes`;
};

type PendingCyclesListProps = {
  groupId: string | undefined;
  // Recebe o início do ciclo (YYYY-MM-DD), exatamente como a API o devolveu.
  onViewClosing: (cycleStart: string) => void;
};

/**
 * Aba Acompanhamento de Relatórios: um cartão por ciclo encerrado (ou em
 * carência) que ainda aguarda quitação, do mais recente ao mais antigo, como o
 * backend os entrega. Mostra só o que ajuda a decidir qual abrir — período,
 * status, quanto já foi pago, acertos confirmados e despesas pendentes — e
 * entrega o ciclo escolhido a quem a usa, que abre o Relatório de Fechamento.
 */
const PendingCyclesList: React.FC<PendingCyclesListProps> = ({ groupId, onViewClosing }) => {
  const { cycles, loading, error } = useGroupPendingCycles(groupId);

  if (loading) {
    return (
      <Box display="flex" justifyContent="center" mt={4}>
        <CircularProgress />
      </Box>
    );
  }

  if (error) return <Typography color="error">{error}</Typography>;

  if (cycles.length === 0) {
    return <Typography color="text.secondary">Nenhum ciclo aguardando pagamento.</Typography>;
  }

  return (
    <Stack component="ul" aria-label="Ciclos aguardando pagamento" spacing={1.5} sx={{ listStyle: 'none', m: 0, p: 0 }}>
      {cycles.map(pending => {
        const { cycle, totals } = pending;
        const period = `${formatDate(cycle.start)} – ${formatDate(cycle.end)}`;
        const chip = statusChip(pending);
        const percent = percentPaid(totals);

        return (
          <Card key={cycle.start} component="li" variant="outlined" sx={{ p: 2 }}>
            <Box display="flex" justifyContent="space-between" alignItems="flex-start" gap={1} flexWrap="wrap">
              <Typography fontWeight={700}>{period}</Typography>
              <Chip label={chip.label} color={chip.color} variant={chip.variant} size="small" />
            </Box>

            <Box mt={1.5}>
              <LinearProgress variant="determinate" value={percent} aria-label={`Pago: ${percent}% do total`} />
              <Typography variant="caption" color="text.secondary">
                {`R$ ${formatMoney(totals.paid)} de R$ ${formatMoney(totals.total)} pagos`}
              </Typography>
            </Box>

            <Stack direction="row" columnGap={2} flexWrap="wrap" mt={1}>
              {pending.settlements_total > 0 && (
                <Typography variant="body2">
                  {`Acertos confirmados ${pending.settlements_confirmed} de ${pending.settlements_total}`}
                </Typography>
              )}
              <Typography variant="body2">{pendingExpensesLabel(pending.pending_expenses_count)}</Typography>
            </Stack>

            <Box display="flex" justifyContent="flex-end" mt={1.5}>
              <Button
                size="small"
                aria-label={`Ver fechamento do ciclo ${period}`}
                onClick={() => onViewClosing(cycle.start)}
              >
                Ver fechamento
              </Button>
            </Box>
          </Card>
        );
      })}
    </Stack>
  );
};

export default PendingCyclesList;
