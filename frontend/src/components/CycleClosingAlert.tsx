import React from 'react';
import { Alert } from '@mui/material';
import type { Summary } from '../hooks/useGroupCycle';

// new Date('YYYY-MM-DD') é UTC-meia-noite e desloca 1 dia em fusos negativos —
// construímos a partir dos componentes locais (mesmo cuidado de GroupSummary).
const parseLocalDate = (dateStr: string): Date => {
  const [year, month, day] = dateStr.split('-').map(Number);
  return new Date(year, month - 1, day);
};

const startOfToday = (): Date => {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth(), now.getDate());
};

const formatDayMonth = (dateStr: string): string =>
  parseLocalDate(dateStr).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });

/**
 * Quem ainda deve um acerto: devedor de algum par de `settlements` sem
 * confirmação do comprovante (`confirmedAt`) — o mesmo critério par-a-par com
 * que o backend decide se o ciclo está quitado (`CycleSummaryService::
 * summaryIsFullySettled`). Não dá para usar `balances`: é o rateio líquido da
 * competência e não muda quando uma despesa é paga ou um acerto é confirmado
 * (e é 0 numa dívida circular, ou positivo para quem ainda deve a um terceiro).
 * O nome vem de `balances`; cada devedor aparece uma vez, na ordem dos acertos.
 */
const pendingDebtorNames = ({ balances, settlements }: Summary): string[] => {
  const nameById = new Map(balances.map(balance => [balance.user_id, balance.name]));
  const debtorIds = new Set(
    settlements.filter(settlement => !settlement.confirmedAt).map(settlement => settlement.from_user_id)
  );

  return [...debtorIds].map(userId => nameById.get(userId) ?? 'Desconhecido');
};

type CycleClosingAlertProps = { summary: Summary };

/**
 * Aviso de fechamento de ciclo, exibido na Home do grupo e na tela de
 * Despesas — ver docs/feature/concluidas/202609/20260902-pagamento-ciclo-fechado §2.10.
 *
 * - PRÉ-fechamento: enquanto o ciclo ainda está `open` e hoje já passou da
 *   fronteira (`cycle.end`) mas não chegou à data de corte (`cycle.closes_at`,
 *   = fronteira + carência de 5 dias) — janela em que o ciclo segue editável.
 * - PÓS-fechamento: depois que o ciclo fechou (`closed`/`closed_manually`),
 *   enquanto não está selado e ainda há acerto sem confirmação. Com todos os
 *   acertos confirmados e só despesas por marcar como pagas, o aviso some: não
 *   há mais devedor a cobrar (docs/bugfix/20261024-alerta-ciclo-fechado-apos-pagamento).
 *
 * As duas condições são disjuntas por `status`; fora delas não renderiza nada.
 */
const CycleClosingAlert: React.FC<CycleClosingAlertProps> = ({ summary }) => {
  const { cycle } = summary;

  const today = startOfToday().getTime();
  const end = cycle.end ? parseLocalDate(cycle.end).getTime() : NaN;
  const closesAt = cycle.closes_at ? parseLocalDate(cycle.closes_at).getTime() : NaN;

  if (cycle.status === 'open' && !Number.isNaN(end) && !Number.isNaN(closesAt) && today >= end && today < closesAt) {
    return (
      <Alert severity="info" sx={{ mb: 2 }}>
        Este ciclo fecha em {formatDayMonth(cycle.closes_at)}. Registre e acerte as despesas
        até lá.
      </Alert>
    );
  }

  const cycleClosed = cycle.status === 'closed' || cycle.status === 'closed_manually';
  const debtors = pendingDebtorNames(summary);

  if (cycleClosed && !cycle.settled && debtors.length > 0) {
    return (
      <Alert severity="warning" sx={{ mb: 2 }}>
        O ciclo fechou e ainda falta acertar: {debtors.join(', ')}.
      </Alert>
    );
  }

  return null;
};

export default CycleClosingAlert;
