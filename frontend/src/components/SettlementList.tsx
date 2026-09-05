import React from 'react';
import { Box, Card, CardContent, Stack, Typography } from '@mui/material';
import ArrowForwardIcon from '@mui/icons-material/ArrowForward';
import UserAvatar from './UserAvatar';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

type SettlementListProps = {
  settlements: SummarySettlement[];
  balances: SummaryBalance[];
};

/**
 * Liquidação par-a-par do ciclo: quem deve pagar quanto a quem, já líquido
 * por par (backend neta os dois sentidos antes de expor `settlements`).
 * Resolve nome/avatar via `balances` (mesmo `summary`, sem chamada de API
 * nova) porque `settlements` só traz `user_id`.
 */
const SettlementList: React.FC<SettlementListProps> = ({ settlements, balances }) => {
  const nameById = new Map(balances.map(balance => [balance.user_id, balance.name]));
  const nameFor = (userId: number): string => nameById.get(userId) ?? 'Desconhecido';
  const avatarById = new Map(balances.map(balance => [balance.user_id, balance.avatarUrl]));
  const avatarFor = (userId: number): string | null | undefined => avatarById.get(userId);

  return (
    <Stack spacing={1.5}>
      {settlements.map((settlement, index) => (
        <Card key={`${settlement.from_user_id}-${settlement.to_user_id}-${index}`} variant="outlined">
          <CardContent sx={{ display: 'flex', alignItems: 'center', gap: 1.5, '&:last-child': { pb: 2 } }}>
            <UserAvatar name={nameFor(settlement.from_user_id)} avatarUrl={avatarFor(settlement.from_user_id)} />
            <Box flexGrow={1}>
              <Typography variant="body2">
                <strong>{nameFor(settlement.from_user_id)}</strong> deve pagar{' '}
                <Typography component="span" variant="body2" color="error.main">
                  R$ {formatMoney(settlement.amount)}
                </Typography>{' '}
                a <strong>{nameFor(settlement.to_user_id)}</strong>
              </Typography>
            </Box>
            <ArrowForwardIcon color="action" fontSize="small" />
            <UserAvatar name={nameFor(settlement.to_user_id)} avatarUrl={avatarFor(settlement.to_user_id)} />
          </CardContent>
        </Card>
      ))}
    </Stack>
  );
};

export default SettlementList;
