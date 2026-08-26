import React from 'react';
import { Avatar, Box, Card, CardActionArea, Chip, Stack, Typography } from '@mui/material';
import ArrowForwardIcon from '@mui/icons-material/ArrowForward';
import QrCode2OutlinedIcon from '@mui/icons-material/QrCode2Outlined';
import { getInitials } from '../layouts/group/getInitials';
import { brandColors } from '../theme/brandColors';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

type PayableSettlementListProps = {
  settlements: SummarySettlement[];
  balances: SummaryBalance[];
  onSelect: (settlement: SummarySettlement) => void;
};

/**
 * Variação clicável de components/SettlementList.tsx (que continua só
 * exibindo, sem ação, nas outras 2 telas que o usa) — ver
 * docs/feature/20260825-pagamentos-grid-pix/plan.md §2. Mesmo dado
 * (`settlements`/`balances`), cada card abre o pagamento via Pix do credor
 * ao ser clicado; `Payments.tsx` decide se o credor tem chave cadastrada.
 */
const PayableSettlementList: React.FC<PayableSettlementListProps> = ({ settlements, balances, onSelect }) => {
  const nameById = new Map(balances.map(balance => [balance.user_id, balance.name]));
  const nameFor = (userId: number): string => nameById.get(userId) ?? 'Desconhecido';

  if (settlements.length === 0) {
    return (
      <Typography color="text.secondary" textAlign="center" py={4}>
        Ninguém deve nada a ninguém neste ciclo.
      </Typography>
    );
  }

  return (
    <Stack spacing={1.5}>
      {settlements.map((settlement, index) => (
        <Card key={`${settlement.from_user_id}-${settlement.to_user_id}-${index}`} variant="outlined">
          <CardActionArea
            onClick={() => onSelect(settlement)}
            sx={{ display: 'flex', alignItems: 'center', gap: 1.5, p: 2 }}
          >
            <Avatar sx={{ bgcolor: brandColors.primaryLight, color: brandColors.primary, fontSize: '0.85rem' }}>
              {getInitials(nameFor(settlement.from_user_id))}
            </Avatar>

            <Box flexGrow={1} minWidth={0}>
              <Typography variant="body2">
                <strong>{nameFor(settlement.from_user_id)}</strong> deve pagar{' '}
                <Typography component="span" variant="body2" fontWeight={700} color="error.main">
                  R$ {formatMoney(settlement.amount)}
                </Typography>{' '}
                a <strong>{nameFor(settlement.to_user_id)}</strong>
              </Typography>
            </Box>

            <ArrowForwardIcon color="action" fontSize="small" sx={{ flexShrink: 0 }} />

            <Avatar sx={{ bgcolor: brandColors.primaryLight, color: brandColors.primary, fontSize: '0.85rem' }}>
              {getInitials(nameFor(settlement.to_user_id))}
            </Avatar>

            <Chip
              icon={<QrCode2OutlinedIcon fontSize="small" />}
              label="Pix"
              size="small"
              variant="outlined"
              sx={{ flexShrink: 0, ml: 0.5 }}
            />
          </CardActionArea>
        </Card>
      ))}
    </Stack>
  );
};

export default PayableSettlementList;
