import React, { useState } from 'react';
import { Card, CardContent, Tabs, Tab, Typography } from '@mui/material';
import BalanceCards from './BalanceCards';
import SettlementList from './SettlementList';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';

type SummarySidePanelProps = {
  balances: SummaryBalance[];
  settlements: SummarySettlement[];
};

type PanelTab = 'balance' | 'settlement';

/**
 * Painel lateral da tela de Resumo do grupo (`GroupSummary`): concentra
 * "Saldos por pessoa" e "Quem paga a quem" em duas abas de um único card,
 * no lugar dos dois blocos empilhados que existiam antes desta feature
 * (`docs/feature/20260822-reestruturacao-resumo/`).
 */
const SummarySidePanel: React.FC<SummarySidePanelProps> = ({ balances, settlements }) => {
  const [tab, setTab] = useState<PanelTab>('balance');

  return (
    <Card variant="outlined">
      <CardContent>
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
