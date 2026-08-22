import React from 'react';
import {
  Box,
  Card,
  CardContent,
  Typography,
  CircularProgress,
  IconButton,
  Grid,
  Paper,
  List,
  ListItem,
  ListItemIcon,
  ListItemText,
  Chip
} from '@mui/material';
import { useParams } from 'react-router-dom';
import ArrowBackIosNewIcon from '@mui/icons-material/ArrowBackIosNew';
import ArrowForwardIosIcon from '@mui/icons-material/ArrowForwardIos';
import AutorenewOutlinedIcon from '@mui/icons-material/AutorenewOutlined';
import ReceiptOutlinedIcon from '@mui/icons-material/ReceiptOutlined';
import { useGroupCycle, cycleStatusChip } from '../hooks/useGroupCycle';
import BalanceCards from '../components/BalanceCards';

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

const GroupSummary: React.FC = () => {
  const { id: groupId } = useParams<{ id: string }>();
  const { summary, loading, error, goToPreviousCycle, goToNextCycle } = useGroupCycle(groupId);

  return (
    <>
      {loading ? (
        <Box display="flex" justifyContent="center" mt={4}>
          <CircularProgress />
        </Box>
      ) : error ? (
        <Typography color="error">{error}</Typography>
      ) : summary ? (
        <>
          <Box
            display="flex"
            alignItems="center"
            justifyContent="center"
            mb={3}
            gap={1}
          >
            <IconButton
              onClick={goToPreviousCycle}
              aria-label="Ciclo anterior"
            >
              <ArrowBackIosNewIcon />
            </IconButton>
            <Typography variant="h6" textTransform="capitalize">
              {formatDate(summary.cycle.start)} – {formatDate(summary.cycle.end)}
            </Typography>
            <IconButton
              onClick={goToNextCycle}
              aria-label="Próximo ciclo"
            >
              <ArrowForwardIosIcon />
            </IconButton>
          </Box>

          <Box display="flex" justifyContent="center" mb={3}>
            <Chip
              label={cycleStatusChip[summary.cycle.status].label}
              color={cycleStatusChip[summary.cycle.status].color}
              variant={cycleStatusChip[summary.cycle.status].variant}
              size="small"
            />
          </Box>

          <Grid container spacing={2} mb={3}>
            <Grid size={{ xs: 12, md: 4 }}>
              <Card>
                <CardContent>
                  <Typography variant="body2" color="text.secondary">
                    Total de despesas
                  </Typography>
                  <Typography variant="h5">R$ {formatMoney(summary.totals.total)}</Typography>
                  <Typography variant="caption" color="text.secondary" textTransform="capitalize">
                    {formatDate(summary.cycle.start)} – {formatDate(summary.cycle.end)}
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
                    R$ {formatMoney(summary.totals.paid)}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {percentOf(summary.totals.paid, summary.totals.total)}% do total
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
                    R$ {formatMoney(summary.totals.pending)}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {percentOf(summary.totals.pending, summary.totals.total)}% do total
                  </Typography>
                </CardContent>
              </Card>
            </Grid>
          </Grid>

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
                  <ListItem key={expense.id} divider>
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

          <Typography variant="h6" gutterBottom>
            Saldos por pessoa
          </Typography>
          <BalanceCards balances={summary.balances} />
        </>
      ) : null}
    </>
  );
};

export default GroupSummary;
