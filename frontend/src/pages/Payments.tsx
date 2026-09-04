import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import {
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle,
  Grid,
  IconButton,
  Snackbar,
  Alert,
  Stack,
  Typography
} from '@mui/material';
import { useParams } from 'react-router-dom';
import ArrowBackIosNewIcon from '@mui/icons-material/ArrowBackIosNew';
import ArrowForwardIosIcon from '@mui/icons-material/ArrowForwardIos';
import PhotoCameraOutlinedIcon from '@mui/icons-material/PhotoCameraOutlined';
import UploadFileOutlinedIcon from '@mui/icons-material/UploadFileOutlined';
import UndoIcon from '@mui/icons-material/Undo';
import { API_BASE_URL } from '../config';
import { useGroupCycle, cycleStatusChip, SummaryExpense, SummarySettlement } from '../hooks/useGroupCycle';
import { usePaymentActions } from '../hooks/usePaymentActions';
import PayableSettlementList from '../components/PayableSettlementList';
import PixPaymentDialog from '../components/PixPaymentDialog';
import DespesasThemeScope from '../theme/DespesasThemeScope';

const formatDate = (dateStr: string): string => {
  const [year, month, day] = dateStr.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' });
};

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

type GroupMemberPix = { id: number; name: string; email: string; pix: string | null };

