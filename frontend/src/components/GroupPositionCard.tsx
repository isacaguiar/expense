import React, { useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { Alert, Box, Button, Card, Chip, Collapse, Snackbar, Stack, Typography } from '@mui/material';
import ExpandLessIcon from '@mui/icons-material/ExpandLess';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import PhotoCameraOutlinedIcon from '@mui/icons-material/PhotoCameraOutlined';
import UploadFileOutlinedIcon from '@mui/icons-material/UploadFileOutlined';
import { cycleStatusChip } from '../hooks/useGroupCycle';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';
import { useConfirmSettlement } from '../hooks/useConfirmSettlement';
import { usePaymentActions } from '../hooks/usePaymentActions';
import type { OverviewGroup, OverviewPayable } from '../types/overview';
import MyItemsList from './MyItemsList';
import PayableSettlementList from './PayableSettlementList';
import PixPaymentDialog from './PixPaymentDialog';
import ProofUploadDialog from './ProofUploadDialog';
import ReceivableList from './ReceivableList';

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// 'YYYY-MM-DD' → 'DD/MM' sem passar por Date: new Date('YYYY-MM-DD') é UTC e
// desloca um dia em fusos negativos.
const formatDayMonth = (dateStr: string): string => {
  const [, month, day] = dateStr.split('-');
  return `${day}/${month}`;
};

type GroupPositionCardProps = {
  overview: OverviewGroup;
  currentUser: { id: number; name: string };
  /** Depois de qualquer ação com sucesso: a página recarrega o `/me/overview`. */
  onChanged: () => void;
};

type Total = { testId: string; label: string; value: number; color?: string };

/**
 * Cartão de um grupo no Painel: o ciclo em foco, os três totais, o selo "Em dia"
 * ou o aviso de algo em aberto e, ao abrir, o detalhe (você paga, você recebe,
 * seus itens) com as ações. Os números, o `cycles_ago` e as flags `can_*` vêm do
 * servidor; o cartão só exibe e manda de volta o `cycles_ago` que recebeu, em
 * toda ação (comprovante, marcar e desfazer), nunca um valor seu.
 *
 * As ações reaproveitam o que `Payments` usa: `useConfirmSettlement`,
 * `usePaymentActions`, `PixPaymentDialog` e `ProofUploadDialog`. Uma instância
 * dos hooks por cartão, porque eles são por `cyclesAgo`.
 *
 * docs/feature/20261010-painel-financeiro-pessoal/plan.md §2 e §3
 */
const GroupPositionCard: React.FC<GroupPositionCardProps> = ({ overview, currentUser, onChanged }) => {
  const { group, cycle } = overview;

  const [expanded, setExpanded] = useState<boolean>(false);
  const [proofTarget, setProofTarget] = useState<OverviewPayable | null>(null);
  const [proofSuccess, setProofSuccess] = useState<boolean>(false);
  const [payExpenseId, setPayExpenseId] = useState<number | null>(null);
  const [pixTarget, setPixTarget] = useState<OverviewPayable | null>(null);
  const [pixWarning, setPixWarning] = useState<string | null>(null);

  const {
    submitting: sendingProof,
    error: proofError,
    clearError: clearProofError,
    confirm: confirmSettlement
  } = useConfirmSettlement(group.id, () => {
    setProofSuccess(true);
    setProofTarget(null);
    onChanged();
  });

  // Os dois primeiros argumentos só alimentam `canPay`/`canUnpay`, que o painel
  // não usa: a visibilidade dos botões vem de `can_pay`/`can_unpay` do servidor.
  const {
    payingExpenseId,
    payError,
    paySuccess,
    unpaySuccess,
    dismissPayError,
    dismissPaySuccess,
    dismissUnpaySuccess,
    handlePay,
    handleUnpay
  } = usePaymentActions(null, null, cycle.cycles_ago, onChanged);

  const chip =
    cycle.status === 'open' && cycle.in_grace
      ? { label: `Fecha em ${formatDayMonth(cycle.closes_at)}`, color: 'warning' as const, variant: undefined }
      : cycleStatusChip[cycle.status];

  const totals: Total[] = [
    { testId: 'total-owe', label: 'A pagar', value: overview.owe.open, color: overview.owe.open > 0 ? 'error.main' : undefined },
    {
      testId: 'total-receive',
      label: 'A receber',
      value: overview.receive.open,
      color: overview.receive.open > 0 ? 'success.main' : undefined
    },
    { testId: 'total-confirmed', label: 'Já confirmado', value: overview.owe.confirmed },
    // O que os devedores já comprovaram ao usuário (`receive.confirmed`): sem ele, o credor
    // via "Já confirmado R$ 0,00" mesmo com comprovantes enviados. São comprovantes, não
    // dinheiro conferido: o sistema não tem confirmação de recebimento pelo credor.
    { testId: 'total-received', label: 'Já recebido', value: overview.receive.confirmed }
  ];

  // `PayableSettlementList` é a mesma lista de Pagamentos: recebe acertos no
  // formato do `summary`, com o usuário logado como devedor de todos.
  const settlements: SummarySettlement[] = overview.to_pay.map(payable => ({
    from_user_id: currentUser.id,
    to_user_id: payable.to_user.id,
    amount: payable.amount,
    confirmedProofUrl: payable.confirmed_proof_url,
    confirmedAt: payable.confirmed_at
  }));
  const balances: SummaryBalance[] = [
    { user_id: currentUser.id, name: currentUser.name, balance: 0 },
    ...overview.to_pay.map(payable => ({
      user_id: payable.to_user.id,
      name: payable.to_user.name,
      avatarUrl: payable.to_user.avatar_url,
      balance: 0
    }))
  ];
  // A condição de confirmar é do ciclo, igual para todos os acertos.
  const canConfirm = overview.to_pay.some(payable => payable.can_confirm);

  const payableFor = (settlement: SummarySettlement): OverviewPayable | undefined =>
    overview.to_pay.find(payable => payable.to_user.id === settlement.to_user_id);

  const handlePayWithPix = (settlement: SummarySettlement) => {
    const payable = payableFor(settlement);

    // Credor que saiu do grupo: sem e-mail não há como gerar o Pix.
    if (!payable || !payable.to_user.email) {
      setPixWarning('Não foi possível identificar esse credor.');
      return;
    }

    if (!payable.to_user.pix) {
      setPixWarning(`${payable.to_user.name} ainda não cadastrou uma chave Pix.`);
      return;
    }

    setPixTarget(payable);
  };

  const handleSendProof = (settlement: SummarySettlement) => {
    const payable = payableFor(settlement);
    if (!payable) return;

    clearProofError();
    setProofTarget(payable);
  };

  return (
    <Card variant="outlined" sx={{ p: 2 }}>
      <Box display="flex" justifyContent="space-between" alignItems="flex-start" gap={1} flexWrap="wrap">
        <Box minWidth={0}>
          <Typography variant="h6" component="h2" fontWeight={700}>
            {group.name}
          </Typography>
          <Typography variant="caption" color="text.secondary">
            {formatDayMonth(cycle.start)} – {formatDayMonth(cycle.end)}
          </Typography>
        </Box>
        <Stack direction="row" spacing={1} alignItems="center">
          {!overview.has_open && <Chip label="Em dia" color="success" size="small" />}
          <Chip label={chip.label} color={chip.color} variant={chip.variant} size="small" />
        </Stack>
      </Box>

      <Box display="grid" gap={1.5} mt={2} gridTemplateColumns={{ xs: 'repeat(2, 1fr)', sm: 'repeat(4, 1fr)' }}>
        {totals.map(total => (
          <Box key={total.testId} data-testid={total.testId} minWidth={0}>
            <Typography variant="caption" color="text.secondary" component="p">
              {total.label}
            </Typography>
            <Typography variant="subtitle1" fontWeight={700} color={total.color}>
              R$ {formatMoney(total.value)}
            </Typography>
          </Box>
        ))}
      </Box>

      {overview.has_open && (
        <Alert severity={overview.owe.open > 0 ? 'warning' : 'info'} sx={{ mt: 2 }}>
          {overview.owe.open > 0
            ? 'Você tem pagamentos em aberto neste ciclo.'
            : 'Você tem valores a receber em aberto neste ciclo.'}
        </Alert>
      )}

      <Box display="flex" justifyContent="space-between" alignItems="center" mt={1.5} gap={1}>
        <Button size="small" component={RouterLink} to={`/groups/${group.id}/payments`}>
          Ver ciclos
        </Button>
        <Button
          size="small"
          aria-expanded={expanded}
          endIcon={expanded ? <ExpandLessIcon /> : <ExpandMoreIcon />}
          onClick={() => setExpanded(previous => !previous)}
        >
          {expanded ? 'Ocultar detalhes' : 'Ver detalhes'}
        </Button>
      </Box>

      <Collapse in={expanded} unmountOnExit>
        <Stack spacing={2.5} mt={1.5}>
          <Box>
            <Typography variant="subtitle2" component="h3" fontWeight={700} gutterBottom>
              Você paga
            </Typography>
            {overview.to_pay.length === 0 ? (
              <Typography variant="body2" color="text.secondary" py={1}>
                Você não deve nada neste ciclo.
              </Typography>
            ) : (
              <PayableSettlementList
                settlements={settlements}
                balances={balances}
                currentUserId={currentUser.id}
                canConfirm={canConfirm}
                cycleSettled={cycle.settled}
                onPayWithPix={handlePayWithPix}
                onSendProof={handleSendProof}
              />
            )}
          </Box>

          <Box>
            <Typography variant="subtitle2" component="h3" fontWeight={700} gutterBottom>
              Você recebe
            </Typography>
            <ReceivableList receivables={overview.to_receive} />
          </Box>

          <Box>
            <Typography variant="subtitle2" component="h3" fontWeight={700} gutterBottom>
              Seus itens
            </Typography>
            <MyItemsList
              items={overview.my_items}
              busyExpenseId={payingExpenseId}
              onPay={setPayExpenseId}
              onUnpay={handleUnpay}
            />
          </Box>
        </Stack>
      </Collapse>

      {pixTarget && (
        <PixPaymentDialog
          open
          onClose={() => setPixTarget(null)}
          targetEmail={pixTarget.to_user.email ?? ''}
          targetName={pixTarget.to_user.name}
          targetAvatarUrl={pixTarget.to_user.avatar_url}
          amount={pixTarget.amount}
        />
      )}

      <ProofUploadDialog
        open={proofTarget !== null}
        title="Enviar comprovante do Pix"
        description="Anexe o comprovante do seu pagamento via Pix para confirmar."
        selectLabel="Selecionar comprovante"
        icon={<UploadFileOutlinedIcon />}
        busy={sendingProof}
        error={proofError}
        onClose={() => setProofTarget(null)}
        onConfirm={file => {
          if (proofTarget) confirmSettlement(proofTarget.to_user.id, file, cycle.cycles_ago);
        }}
      />

      <ProofUploadDialog
        open={payExpenseId !== null}
        title="Confirmar pagamento"
        description="Anexe uma foto do comprovante para confirmar o pagamento."
        selectLabel="Selecionar foto"
        icon={<PhotoCameraOutlinedIcon />}
        onClose={() => setPayExpenseId(null)}
        onConfirm={file => {
          if (payExpenseId !== null) handlePay(payExpenseId, file);
          setPayExpenseId(null);
        }}
      />

      <Snackbar
        open={proofSuccess}
        autoHideDuration={4000}
        onClose={() => setProofSuccess(false)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={() => setProofSuccess(false)} severity="success" variant="filled">
          Comprovante enviado.
        </Alert>
      </Snackbar>

      <Snackbar
        open={paySuccess}
        autoHideDuration={4000}
        onClose={dismissPaySuccess}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={dismissPaySuccess} severity="success" variant="filled">
          Pagamento confirmado.
        </Alert>
      </Snackbar>

      <Snackbar
        open={unpaySuccess}
        autoHideDuration={4000}
        onClose={dismissUnpaySuccess}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={dismissUnpaySuccess} severity="success" variant="filled">
          Pagamento desfeito.
        </Alert>
      </Snackbar>

      <Snackbar
        open={payError !== null}
        autoHideDuration={6000}
        onClose={dismissPayError}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={dismissPayError} severity="error" variant="filled">
          {payError}
        </Alert>
      </Snackbar>

      <Snackbar
        open={pixWarning !== null}
        autoHideDuration={5000}
        onClose={() => setPixWarning(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={() => setPixWarning(null)} severity="info" variant="filled">
          {pixWarning}
        </Alert>
      </Snackbar>
    </Card>
  );
};

export default GroupPositionCard;
