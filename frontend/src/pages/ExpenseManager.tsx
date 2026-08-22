import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { API_BASE_URL } from '../config';
import {
  Avatar,
  Box,
  Button,
  Chip,
  Typography,
  CircularProgress,
  Alert,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Grid,
  IconButton,
  Link as MuiLink,
  Paper,
  Snackbar,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TextField,
  ToggleButton,
  ToggleButtonGroup,
  Tooltip
} from '@mui/material';
import { Link, useNavigate, useParams } from 'react-router-dom';
import ArrowBackIosNewIcon from '@mui/icons-material/ArrowBackIosNew';
import ArrowForwardIosIcon from '@mui/icons-material/ArrowForwardIos';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import EditOutlinedIcon from '@mui/icons-material/EditOutlined';
import CheckCircleOutlineIcon from '@mui/icons-material/CheckCircleOutline';
import UndoIcon from '@mui/icons-material/Undo';
import AutorenewOutlinedIcon from '@mui/icons-material/AutorenewOutlined';
import ReceiptOutlinedIcon from '@mui/icons-material/ReceiptOutlined';
import { useGroupCycle, SummaryExpense } from '../hooks/useGroupCycle';
import SummarySidePanel from '../components/SummarySidePanel';
import { getInitials } from '../layouts/group/getInitials';
import { brandColors } from '../theme/brandColors';

// exp.date vem como 'YYYY-MM-DD'; new Date(string) interpreta como meia-noite UTC,
// que em fusos negativos (ex.: America/Sao_Paulo) cai no dia anterior ao converter
// pra hora local. Construir a partir dos componentes evita isso.
const formatDate = (dateStr: string): string => {
  const [year, month, day] = dateStr.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('pt-BR');
};

// Rótulo curto (dia + mês abreviado) pro cabeçalho de competência — mesmo
// formato usado em GroupSummary, pra não ter duas convenções de data na UI.
const formatCycleBoundary = (dateStr: string): string => {
  const [year, month, day] = dateStr.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' });
};