const Payments: React.FC = () => {
  const { id: groupId } = useParams<{ id: string }>();
  const { summary, loading, error, cyclesAgo, goToPreviousCycle, goToNextCycle, reload } = useGroupCycle(groupId);

  const [currentUserId, setCurrentUserId] = useState<number | null>(null);

  useEffect(() => {
    const token = localStorage.getItem('accessToken');
    axios
      .get<{ id: number }>(`${API_BASE_URL}/api/me`, { headers: { Authorization: token ? `Bearer ${token}` : '' } })
      .then(res => setCurrentUserId(res.data.id))
      .catch(err => console.error('Erro ao carregar usuário autenticado:', err));
  }, []);

  // Membros do grupo com email/chave Pix — usado só pra resolver, ao clicar
  // num valor a pagar, se o credor tem Pix cadastrado e qual o e-mail pra
  // chamar /pix/generate (docs/feature/concluidas/202608/20260825-pagamentos-grid-pix/plan.md §1).
  const [members, setMembers] = useState<GroupMemberPix[]>([]);

  useEffect(() => {
    if (!groupId) return;

    const token = localStorage.getItem('accessToken');
    axios
      .get<GroupMemberPix[]>(`${API_BASE_URL}/api/groups/${groupId}/members`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(res => setMembers(res.data))
      .catch(err => console.error('Erro ao carregar membros do grupo:', err));
  }, [groupId]);

  const {
    payingExpenseId,
    payError,
    paySuccess,
    unpaySuccess,
    dismissPayError,
    dismissPaySuccess,
    dismissUnpaySuccess,
    canPay,
    canUnpay,
    handlePay,
    handleUnpay
  } = usePaymentActions(currentUserId, summary, cyclesAgo, reload);

  // Diálogo "Confirmar pagamento": a foto é obrigatória aqui (do lado do
  // cliente) mesmo com o campo sendo opcional na API — ver plan.md §2/§6.
  const [confirmExpenseId, setConfirmExpenseId] = useState<number | null>(null);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const openConfirmDialog = (expenseId: number) => {
    setConfirmExpenseId(expenseId);
    setSelectedFile(null);
  };

  const closeConfirmDialog = () => {
    setConfirmExpenseId(null);
    setSelectedFile(null);
  };

  const confirmPayment = () => {
    if (confirmExpenseId === null || !selectedFile) return;
    handlePay(confirmExpenseId, selectedFile);
    closeConfirmDialog();
  };

  // Valor a pagar por pessoa: ao clicar num settlement, abre o Pix do credor
  // se ele tiver chave cadastrada, senão avisa (docs/feature/concluidas/202608/20260825-pagamentos-grid-pix/plan.md §2).
  const [pixTarget, setPixTarget] = useState<{ settlement: SummarySettlement; member: GroupMemberPix } | null>(null);
  const [noPixWarning, setNoPixWarning] = useState<string | null>(null);

  const handleSelectSettlement = (settlement: SummarySettlement) => {
    const creditor = members.find(m => m.id === settlement.to_user_id);

    if (!creditor) {
      setNoPixWarning('Não foi possível identificar esse credor.');
      return;
    }

    if (!creditor.pix) {
      setNoPixWarning(`${creditor.name} ainda não cadastrou uma chave Pix.`);
      return;
    }

    setPixTarget({ settlement, member: creditor });
  };

  // Enviar comprovante do pagamento do settlement (devedor) — conceito
  // distinto de confirmar uma despesa (isso continua com o credor, acima).
  // Ver docs/feature/concluidas/202608/20260825-pagamentos-grid-pix/plan.md §6.
  const [confirmSettlementTarget, setConfirmSettlementTarget] = useState<SummarySettlement | null>(null);
  const [selectedSettlementFile, setSelectedSettlementFile] = useState<File | null>(null);
  const [confirmingSettlement, setConfirmingSettlement] = useState<boolean>(false);
  const [settlementConfirmError, setSettlementConfirmError] = useState<string | null>(null);
  const [settlementConfirmSuccess, setSettlementConfirmSuccess] = useState<boolean>(false);

  const openConfirmSettlementDialog = (settlement: SummarySettlement) => {
    setConfirmSettlementTarget(settlement);
    setSelectedSettlementFile(null);
    setSettlementConfirmError(null);
  };

  const closeConfirmSettlementDialog = () => {
    setConfirmSettlementTarget(null);
    setSelectedSettlementFile(null);
  };

  const confirmSettlementPayment = () => {
    if (!confirmSettlementTarget || !selectedSettlementFile || !groupId) return;

    setConfirmingSettlement(true);
    setSettlementConfirmError(null);

    const token = localStorage.getItem('accessToken');
    const form = new FormData();
    form.append('to_user_id', String(confirmSettlementTarget.to_user_id));
    form.append('comprovante', selectedSettlementFile);

    axios
      .post(`${API_BASE_URL}/api/groups/${groupId}/settlements/confirm`, form, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(() => {
        setSettlementConfirmSuccess(true);
        closeConfirmSettlementDialog();
        reload();
      })
      .catch(err => {
        console.error('Erro ao confirmar pagamento do settlement:', err);
        setSettlementConfirmError(err.response?.data?.error ?? 'Falha ao enviar o comprovante.');
      })
      .finally(() => setConfirmingSettlement(false));
  };

  const expenses = summary?.expenses ?? [];

  return (
    <DespesasThemeScope>
      {loading ? (
        <Box display="flex" justifyContent="center" mt={4}>
          <CircularProgress />
        </Box>
      ) : error ? (
        <Typography color="error">{error}</Typography>
      ) : summary ? (
        <>
          <Box display="flex" alignItems="center" justifyContent="center" mb={1} gap={1}>
            <IconButton onClick={goToPreviousCycle} aria-label="Ciclo anterior">
              <ArrowBackIosNewIcon />
            </IconButton>
            <Typography
              variant="h6"
              textTransform="capitalize"
              sx={{ flexGrow: 1, minWidth: 0, textAlign: 'center', fontSize: { xs: '1rem', md: '1.25rem' } }}
            >
              {formatDate(summary.cycle.start)} – {formatDate(summary.cycle.end)}
            </Typography>
            <IconButton onClick={goToNextCycle} aria-label="Próximo ciclo">
              <ArrowForwardIosIcon />
            </IconButton>
          </Box>

          <Box display="flex" justifyContent="center" mb={3}>
            <Chip
              label={cycleStatusChip[summary.cycle.status].label}
              color={cycleStatusChip[summary.cycle.status].color}
              variant={cycleStatusChip[summary.cycle.status].variant}
              size="small"
            />
          </Box>

          <Grid container spacing={3}>
            <Grid size={{ xs: 12, md: 7 }}>
              <Typography variant="subtitle1" fontWeight={700} gutterBottom>
                Despesas do ciclo
              </Typography>

              {expenses.length === 0 ? (
                <Typography color="text.secondary">Nenhuma despesa nesta competência.</Typography>
              ) : (
                <Stack spacing={2}>
                  {expenses.map((exp: SummaryExpense) => (
                    <Card key={exp.id} variant="outlined">
                      <CardContent>
                        <Box display="flex" justifyContent="space-between" alignItems="flex-start" gap={1}>
                          <Typography variant="subtitle1" fontWeight="bold">
                            {exp.description}
                          </Typography>
                          <Chip
                            label={exp.paid ? 'Paga' : 'Pendente'}
                            color={exp.paid ? 'success' : 'warning'}
                            size="small"
                          />
                        </Box>

                        <Typography variant="body2" color="text.secondary">
                          Credor: {exp.payerName ?? '-'}
                        </Typography>
                        <Typography variant="body2" color="text.secondary">
                          Valor Total: R$ {formatMoney(exp.value)}
                        </Typography>
                        <Typography variant="body2" color="text.secondary">
                          Valor por pessoa: R$ {formatMoney(exp.valuePerPerson)}
                        </Typography>
                        <Typography variant="body2" color="text.secondary">
                          Pagadores: {exp.participants.join(', ')}
                        </Typography>

                        {exp.paid && exp.paymentProofUrl && (
                          <Typography variant="body2" mt={1}>
                            <a href={exp.paymentProofUrl} target="_blank" rel="noreferrer">
                              Ver comprovante
                            </a>
                          </Typography>
                        )}

                        <Box display="flex" justifyContent="flex-end" mt={2} gap={1}>
                          {canPay(exp) && (
                            <Button
                              size="small"
                              variant="contained"
                              startIcon={<PhotoCameraOutlinedIcon />}
                              disabled={payingExpenseId === exp.id}
                              onClick={() => openConfirmDialog(exp.id)}
                            >
                              Confirmar pagamento
                            </Button>
                          )}
                          {canUnpay(exp) && (
                            <Button
                              size="small"
                              variant="outlined"
                              startIcon={<UndoIcon />}
                              disabled={payingExpenseId === exp.id}
                              onClick={() => handleUnpay(exp.id)}
                            >
                              Desfazer pagamento
                            </Button>
                          )}
                        </Box>
                      </CardContent>
                    </Card>
                  ))}
                </Stack>
              )}
            </Grid>

            <Grid size={{ xs: 12, md: 5 }}>
              <Typography variant="subtitle1" fontWeight={700} gutterBottom>
                Valores a pagar
              </Typography>

              <PayableSettlementList
                settlements={summary.settlements}
                balances={summary.balances}
                currentUserId={currentUserId}
                canConfirm={
                  (summary.cycle.status === 'closed' || summary.cycle.status === 'closed_manually') &&
                  !summary.cycle.settled
                }
                cycleSettled={summary.cycle.settled}
                onPayWithPix={handleSelectSettlement}
                onSendProof={openConfirmSettlementDialog}
              />
            </Grid>
          </Grid>
        </>
      ) : null}

      <Dialog open={confirmExpenseId !== null} onClose={closeConfirmDialog} fullWidth maxWidth="xs">
        <DialogTitle>Confirmar pagamento</DialogTitle>
        <DialogContent>
          <DialogContentText mb={2}>
            Anexe uma foto do comprovante para confirmar o pagamento.
          </DialogContentText>
          <Button variant="outlined" component="label" fullWidth startIcon={<PhotoCameraOutlinedIcon />}>
            {selectedFile ? selectedFile.name : 'Selecionar foto'}
            <input
              ref={fileInputRef}
              type="file"
              accept="image/*"
              capture="environment"
              hidden
              onChange={e => setSelectedFile(e.target.files?.[0] ?? null)}
            />
          </Button>
        </DialogContent>
        <DialogActions>
          <Button onClick={closeConfirmDialog}>Cancelar</Button>
          <Button variant="contained" disabled={!selectedFile} onClick={confirmPayment}>
            Confirmar
          </Button>
        </DialogActions>
      </Dialog>

      {pixTarget && (
        <PixPaymentDialog
          open={pixTarget !== null}
          onClose={() => setPixTarget(null)}
          targetEmail={pixTarget.member.email}
          targetName={pixTarget.member.name}
          amount={pixTarget.settlement.amount}
        />
      )}

      <Dialog open={confirmSettlementTarget !== null} onClose={closeConfirmSettlementDialog} fullWidth maxWidth="xs">
        <DialogTitle>Enviar comprovante do Pix</DialogTitle>
        <DialogContent>
          <DialogContentText mb={2}>
            Anexe o comprovante do seu pagamento via Pix para confirmar.
          </DialogContentText>
          <Button variant="outlined" component="label" fullWidth startIcon={<UploadFileOutlinedIcon />}>
            {selectedSettlementFile ? selectedSettlementFile.name : 'Selecionar comprovante'}
            <input
              type="file"
              accept="image/*"
              capture="environment"
              hidden
              onChange={e => setSelectedSettlementFile(e.target.files?.[0] ?? null)}
            />
          </Button>
          {settlementConfirmError && (
            <Alert severity="error" sx={{ mt: 2 }}>
              {settlementConfirmError}
            </Alert>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={closeConfirmSettlementDialog} disabled={confirmingSettlement}>
            Cancelar
          </Button>
          <Button
            variant="contained"
            disabled={!selectedSettlementFile || confirmingSettlement}
            onClick={confirmSettlementPayment}
          >
            Confirmar
          </Button>
        </DialogActions>
      </Dialog>

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
        open={settlementConfirmSuccess}
        autoHideDuration={4000}
        onClose={() => setSettlementConfirmSuccess(false)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={() => setSettlementConfirmSuccess(false)} severity="success" variant="filled">
          Comprovante enviado.
        </Alert>
      </Snackbar>

      <Snackbar
        open={noPixWarning !== null}
        autoHideDuration={5000}
        onClose={() => setNoPixWarning(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={() => setNoPixWarning(null)} severity="info" variant="filled">
          {noPixWarning}
        </Alert>
      </Snackbar>
    </DespesasThemeScope>
  );
};

export default Payments;
