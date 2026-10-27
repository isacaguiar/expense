import React, { useMemo } from 'react';
import { Box, Tab, Tabs } from '@mui/material';
import PayableSettlementList from './PayableSettlementList';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';
import { orderSettlementsForViewer, splitSettlementsByConfirmation } from '../utils/cycleOrdering';

export type SettlementsTab = 'toPay' | 'paid';

type PaymentSettlementsPanelProps = {
  // `null`/`undefined`: snapshot anterior à migration de `settlements` — vira lista vazia.
  settlements: SummarySettlement[] | null | undefined;
  balances: SummaryBalance[];
  currentUserId: number | null;
  canConfirm: boolean;
  cycleSettled: boolean;
  // Controlado: `Payments` guarda a aba, porque a página troca o conteúdo por um
  // spinner a cada busca de ciclo e um estado daqui se perderia.
  tab: SettlementsTab;
  onTabChange: (tab: SettlementsTab) => void;
  onPayWithPix: (settlement: SummarySettlement) => void;
  onSendProof: (settlement: SummarySettlement) => void;
};

const TAB_ID = (tab: SettlementsTab) => `settlements-tab-${tab}`;
const PANEL_ID = 'settlements-tabpanel';

const EMPTY_MESSAGE: Record<SettlementsTab, string> = {
  toPay: 'Nenhum valor a pagar neste ciclo.',
  paid: 'Nenhum valor pago neste ciclo ainda.'
};
const NO_SETTLEMENTS_MESSAGE = 'Ninguém deve nada a ninguém neste ciclo.';

/**
 * Acertos do ciclo em duas abas, "Valores a pagar" e "Valores pagos", repartidas só
 * por `confirmedAt` (nunca por `Quota.paid` da despesa nem pela URL do comprovante).
 * Cada aba mostra as saídas do usuário logado primeiro e, depois, os demais devedores
 * pelo total da própria aba. Só repartição e ordem: valores, Pix e envio de
 * comprovante são os de `PayableSettlementList`.
 * docs/feature/20261026-organizacao-despesas-pagamentos/plan.md §8.
 */
const PaymentSettlementsPanel: React.FC<PaymentSettlementsPanelProps> = ({
  settlements,
  balances,
  currentUserId,
  canConfirm,
  cycleSettled,
  tab,
  onTabChange,
  onPayWithPix,
  onSendProof
}) => {
  const { toPay, paid } = useMemo(() => splitSettlementsByConfirmation(settlements), [settlements]);

  const visible = useMemo(() => {
    const nameById = new Map(balances.map(balance => [balance.user_id, balance.name]));
    const nameFor = (userId: number): string => nameById.get(userId) ?? 'Desconhecido';
    return orderSettlementsForViewer(tab === 'toPay' ? toPay : paid, currentUserId, nameFor);
  }, [balances, currentUserId, paid, tab, toPay]);

  const hasAny = toPay.length + paid.length > 0;

  return (
    <Box>
      <Tabs
        value={tab}
        onChange={(_, value: SettlementsTab) => onTabChange(value)}
        variant="fullWidth"
        selectionFollowsFocus
        aria-label="Acertos entre membros"
        sx={{ mb: 2 }}
      >
        <Tab
          value="toPay"
          id={TAB_ID('toPay')}
          aria-controls={PANEL_ID}
          label={`Valores a pagar (${toPay.length})`}
        />
        <Tab value="paid" id={TAB_ID('paid')} aria-controls={PANEL_ID} label={`Valores pagos (${paid.length})`} />
      </Tabs>

      <Box role="tabpanel" id={PANEL_ID} aria-labelledby={TAB_ID(tab)}>
        <PayableSettlementList
          settlements={visible}
          balances={balances}
          currentUserId={currentUserId}
          canConfirm={canConfirm}
          cycleSettled={cycleSettled}
          onPayWithPix={onPayWithPix}
          onSendProof={onSendProof}
          emptyMessage={hasAny ? EMPTY_MESSAGE[tab] : NO_SETTLEMENTS_MESSAGE}
        />
      </Box>
    </Box>
  );
};

export default PaymentSettlementsPanel;
