import React from 'react';
import { Box, Chip, List, ListItem, ListItemIcon, ListItemText, Paper, Typography } from '@mui/material';
import AutorenewOutlinedIcon from '@mui/icons-material/AutorenewOutlined';
import ReceiptOutlinedIcon from '@mui/icons-material/ReceiptOutlined';
import type { SummaryExpense } from '../hooks/useGroupCycle';
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

type CycleExpenseListProps = {
  expenses: SummaryExpense[];
};

/**
 * "Despesas do ciclo": a lista de despesas de um ciclo, extraída de
 * `CycleDetailPanel.tsx` para ser reaproveitada pelo Relatório de Fechamento.
 * Mostra a descrição do pagamento (`paymentNote`) de uma despesa paga, como
 * texto puro e só quando houver: ciclo selado antes da feature
 * `comprovante-ao-marcar-paga` não traz o campo (snapshot congelado).
 */
const CycleExpenseList: React.FC<CycleExpenseListProps> = ({ expenses }) => (
  <>
    <Typography variant="h6" gutterBottom>
      Despesas do ciclo
    </Typography>
    {expenses.length === 0 ? (
      <Typography color="text.secondary" mb={3}>
        Nenhuma despesa neste ciclo.
      </Typography>
    ) : (
      <Paper elevation={3} sx={{ mb: 3 }}>
        <List disablePadding>
          {expenses.map(expense => (
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
                  <>
                    {`${formatDate(expense.date)} · Pago por ${expense.payerName ?? '-'} · ` +
                      `Dividido entre ${expense.participants.length} pessoa${expense.participants.length === 1 ? '' : 's'}`}
                    {expense.paid && expense.paymentNote && (
                      <Box component="span" display="block" sx={{ overflowWrap: 'anywhere' }}>
                        Descrição do pagamento: {expense.paymentNote}
                      </Box>
                    )}
                  </>
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
  </>
);

export default CycleExpenseList;