const ExpenseManager: React.FC = () => {
  const { id: groupId } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const { summary, loading, error, cyclesAgo, goToPreviousCycle, goToNextCycle, reload } = useGroupCycle(groupId);
  const expenses = summary?.expenses ?? [];

  const [currentUserId, setCurrentUserId] = useState<number | null>(null);

  useEffect(() => {
    const token = localStorage.getItem('accessToken');
    axios
      .get<{ id: number }>(`${API_BASE_URL}/api/me`, { headers: { Authorization: token ? `Bearer ${token}` : '' } })
      .then(res => setCurrentUserId(res.data.id))
      .catch(err => console.error('Erro ao carregar usuário autenticado:', err));
  }, []);

  // Busca e filtro por tipo — client-side, sobre os dados da competência já carregada
  const [search, setSearch] = useState<string>('');
  const [typeFilter, setTypeFilter] = useState<'all' | 'fixed' | 'variable'>('all');

  // Diálogo de remoção de despesa Fixa
  const [removeExpenseId, setRemoveExpenseId] = useState<number | null>(null);
  const [removeSuccess, setRemoveSuccess] = useState<boolean>(false);

  // Diálogo de exclusão de despesa variável
  const [deleteExpenseId, setDeleteExpenseId] = useState<number | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [deleting, setDeleting] = useState<boolean>(false);
  const [deleteSuccess, setDeleteSuccess] = useState<boolean>(false);

  // Marcar como paga / desfazer pagamento
  const [payingExpenseId, setPayingExpenseId] = useState<number | null>(null);
  const [payError, setPayError] = useState<string | null>(null);
  const [paySuccess, setPaySuccess] = useState<boolean>(false);
  const [unpaySuccess, setUnpaySuccess] = useState<boolean>(false);

  const handlePay = (expenseId: number) => {
    setPayingExpenseId(expenseId);
    setPayError(null);

    const token = localStorage.getItem('accessToken');

    axios
      .post(`${API_BASE_URL}/api/expenses/${expenseId}/pay`, null, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(() => {
        setPaySuccess(true);
        reload();
      })
      .catch(err => {
        console.error('Erro ao marcar despesa como paga:', err);
        setPayError(err.response?.data?.error ?? 'Falha ao marcar despesa como paga.');
      })
      .finally(() => setPayingExpenseId(null));
  };

  const handleUnpay = (expenseId: number) => {
    setPayingExpenseId(expenseId);
    setPayError(null);

    const token = localStorage.getItem('accessToken');

    axios
      .post(`${API_BASE_URL}/api/expenses/${expenseId}/unpay`, null, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(() => {
        setUnpaySuccess(true);
        reload();
      })
      .catch(err => {
        console.error('Erro ao desfazer pagamento da despesa:', err);
        setPayError(err.response?.data?.error ?? 'Falha ao desfazer pagamento.');
      })
      .finally(() => setPayingExpenseId(null));
  };

  // Fechar/reabrir a competência vigente
  const [closingMonth, setClosingMonth] = useState<boolean>(false);
  const [reopeningMonth, setReopeningMonth] = useState<boolean>(false);
  const [closeReopenError, setCloseReopenError] = useState<string | null>(null);
  const [closeSuccess, setCloseSuccess] = useState<boolean>(false);
  const [reopenSuccess, setReopenSuccess] = useState<boolean>(false);

  const handleCloseMonth = () => {
    if (!groupId) return;

    setClosingMonth(true);
    setCloseReopenError(null);

    const token = localStorage.getItem('accessToken');

    axios
      .post(`${API_BASE_URL}/api/groups/${groupId}/expenses/close`, null, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(() => {
        setCloseSuccess(true);
        reload();
      })
      .catch(err => {
        console.error('Erro ao fechar a competência:', err);
        setCloseReopenError(err.response?.data?.error ?? 'Falha ao fechar a competência.');
      })
      .finally(() => setClosingMonth(false));
  };

  const handleReopenMonth = () => {
    if (!groupId) return;

    setReopeningMonth(true);
    setCloseReopenError(null);

    const token = localStorage.getItem('accessToken');

    axios
      .post(`${API_BASE_URL}/api/groups/${groupId}/expenses/reopen`, null, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(() => {
        setReopenSuccess(true);
        reload();
      })
      .catch(err => {
        console.error('Erro ao reabrir a competência:', err);
        setCloseReopenError(err.response?.data?.error ?? 'Falha ao reabrir a competência.');
      })
      .finally(() => setReopeningMonth(false));
  };

  const stopFixedRecurrence = (cutoffYear: number, cutoffMonth: number) => {
    if (removeExpenseId === null) return;

    const token = localStorage.getItem('accessToken');

    axios
      .post(
        `${API_BASE_URL}/api/expenses/${removeExpenseId}/stop-recurrence`,
        { year: cutoffYear, month: cutoffMonth },
        { headers: { Authorization: token ? `Bearer ${token}` : '' } }
      )
      .then(() => {
        setRemoveExpenseId(null);
        setRemoveSuccess(true);
        reload();
      })
      .catch(err => {
        console.error('Erro ao remover recorrência da despesa fixa:', err);
        alert('Falha ao remover despesa fixa.');
      });
  };

  const handleRemoveFromThisMonth = () => {
    if (!summary) return;

    const [year, month] = summary.cycle.start.split('-').map(Number);
    stopFixedRecurrence(year, month);
  };

  const handleRemoveFromNextMonth = () => {
    if (!summary) return;

    const [year, month] = summary.cycle.start.split('-').map(Number);
    const next = new Date(year, month - 1, 1);
    next.setMonth(next.getMonth() + 1);
    stopFixedRecurrence(next.getFullYear(), next.getMonth() + 1);
  };

  const removeExpense = expenses.find(exp => exp.id === removeExpenseId) ?? null;
  const deleteExpense = expenses.find(exp => exp.id === deleteExpenseId) ?? null;

  const closeDeleteDialog = () => {
    setDeleteExpenseId(null);
    setDeleteError(null);
  };

  const confirmDelete = () => {
    if (deleteExpenseId === null) return;

    setDeleting(true);
    setDeleteError(null);

    const token = localStorage.getItem('accessToken');

    axios
      .delete(`${API_BASE_URL}/api/expenses/${deleteExpenseId}`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(() => {
        setDeleteExpenseId(null);
        setDeleteSuccess(true);
        reload();
      })
      .catch(err => {
        console.error('Erro ao excluir despesa:', err);
        // O backend valida as regras de domínio (competência fechada, despesa
        // paga) — o cliente só exibe o motivo que a API devolveu.
        setDeleteError(err.response?.data?.error ?? 'Falha ao excluir despesa.');
      })
      .finally(() => setDeleting(false));
  };

  const filteredExpenses = expenses
    .filter(exp => exp.description.toLowerCase().includes(search.toLowerCase()))
    .filter(exp => {
      if (typeFilter === 'fixed') return Boolean(exp.isFixed);
      if (typeFilter === 'variable') return !exp.isFixed;
      return true;
    });

  // Ações de edição/exclusão/pagamento só existem enquanto a competência
  // selecionada está aberta — competências fechadas (automática ou
  // manualmente) e futuras são refletidas aqui, não decididas à parte: a API
  // recusaria a ação de qualquer forma, isto só evita mostrar um botão que
  // levaria a um erro.
  const cycleIsOpen = summary?.cycle.status === 'open';

  const isOwner = (exp: SummaryExpense) =>
    currentUserId !== null && (currentUserId === exp.userCreatorId || currentUserId === exp.userPayerId);

  const isCreditor = (exp: SummaryExpense) => currentUserId !== null && currentUserId === exp.userPayerId;

  const canEdit = (exp: SummaryExpense) => cycleIsOpen && isOwner(exp);
  const canDelete = (exp: SummaryExpense) => cycleIsOpen && !exp.isFixed && !exp.paid && isOwner(exp);
  const canPay = (exp: SummaryExpense) => cycleIsOpen && !exp.paid && isCreditor(exp);
  const canUnpay = (exp: SummaryExpense) => cycleIsOpen && exp.paid && isCreditor(exp);

  return (
    <>
      {/* Cabeçalho */}
      <Box
        display="flex"
        justifyContent="flex-end"
        alignItems="center"
        mb={3}
      >
        <Button
          variant="contained"
          color="primary"
          startIcon={<AddIcon />}
          onClick={() => navigate(`/groups/${groupId}/expenses/new`)}
        >
          Nova Despesa
        </Button>
      </Box>

      {/* Seletor de competência */}
      <Box
        display="flex"
        alignItems="center"
        justifyContent="center"
        mb={3}
        gap={1}
      >
        <IconButton onClick={goToPreviousCycle} aria-label="Competência anterior">
          <ArrowBackIosNewIcon />
        </IconButton>
        <Typography variant="h6" textTransform="capitalize">
          {summary ? `${formatCycleBoundary(summary.cycle.start)} – ${formatCycleBoundary(summary.cycle.end)}` : ''}
        </Typography>
        <IconButton onClick={goToNextCycle} aria-label="Próxima competência">
          <ArrowForwardIosIcon />
        </IconButton>
      </Box>

      {/* Fechar/reabrir — só faz sentido na competência vigente (cyclesAgo=0):
          close()/reopen() sempre operam sobre "agora", nunca sobre a
          competência navegada via as setas acima. */}
      {summary && cyclesAgo === 0 && (summary.cycle.status === 'open' || summary.cycle.status === 'closed_manually') && (
        <Box display="flex" flexDirection="column" alignItems="center" mb={3} gap={1}>
          {summary.cycle.status === 'open' ? (
            <Button variant="outlined" onClick={handleCloseMonth} disabled={closingMonth}>
              Fechar mês
            </Button>
          ) : (
            <Button variant="outlined" onClick={handleReopenMonth} disabled={reopeningMonth}>
              Reabrir mês
            </Button>
          )}
          {closeReopenError && (
            <Alert severity="error" sx={{ maxWidth: 480 }}>
              {closeReopenError}
            </Alert>
          )}
        </Box>
      )}

      {/* Grid principal: listagem à esquerda, saldo por pessoa à direita */}
      {loading ? (
        <Box display="flex" justifyContent="center" mt={4}>
          <CircularProgress />
        </Box>
      ) : error ? (
        <Typography color="error">{error}</Typography>
      ) : (
        <Grid container spacing={3}>
          <Grid size={{ xs: 12, sm: 12, lg: 8 }}>
            {/* Busca e filtro por tipo */}
            <Box display="flex" gap={2} mb={3} flexWrap="wrap" alignItems="center">
              <TextField
                label="Buscar despesa"
                value={search}
                onChange={e => setSearch(e.target.value)}
                size="small"
                sx={{ minWidth: 240 }}
              />
              <ToggleButtonGroup
                value={typeFilter}
                exclusive
                size="small"
                onChange={(_, value) => value && setTypeFilter(value)}
              >
                <ToggleButton value="all">Todas</ToggleButton>
                <ToggleButton value="fixed">Fixas</ToggleButton>
                <ToggleButton value="variable">Variáveis</ToggleButton>
              </ToggleButtonGroup>
            </Box>

            {expenses.length === 0 ? (
              <Typography color="text.secondary">Nenhuma despesa encontrada para esta competência.</Typography>
            ) : filteredExpenses.length === 0 ? (
              <Typography color="text.secondary">Nenhuma despesa encontrada para esse filtro.</Typography>
            ) : (
              <TableContainer component={Paper} elevation={3}>
                <Table>
                  <TableHead>
                    <TableRow>
                      <TableCell>Tipo</TableCell>
                      <TableCell>Despesa</TableCell>
                      <TableCell>Valor</TableCell>
                      <TableCell>Credor</TableCell>
                      <TableCell>Status</TableCell>
                      <TableCell align="right">Ações</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {filteredExpenses.map(exp => (
                      <TableRow key={exp.id} hover>
                        <TableCell>
                          <Tooltip title={exp.isFixed ? 'Fixa' : 'Variável'}>
                            {exp.isFixed ? (
                              <AutorenewOutlinedIcon color="action" fontSize="small" />
                            ) : (
                              <ReceiptOutlinedIcon color="action" fontSize="small" />
                            )}
                          </Tooltip>
                        </TableCell>
                        <TableCell>
                          <MuiLink component={Link} to={`/groups/${groupId}/expenses/${exp.id}`} underline="hover">
                            {exp.description}
                          </MuiLink>
                        </TableCell>
                        <TableCell>
                          R$ {exp.value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                        </TableCell>
                        <TableCell>
                          <Box display="flex" alignItems="center" gap={1}>
                            <Avatar
                              sx={{
                                bgcolor: brandColors.primaryLight,
                                color: brandColors.primary,
                                width: 28,
                                height: 28,
                                fontSize: '0.75rem'
                              }}
                            >
                              {getInitials(exp.payerName || '-')}
                            </Avatar>
                            {exp.payerName || '-'}
                          </Box>
                        </TableCell>
                        <TableCell>
                          <Chip
                            label={exp.paid ? 'Paga' : 'Pendente'}
                            color={exp.paid ? 'success' : 'warning'}
                            size="small"
                          />
                        </TableCell>
                        <TableCell align="right">
                          <Box display="flex" gap={0.5} justifyContent="flex-end">
                            {exp.isFixed && cycleIsOpen && (
                              <Tooltip title="Remover despesa fixa">
                                <IconButton
                                  aria-label="Remover despesa fixa"
                                  size="small"
                                  onClick={() => setRemoveExpenseId(exp.id)}
                                >
                                  <DeleteOutlineIcon fontSize="small" />
                                </IconButton>
                              </Tooltip>
                            )}
                            {canEdit(exp) && (
                              <Tooltip title="Editar despesa">
                                <IconButton
                                  aria-label="Editar despesa"
                                  size="small"
                                  component={Link}
                                  to={`/groups/${groupId}/expenses/${exp.id}`}
                                >
                                  <EditOutlinedIcon fontSize="small" />
                                </IconButton>
                              </Tooltip>
                            )}
                            {canDelete(exp) && (
                              <Tooltip title="Excluir despesa">
                                <IconButton
                                  aria-label="Excluir despesa"
                                  size="small"
                                  onClick={() => setDeleteExpenseId(exp.id)}
                                >
                                  <DeleteOutlineIcon fontSize="small" />
                                </IconButton>
                              </Tooltip>
                            )}
                            {canPay(exp) && (
                              <Tooltip title="Marcar como paga">
                                <IconButton
                                  aria-label="Marcar como paga"
                                  size="small"
                                  disabled={payingExpenseId === exp.id}
                                  onClick={() => handlePay(exp.id)}
                                >
                                  <CheckCircleOutlineIcon fontSize="small" />
                                </IconButton>
                              </Tooltip>
                            )}
                            {canUnpay(exp) && (
                              <Tooltip title="Desfazer pagamento">
                                <IconButton
                                  aria-label="Desfazer pagamento"
                                  size="small"
                                  disabled={payingExpenseId === exp.id}
                                  onClick={() => handleUnpay(exp.id)}
                                >
                                  <UndoIcon fontSize="small" />
                                </IconButton>
                              </Tooltip>
                            )}
                          </Box>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableContainer>
            )}
          </Grid>

          <Grid size={{ xs: 12, sm: 12, lg: 4 }}>
            {summary && (
              <SummarySidePanel
                balances={summary.balances}
                settlements={summary.settlements}
                cycleStatus={summary.cycle.status}
              />
            )}
          </Grid>
        </Grid>
      )}

      {/* Diálogo de remoção de despesa Fixa */}
      <Dialog
        open={removeExpenseId !== null}
        onClose={() => setRemoveExpenseId(null)}
        PaperProps={{ sx: { borderRadius: 2 } }}
      >
        <DialogTitle>Remover despesa fixa</DialogTitle>
        <DialogContent dividers>
          <Typography>
            {removeExpense
              ? `A partir de quando "${removeExpense.description}" deve deixar de aparecer?`
              : 'A partir de quando esta despesa deve deixar de aparecer?'}
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setRemoveExpenseId(null)}>Cancelar</Button>
          <Button onClick={handleRemoveFromThisMonth}>A partir deste mês</Button>
          <Button variant="contained" onClick={handleRemoveFromNextMonth}>
            A partir do mês que vem
          </Button>
        </DialogActions>
      </Dialog>

      <Snackbar
        open={removeSuccess}
        autoHideDuration={4000}
        onClose={() => setRemoveSuccess(false)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={() => setRemoveSuccess(false)} severity="success" variant="filled">
          Despesa fixa removida com sucesso.
        </Alert>
      </Snackbar>

      {/* Diálogo de exclusão de despesa variável */}
      <Dialog open={deleteExpenseId !== null} onClose={closeDeleteDialog} PaperProps={{ sx: { borderRadius: 2 } }}>
        <DialogTitle>Excluir despesa</DialogTitle>
        <DialogContent dividers>
          <Typography>
            {deleteExpense
              ? `Tem certeza que deseja excluir "${deleteExpense.description}"? Essa ação não pode ser desfeita.`
              : 'Tem certeza que deseja excluir esta despesa? Essa ação não pode ser desfeita.'}
          </Typography>
          {deleteError && (
            <Alert severity="error" sx={{ mt: 2 }}>
              {deleteError}
            </Alert>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={closeDeleteDialog} disabled={deleting}>
            Cancelar
          </Button>
          <Button variant="contained" color="error" onClick={confirmDelete} disabled={deleting}>
            Excluir
          </Button>
        </DialogActions>
      </Dialog>

      <Snackbar
        open={deleteSuccess}
        autoHideDuration={4000}
        onClose={() => setDeleteSuccess(false)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={() => setDeleteSuccess(false)} severity="success" variant="filled">
          Despesa excluída com sucesso.
        </Alert>
      </Snackbar>

      <Snackbar
        open={closeSuccess}
        autoHideDuration={4000}
        onClose={() => setCloseSuccess(false)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={() => setCloseSuccess(false)} severity="success" variant="filled">
          Competência fechada com sucesso.
        </Alert>
      </Snackbar>

      <Snackbar
        open={reopenSuccess}
        autoHideDuration={4000}
        onClose={() => setReopenSuccess(false)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={() => setReopenSuccess(false)} severity="success" variant="filled">
          Competência reaberta com sucesso.
        </Alert>
      </Snackbar>

      <Snackbar
        open={paySuccess}
        autoHideDuration={4000}
        onClose={() => setPaySuccess(false)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={() => setPaySuccess(false)} severity="success" variant="filled">
          Despesa marcada como paga.
        </Alert>
      </Snackbar>

      <Snackbar
        open={unpaySuccess}
        autoHideDuration={4000}
        onClose={() => setUnpaySuccess(false)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={() => setUnpaySuccess(false)} severity="success" variant="filled">
          Pagamento desfeito.
        </Alert>
      </Snackbar>

      <Snackbar
        open={payError !== null}
        autoHideDuration={6000}
        onClose={() => setPayError(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={() => setPayError(null)} severity="error" variant="filled">
          {payError}
        </Alert>
      </Snackbar>
    </>
  );
};

export default ExpenseManager;
