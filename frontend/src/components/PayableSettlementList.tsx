import React from 'react';
import { Box, Button, Card, Chip, Stack, Typography } from '@mui/material';
import ArrowForwardIcon from '@mui/icons-material/ArrowForward';
import QrCode2OutlinedIcon from '@mui/icons-material/QrCode2Outlined';
import UploadFileOutlinedIcon from '@mui/icons-material/UploadFileOutlined';
import CheckCircleOutlineIcon from '@mui/icons-material/CheckCircleOutline';
import CallReceivedOutlinedIcon from '@mui/icons-material/CallReceivedOutlined';
import UserAvatar from './UserAvatar';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';
import { isSettlementConfirmed } from '../utils/cycleOrdering';

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
  // Texto de lista vazia. O padrão serve a Pagamentos sem abas e ao Painel; as abas
  // "Valores a pagar"/"Valores pagos" passam o delas.
  emptyMessage?: string;
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
  onSendProof,
  emptyMessage = 'Ninguém deve nada a ninguém neste ciclo.'
}) => {
  const nameById = new Map(balances.map(balance => [balance.user_id, balance.name]));
  const nameFor = (userId: number): string => nameById.get(userId) ?? 'Desconhecido';
  const avatarById = new Map(balances.map(balance => [balance.user_id, balance.avatarUrl]));
  const avatarFor = (userId: number): string | null | undefined => avatarById.get(userId);

  if (settlements.length === 0) {
    return (
      <Typography color="text.secondary" textAlign="center" py={4}>
        {emptyMessage}
      </Typography>
    );
  }

  return (
    <Stack spacing={1.5}>
      {settlements.map((settlement, index) => {
        const isDebtor = currentUserId !== null && currentUserId === settlement.from_user_id;
        // "Pago" é o acerto ter `confirmedAt`; a URL do comprovante não decide (expira no
        // snapshot e pode faltar). docs/feature/20261026-organizacao-despesas-pagamentos/plan.md §7.
        const confirmed = isSettlementConfirmed(settlement);
        // Valor que o usuário logado tem a receber: marcado com texto, sem botões de devedor.
        const isReceiver = currentUserId !== null && currentUserId === settlement.to_user_id;
        // A nota só faz sentido junto de um comprovante enviado.
        const note = confirmed ? settlement.confirmedNote : null;

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

            {note && (
              <Typography
                data-testid="settlement-note"
                variant="caption"
                color="text.secondary"
                display="block"
                mt={1}
                sx={{ overflowWrap: 'anywhere' }}
              >
                {note}
              </Typography>
            )}

            <Box display="flex" alignItems="center" justifyContent="flex-end" flexWrap="wrap" gap={1} mt={1.5}>
              {isReceiver && (
                <Chip
                  icon={<CallReceivedOutlinedIcon fontSize="small" />}
                  label={confirmed ? 'Pagou a você' : 'Deve pagar a você'}
                  color="info"
                  size="small"
                  variant="outlined"
                />
              )}

              {confirmed &&
                (settlement.confirmedProofUrl ? (
                  <Chip
                    icon={<CheckCircleOutlineIcon fontSize="small" />}
                    label="Comprovante enviado"
                    color="success"
                    size="small"
                    variant="outlined"
                    component="a"
                    href={settlement.confirmedProofUrl}
                    target="_blank"
                    rel="noreferrer"
                    clickable
                  />
                ) : (
                  // Confirmado sem URL (o arquivo foi removido): o estado aparece, sem link quebrado.
                  <Chip
                    icon={<CheckCircleOutlineIcon fontSize="small" />}
                    label="Comprovante enviado"
                    color="success"
                    size="small"
                    variant="outlined"
                  />
                ))}

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
