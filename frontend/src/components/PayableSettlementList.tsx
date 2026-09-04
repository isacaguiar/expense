import React from 'react';
import { Box, Button, Card, Chip, Stack, Typography } from '@mui/material';
import ArrowForwardIcon from '@mui/icons-material/ArrowForward';
import QrCode2OutlinedIcon from '@mui/icons-material/QrCode2Outlined';
import UploadFileOutlinedIcon from '@mui/icons-material/UploadFileOutlined';
import CheckCircleOutlineIcon from '@mui/icons-material/CheckCircleOutline';
import UserAvatar from './UserAvatar';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

type PayableSettlementListProps = {
  settlements: SummarySettlement[];
  balances: SummaryBalance[];
  currentUserId: number | null;
  canConfirm: boolean;
  cycleSettled: boolean;
  onPayWithPix: (settlement: SummarySettlement) => void;
  onSendProof: (settlement: SummarySettlement) => void;
};

/**
 * "Valores a pagar" — a mesma liquidação par-a-par de components/SettlementList.tsx
 * (que continua só exibindo, sem ação, nas outras 2 telas que o usa), mas com
 * ações do lado do DEVEDOR: pagar via Pix (docs/feature/concluidas/202608/20260825-pagamentos-grid-pix/plan.md §2)
 * e, separadamente, enviar comprovante do próprio pagamento pra confirmar o
 * settlement (§6) — conceito distinto de confirmar uma despesa (isso
 * continua 100% com o credor, em Despesas/Payments, inalterado). Só o
 * devedor daquele par (`currentUserId === from_user_id`) vê os botões de
 * ação; qualquer membro vê o status de confirmação.
 *
 * `canConfirm` = o backend aceita o comprovante do devedor agora (competência
 * fechada e não selada — ver docs/feature/concluidas/202609/20260902-pagamento-ciclo-fechado
 * §2.2). Enquanto `false`, o devedor vê só um aviso no lugar dos botões.
 */
const PayableSettlementList: React.FC<PayableSettlementListProps> = ({
  settlements,
  balances,
  currentUserId,
  canConfirm,
  cycleSettled,
  onPayWithPix,
  onSendProof
}) => {
  const nameById = new Map(balances.map(balance => [balance.user_id, balance.name]));
  const nameFor = (userId: number): string => nameById.get(userId) ?? 'Desconhecido';
  const avatarById = new Map(balances.map(balance => [balance.user_id, balance.avatarUrl]));
  const avatarFor = (userId: number): string | null | undefined => avatarById.get(userId);

  if (settlements.length === 0) {
    return (
      <Typography color="text.secondary" textAlign="center" py={4}>
        Ninguém deve nada a ninguém neste ciclo.
      </Typography>
    );
  }

  return (
    <Stack spacing={1.5}>
      {settlements.map((settlement, index) => {
        const isDebtor = currentUserId !== null && currentUserId === settlement.from_user_id;
        const confirmed = Boolean(settlement.confirmedProofUrl);

        return (
          <Card key={`${settlement.from_user_id}-${settlement.to_user_id}-${index}`} variant="outlined" sx={{ p: 2 }}>
            <Box display="flex" alignItems="center" gap={1.5}>
              <UserAvatar name={nameFor(settlement.from_user_id)} avatarUrl={avatarFor(settlement.from_user_id)} />

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

              <UserAvatar name={nameFor(settlement.to_user_id)} avatarUrl={avatarFor(settlement.to_user_id)} />
            </Box>

            <Box display="flex" alignItems="center" justifyContent="flex-end" flexWrap="wrap" gap={1} mt={1.5}>
              {confirmed && (
                <Chip
                  icon={<CheckCircleOutlineIcon fontSize="small" />}
                  label="Comprovante enviado"
                  color="success"
                  size="small"
                  variant="outlined"
                  component="a"
                  href={settlement.confirmedProofUrl ?? undefined}
                  target="_blank"
                  rel="noreferrer"
                  clickable
                />
              )}

              {isDebtor && canConfirm && (
                <>
                  <Button
                    size="small"
                    variant="outlined"
                    startIcon={<QrCode2OutlinedIcon />}
                    onClick={() => onPayWithPix(settlement)}
                  >
                    Pagar com Pix
                  </Button>
                  <Button
                    size="small"
                    variant="contained"
                    startIcon={<UploadFileOutlinedIcon />}
                    onClick={() => onSendProof(settlement)}
                  >
                    {confirmed ? 'Reenviar comprovante' : 'Enviar comprovante'}
                  </Button>
                </>
              )}

              {isDebtor && !canConfirm && !confirmed && (
                <Typography variant="caption" color="text.secondary">
                  {cycleSettled ? 'Ciclo encerrado.' : 'Disponível após o fechamento do ciclo.'}
                </Typography>
              )}
            </Box>
          </Card>
        );
      })}
    </Stack>
  );
};

export default PayableSettlementList;
