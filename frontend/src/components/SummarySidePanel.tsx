import React, { useState } from 'react';
import { Box, Card, CardContent, Chip, Tabs, Tab, Typography } from '@mui/material';
import UpdateIcon from '@mui/icons-material/Update';
import PaidOutlinedIcon from '@mui/icons-material/PaidOutlined';
import BalanceCards from './BalanceCards';
import SettlementList from './SettlementList';
import type { CycleStatus, SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';

type SummarySidePanelProps = {
  balances: SummaryBalance[];
  settlements: SummarySettlement[];
  cycleStatus: CycleStatus;
};

type PanelTab = 'balance' | 'settlement';

/**
 * Painel lateral da tela de Resumo do grupo (`GroupSummary`): concentra
 * "Saldos por pessoa" e "Quem paga a quem" em duas abas de um único card,
 * no lugar dos dois blocos empilhados que existiam antes desta feature
 * (`docs/feature/20260822-reestruturacao-resumo/`).
 */
const SummarySidePanel: React.FC<SummarySidePanelProps> = ({ balances, settlements, cycleStatus }) => {
  const [tab, setTab] = useState<PanelTab>('balance');

  // 'open'/'future': GroupSummary.tsx expõe status calculado ao vivo a cada
  // request (ExpenseController::computeCycleSummary), muda até o ciclo
  // fechar — 'closed'/'closed_manually' já são snapshot imutável.
  const isVolatile = cycleStatus === 'open' || cycleStatus === 'future';

  return (
    <Card variant="outlined">
      <CardContent>
        <Box display="flex" justifyContent="flex-end" mb={1}>
          {isVolatile ? (
            <Chip
              size="small"
              variant="outlined"
              color="info"
              icon={<UpdateIcon fontSize="small" />}
              label="Prévia"
            />
          ) : (
            <Chip
              size="small"
              variant="outlined"
              color="success"
              icon={<PaidOutlinedIcon fontSize="small" />}
              label="Definitivo"
            />
          )}
        </Box>

        <Tabs value={tab} onChange={(_, value: PanelTab) => setTab(value)} sx={{ mb: 2 }}>
          <Tab label="Saldo" value="balance" />
          <Tab label="À pagar" value="settlement" />
        </Tabs>

        {tab === 'balance' ? (
          <BalanceCards balances={balances} />
        ) : settlements.length > 0 ? (
          <SettlementList settlements={settlements} balances={balances} />
        ) : (
          <Typography color="text.secondary">
            Nenhuma pendência entre os membros neste ciclo.
          </Typography>
        )}
      </CardContent>
    </Card>
  );
};

export default SummarySidePanel;
