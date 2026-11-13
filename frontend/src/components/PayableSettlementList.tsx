import React, { useState } from 'react';
import {
  Box,
  Button,
  Card,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle,
  Stack,
  Typography
} from '@mui/material';
import ArrowForwardIcon from '@mui/icons-material/ArrowForward';
import QrCode2OutlinedIcon from '@mui/icons-material/QrCode2Outlined';
import UploadFileOutlinedIcon from '@mui/icons-material/UploadFileOutlined';
import CheckCircleOutlineIcon from '@mui/icons-material/CheckCircleOutline';
import CallReceivedOutlinedIcon from '@mui/icons-material/CallReceivedOutlined';
import UserAvatar from './UserAvatar';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';
import { isSettlementConfirmed } from '../utils/cycleOrdering';

const NO_ONE: readonly number[] = [];

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
  // Ids de quem o usuário logado paga (feature 20261109-responsavel-do-devedor): nos acertos deles ele
  // vê o selo "Você paga por {nome}" e os mesmos botões do devedor.
  payForUserIds?: readonly number[];
  // Texto de lista vazia. O padrão serve a Pagamentos sem abas e ao Painel; as abas
  // "Valores a pagar"/"Valores pagos" passam o delas.
  emptyMessage?: string;
  // Aceitar, recusar e excluir o comprovante enviado por quem paga por outra pessoa (feature
  // 20261110-quem-paga-se-declara-e-credor-recusa, plan §11). Opcionais: sem eles (o Painel) a lista só
  // mostra o estado, sem botões de decisão.
  onAccept?: (settlement: SummarySettlement) => void;
  onRefuse?: (settlement: SummarySettlement) => void;
  onDelete?: (settlement: SummarySettlement) => void;
  // Uma decisão em andamento: desabilita os botões dela.
  decisionBusy?: boolean;
  // Arranjo do topo de cada acerto (feature 20261112-ajustes-de-texto-participantes-e-pagamentos). `'sentence'`
  // (o padrão, o Painel) é a frase "X deve pagar R$ N a Y" de sempre; `'route'` (as abas de Pagamentos) põe os
  // avatares nas pontas, "pagador → credor" numa linha e o valor abaixo, sem "deve pagar" nem "a", e ganha os
  // `data-testid` `settlement-card`, `-payer`, `-payee` e `-amount`. Nota, selos e botões são iguais nos dois.
  rowLayout?: 'sentence' | 'route';
};

/**
 * "Valores a pagar" — a mesma liquidação par-a-par de components/SettlementList.tsx
 * (que continua só exibindo, sem ação, nas outras 2 telas que o usa), mas com
 * ações do lado do DEVEDOR: pagar via Pix (docs/feature/concluidas/202608/20260825-pagamentos-grid-pix/plan.md §2)
 * e, separadamente, enviar comprovante do próprio pagamento pra confirmar o
 * settlement (§6) — conceito distinto de confirmar uma despesa (isso
 * continua 100% com o credor, em Despesas/Payments, inalterado). Só o
 * devedor daquele par (`currentUserId === from_user_id`) vê os botões de
 * ação; qualquer membro vê o status de confirmação. Quem paga por um dependente
 * (`payForUserIds`) ganha os mesmos botões nos acertos dele, menos naqueles que ele
 * próprio recebe.
 *
 * `canConfirm` = o backend aceita o comprovante do devedor agora (competência
 * fechada e não selada — ver docs/feature/concluidas/202609/20260902-pagamento-ciclo-fechado
 * §2.2). Enquanto `false`, o devedor vê só um aviso no lugar dos botões.
 *
 * O comprovante enviado por quem paga por outra pessoa passa pelo aceite do credor (feature
 * 20261110-quem-paga-se-declara-e-credor-recusa): o credor vê Aceitar e Recusar; o devedor e quem enviou veem
 * Excluir (o backend só os deixa excluir, não os outros que pagam pelo devedor); o aceito é definitivo e perde
 * os botões de reenvio.
 */
