import React from 'react';
import { Box, Card, CardContent, Stack, Typography } from '@mui/material';
import UserAvatar from './UserAvatar';
import type { SummaryBalance } from '../hooks/useGroupCycle';

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

type BalanceCardsProps = {
  balances: SummaryBalance[];
};

/**
 * Card individual por participante (avatar, nome, valor líquido e rótulo
 * "a receber"/"a pagar") — layout de referência em
 * `assets/images/tela-despesas.png`. Reaproveitado pela Home do grupo
 * (`GroupSummary`) e pela tela de despesas (`ExpenseManager`).
 */
const BalanceCards: React.FC<BalanceCardsProps> = ({ balances }) => (
  <Stack spacing={1.5}>
    {balances.map(balance => (
      <Card key={balance.user_id} variant="outlined">
        <CardContent sx={{ display: 'flex', alignItems: 'center', gap: 1.5, '&:last-child': { pb: 2 } }}>
          <UserAvatar name={balance.name} avatarUrl={balance.avatarUrl} />
          <Box flexGrow={1}>
            <Typography variant="body1">{balance.name}</Typography>
            <Typography
              variant="body2"
              color={balance.balance > 0 ? 'success.main' : balance.balance < 0 ? 'error.main' : 'text.secondary'}
            >
              R$ {formatMoney(Math.abs(balance.balance))}
              {balance.balance > 0 ? ' a receber' : balance.balance < 0 ? ' a pagar' : ''}
            </Typography>
          </Box>
        </CardContent>
      </Card>
    ))}
  </Stack>
);

export default BalanceCards;
