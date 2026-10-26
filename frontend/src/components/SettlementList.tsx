import React from 'react';
import { Box, Card, CardContent, Chip, Stack, Typography } from '@mui/material';
import ArrowForwardIcon from '@mui/icons-material/ArrowForward';
import CheckCircleOutlineIcon from '@mui/icons-material/CheckCircleOutline';
import UserAvatar from './UserAvatar';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// `confirmedAt` vem do backend em ISO 8601 com fuso; o dia mostrado é o de quem lê.
const formatConfirmedDay = (iso: string): string =>
  new Date(iso).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });

type SettlementListProps = {
  settlements: SummarySettlement[];
  balances: SummaryBalance[];
  // Mostra, em cada acerto, se o comprovante já foi confirmado (e quando) e a
  // descrição que o devedor deu. Desligado por padrão: o painel lateral do
  // ciclo continua só listando quem deve a quem.
  showConfirmation?: boolean;
};

/**
 * Liquidação par-a-par do ciclo: quem deve pagar quanto a quem, já líquido
 * por par (backend neta os dois sentidos antes de expor `settlements`).
 * Resolve nome/avatar via `balances` (mesmo `summary`, sem chamada de API
 * nova) porque `settlements` só traz `user_id`.
 *
 * Com `showConfirmation` (Relatório de Fechamento) cada acerto ganha o chip
 * "Confirmado em dd/mm" ou "Aguardando comprovante" e, se o devedor descreveu o
 * pagamento ao enviar o comprovante, essa descrição como texto puro.
 */
const SettlementList: React.FC<SettlementListProps> = ({ settlements, balances, showConfirmation = false }) => {
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
              {showConfirmation && (
                <Box mt={0.5}>
                  {settlement.confirmedAt ? (
                    <Chip
                      icon={<CheckCircleOutlineIcon fontSize="small" />}
                      label={`Confirmado em ${formatConfirmedDay(settlement.confirmedAt)}`}
                      color="success"
                      size="small"
                      variant="outlined"
                    />
                  ) : (
                    <Chip label="Aguardando comprovante" color="warning" size="small" variant="outlined" />
                  )}
                  {settlement.confirmedAt && settlement.confirmedNote && (
                    <Typography
                      variant="caption"
                      color="text.secondary"
                      display="block"
                      mt={0.5}
                      sx={{ overflowWrap: 'anywhere' }}
                    >
                      Descrição do pagamento: {settlement.confirmedNote}
                    </Typography>
                  )}
                </Box>
              )}
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