const PayableSettlementList: React.FC<PayableSettlementListProps> = ({
  settlements,
  balances,
  currentUserId,
  canConfirm,
  cycleSettled,
  onPayWithPix,
  onSendProof,
  payForUserIds = NO_ONE,
  emptyMessage = 'Ninguém deve nada a ninguém neste ciclo.',
  onAccept,
  onRefuse,
  onDelete,
  decisionBusy = false,
  rowLayout = 'sentence'
}) => {
  const route = rowLayout === 'route';
  // O comprovante que a pessoa está prestes a excluir: a exclusão é definitiva e pede confirmação.
  const [deleting, setDeleting] = useState<SummarySettlement | null>(null);

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
        // snapshot e pode faltar). docs/feature/concluidas/202610/20261026-organizacao-despesas-pagamentos/plan.md §7.
        const confirmed = isSettlementConfirmed(settlement);
        // Valor que o usuário logado tem a receber: marcado com texto, sem botões de devedor.
        const isReceiver = currentUserId !== null && currentUserId === settlement.to_user_id;
        // Quem o usuário paga: os mesmos botões do devedor, exceto quando o usuário é quem recebe o
        // acerto (R14), que já é o credor dele.
        const canPayFor =
          currentUserId !== null && !isDebtor && !isReceiver && payForUserIds.includes(settlement.from_user_id);
        const canAct = isDebtor || canPayFor;
        // A nota só faz sentido junto de um comprovante enviado.
        const note = confirmed ? settlement.confirmedNote : null;

        // Estado do comprovante. Respostas de antes da feature não trazem `confirmationStatus`: um comprovante
        // conta como enviado, e sem comprovante não há estado.
        const status = settlement.confirmationStatus ?? (confirmed ? 'sent' : null);
        const awaiting = status === 'awaiting_acceptance';
        const refused = status === 'refused';
        const accepted = status === 'accepted';
        // Quem enviou o comprovante (só vem preenchido quando difere do devedor).
        const isSender = currentUserId !== null && settlement.confirmedBy?.id === currentUserId;
        // As ações só valem com o ciclo fechado e não encerrado (`canConfirm`), a janela do backend.
        const canDecide = isReceiver && awaiting && canConfirm && onAccept !== undefined && onRefuse !== undefined;
        const canDelete = (isDebtor || isSender) && (awaiting || refused) && canConfirm && onDelete !== undefined;
        // O aceito é definitivo: nem Pix nem reenvio.
        const canResend = canAct && canConfirm && !accepted;

        return (
          <Card
            key={`${settlement.from_user_id}-${settlement.to_user_id}-${index}`}
            variant="outlined"
            sx={{ p: 2 }}
            data-testid={route ? 'settlement-card' : undefined}
          >
            {route ? (
              <Box display="flex" alignItems="center" gap={1.5}>
                <UserAvatar name={nameFor(settlement.from_user_id)} avatarUrl={avatarFor(settlement.from_user_id)} />

                <Box flexGrow={1} minWidth={0}>
                  <Box display="flex" flexWrap="wrap" alignItems="center" columnGap={0.75}>
                    {/* O pagador e a seta não se separam ao quebrar a linha: a seta nunca começa uma linha. */}
                    <Box display="inline-flex" alignItems="center" columnGap={0.75} minWidth={0}>
                      <Typography
                        variant="body2"
                        fontWeight={700}
                        data-testid="settlement-payer"
                        sx={{ overflowWrap: 'anywhere' }}
                      >
                        {nameFor(settlement.from_user_id)}
                      </Typography>
                      <ArrowForwardIcon color="action" fontSize="small" sx={{ flexShrink: 0 }} />
                    </Box>
                    <Typography
                      variant="body2"
                      fontWeight={700}
                      data-testid="settlement-payee"
                      sx={{ overflowWrap: 'anywhere' }}
                    >
                      {nameFor(settlement.to_user_id)}
                    </Typography>
                  </Box>
                  <Typography variant="body2" fontWeight={700} color="error.main" data-testid="settlement-amount">
                    R$ {formatMoney(settlement.amount)}
                  </Typography>
                </Box>

                <UserAvatar name={nameFor(settlement.to_user_id)} avatarUrl={avatarFor(settlement.to_user_id)} />
              </Box>
            ) : (
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
            )}

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
                  label={confirmed && !awaiting ? 'Pagou a você' : 'Deve pagar a você'}
                  color="info"
                  size="small"
                  variant="outlined"
                />
              )}

              {canPayFor && (
                <Chip label={`Você paga por ${nameFor(settlement.from_user_id)}`} color="primary" size="small" variant="outlined" />
              )}

              {awaiting && (
                <Chip
                  label={isReceiver ? 'Aguardando seu aceite' : 'Aguardando aceite do credor'}
                  color="warning"
                  size="small"
                  variant="outlined"
                />
              )}

              {refused && (
                <Chip
                  label={isReceiver ? 'Você recusou o comprovante' : `Recusado por ${nameFor(settlement.to_user_id)}`}
                  color="error"
                  size="small"
                  variant="outlined"
                />
              )}

              {accepted && <Chip label="Aceito pelo credor" color="success" size="small" />}

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

              {confirmed && settlement.confirmedBy && (
                <Typography variant="caption" color="text.secondary">
                  {`Enviado por ${settlement.confirmedBy.name}`}
                </Typography>
              )}

              {canDecide && (
                <>
                  <Button
                    size="small"
                    variant="contained"
                    color="success"
                    disabled={decisionBusy}
                    onClick={() => onAccept?.(settlement)}
                  >
                    Aceitar
                  </Button>
                  <Button
                    size="small"
                    variant="outlined"
                    color="error"
                    disabled={decisionBusy}
                    onClick={() => onRefuse?.(settlement)}
                  >
                    Recusar
                  </Button>
                </>
              )}

              {canDelete && (
                <Button size="small" color="error" disabled={decisionBusy} onClick={() => setDeleting(settlement)}>
                  Excluir
                </Button>
              )}

              {canResend && (
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

              {canAct && !canConfirm && !confirmed && (
                <Typography variant="caption" color="text.secondary">
                  {cycleSettled ? 'Ciclo encerrado.' : 'Disponível após o fechamento do ciclo.'}
                </Typography>
              )}
            </Box>
          </Card>
        );
      })}

      <Dialog open={deleting !== null} onClose={() => setDeleting(null)}>
        <DialogTitle>Excluir comprovante?</DialogTitle>
        <DialogContent>
          <DialogContentText>
            {`O comprovante enviado por ${deleting?.confirmedBy?.name ?? 'quem enviou'} será excluído de vez e não poderá ser recuperado. Quem deve poderá enviar outro.`}
          </DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDeleting(null)}>Cancelar</Button>
          <Button
            color="error"
            onClick={() => {
              if (deleting) onDelete?.(deleting);
              setDeleting(null);
            }}
          >
            Excluir
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
};

export default PayableSettlementList;
