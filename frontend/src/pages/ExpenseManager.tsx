import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { API_BASE_URL } from '../config';
import {
  Box,
  Button,
  Card,
  CardContent,
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
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TextField,
  ToggleButton,
  ToggleButtonGroup,
  Tooltip,
  useMediaQuery,
  useTheme
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
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import SearchOutlinedIcon from '@mui/icons-material/SearchOutlined';
import SavingsOutlinedIcon from '@mui/icons-material/SavingsOutlined';
import ImageOutlinedIcon from '@mui/icons-material/ImageOutlined';
import { useGroupCycle, SummaryExpense } from '../hooks/useGroupCycle';
import { usePaymentActions } from '../hooks/usePaymentActions';
import SummarySidePanel from '../components/SummarySidePanel';
import CycleClosingAlert from '../components/CycleClosingAlert';
import { brandColors } from '../theme/brandColors';
import DespesasThemeScope from '../theme/DespesasThemeScope';
import UserAvatar from '../components/UserAvatar';

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

/**
 * Rótulo de tipo do modal de detalhe. Diferente do ícone da listagem
 * (`renderTypeIcon`, que só distingue Fixa de Variável), aqui os três tipos
 * aparecem por extenso — e a Parcelada mostra qual parcela vence NESTE ciclo
 * ("Parcelada 2/6"), que é o que o usuário pediu.
 *
 * `expenseType` é opcional: ciclo selado antes desta mudança é servido do
 * snapshot congelado e não tem o campo — nesse caso cai no rótulo antigo.
 * Ver docs/feature/20260904-detalhe-despesa-tipo-parcela-valores/specify.md §3.1.
 */
const detailTypeLabel = (exp: SummaryExpense): string => {
  if (exp.expenseType === 'FIXED') return 'Fixa';
  if (exp.expenseType === 'IN_CASH') return 'À Vista';

  if (exp.expenseType === 'IN_INSTALLMENTS') {
    return exp.installmentNumber && exp.installmentsTotal
      ? `Parcelada ${exp.installmentNumber}/${exp.installmentsTotal}`
      : 'Parcelada';
  }

  return exp.isFixed ? 'Fixa' : 'Variável';
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

  // Modal de detalhamento da despesa
  const [detailExpenseId, setDetailExpenseId] = useState<number | null>(null);

  // Diálogo de remoção de despesa Fixa
  const [removeExpenseId, setRemoveExpenseId] = useState<number | null>(null);
  const [removeSuccess, setRemoveSuccess] = useState<boolean>(false);

  // Diálogo de exclusão de despesa variável
  const [deleteExpenseId, setDeleteExpenseId] = useState<number | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [deleting, setDeleting] = useState<boolean>(false);
  const [deleteSuccess, setDeleteSuccess] = useState<boolean>(false);

  // Marcar como paga / desfazer pagamento (hook compartilhado com Payments.tsx)
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
  const detailExpense = expenses.find(exp => exp.id === detailExpenseId) ?? null;

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

  // Ações de EDIÇÃO/exclusão de despesa só existem enquanto a competência
  // selecionada está aberta — inclui a janela de carência, em que o ciclo
  // ainda é `open` (só trava em `closes_at`). Competência fechada (por data ou
  // manualmente), selada ou futura não edita: a API recusaria de qualquer
  // forma, isto só evita mostrar um botão que levaria a um erro. Pagamento
  // (`canPay`/`canUnpay`) NÃO segue esta regra — ver `usePaymentActions`.
  const cycleIsEditable = summary?.cycle.status === 'open';

  const isOwner = (exp: SummaryExpense) =>
    currentUserId !== null && (currentUserId === exp.userCreatorId || currentUserId === exp.userPayerId);

  const canEdit = (exp: SummaryExpense) => cycleIsEditable && isOwner(exp);
  const canDelete = (exp: SummaryExpense) => cycleIsEditable && !exp.isFixed && !exp.paid && isOwner(exp);

  const theme = useTheme();
  // Abaixo de `sm` a tabela de 6 colunas fica ilegível no celular — troca para
  // uma lista de cartões (ver docs/feature/concluidas/202609/20260901-usabilidade-mobile).
  const compact = useMediaQuery(theme.breakpoints.down('sm'));

  const formatValue = (value: number) =>
    value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  // Ícone de tipo e ações compartilhados entre a tabela (>= sm) e os cartões
  // (< sm), para os dois layouts nunca divergirem de comportamento.
  const renderTypeIcon = (exp: SummaryExpense) => (
    <Tooltip title={exp.isFixed ? 'Fixa' : 'Variável'}>
      {exp.isFixed ? (
        <AutorenewOutlinedIcon color="action" fontSize="small" />
      ) : (
        <ReceiptOutlinedIcon color="action" fontSize="small" />
      )}
    </Tooltip>
  );

  /**
   * Uma linha por pagador, com o valor que cabe a cada um. O rateio é sempre
   * igualitário e já vem calculado do backend em `valuePerPerson` — o cliente
   * não redivide nada (docs/sdd/05-context-frontend.md, "Convenções fixas").
   *
   * `participantDetails` (com id e avatar) é a fonte preferencial; ciclo selado
   * antes da feature do avatar só tem `participants` (nomes), e aí a lista sai
   * sem avatar e sem marcar quem é o credor.
   */
  const renderDetailPayers = (exp: SummaryExpense) => {
    const rows =
      exp.participantDetails?.map(p => ({ key: String(p.id), name: p.name, avatarUrl: p.avatarUrl, isCreditor: p.id === exp.userPayerId })) ??
      exp.participants.map((name, index) => ({ key: `${name}-${index}`, name, avatarUrl: null, isCreditor: false }));

    if (rows.length === 0) {
      return <Typography variant="body2">-</Typography>;
    }

    return (
      <Box display="flex" flexDirection="column" gap={1}>
        {rows.map(row => (
          <Box key={row.key} display="flex" alignItems="center" gap={1}>
            <UserAvatar name={row.name} avatarUrl={row.avatarUrl} sx={{ width: 28, height: 28, fontSize: '0.75rem' }} />
            <Typography variant="body2" flexGrow={1}>
              {row.name}
              {row.isCreditor && (
                <Typography component="span" variant="caption" color="text.secondary">
                  {' '}
                  (credor)
                </Typography>
              )}
            </Typography>
            {/* Snapshot de ciclo selado antigo pode não ter valuePerPerson —
                mostra o nome sem valor em vez de quebrar o modal. */}
            {exp.valuePerPerson != null && (
              <Typography variant="body2" fontWeight={600}>
                R$ {formatValue(exp.valuePerPerson)}
              </Typography>
            )}
          </Box>
        ))}
      </Box>
    );
  };

  const renderExpenseActions = (exp: SummaryExpense) => (
    <Box display="flex" gap={0.5} justifyContent="flex-end" flexWrap="wrap">
      <Tooltip title="Ver detalhes">
        <IconButton aria-label="Ver detalhes" size="small" onClick={() => setDetailExpenseId(exp.id)}>
          <InfoOutlinedIcon fontSize="small" />
        </IconButton>
      </Tooltip>
      {exp.paid && exp.paymentProofUrl && (
        <Tooltip title="Ver comprovante">
          <IconButton
            aria-label="Ver comprovante"
            size="small"
            component="a"
            href={exp.paymentProofUrl}
            target="_blank"
            rel="noreferrer"
          >
            <ImageOutlinedIcon fontSize="small" />
          </IconButton>
        </Tooltip>
      )}
      {exp.isFixed && cycleIsEditable && (
        <Tooltip title="Remover despesa fixa">
          <IconButton aria-label="Remover despesa fixa" size="small" onClick={() => setRemoveExpenseId(exp.id)}>
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
          <IconButton aria-label="Excluir despesa" size="small" onClick={() => setDeleteExpenseId(exp.id)}>
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
  );

  const renderExpenseCards = (list: SummaryExpense[]) => (
    <Stack spacing={1.5}>
      {list.map(exp => (
        <Card key={exp.id} variant="outlined">
          <CardContent sx={{ '&:last-child': { pb: 2 } }}>
            <Box display="flex" alignItems="flex-start" justifyContent="space-between" gap={1}>
              <Box display="flex" alignItems="center" gap={1} sx={{ minWidth: 0 }}>
                {renderTypeIcon(exp)}
                <MuiLink
                  component={Link}
                  to={`/groups/${groupId}/expenses/${exp.id}`}
                  underline="hover"
                  sx={{ fontWeight: 500 }}
                >
                  {exp.description}
                </MuiLink>
              </Box>
              <Chip
                label={exp.paid ? 'Paga' : 'Pendente'}
                color={exp.paid ? 'success' : 'warning'}
                size="small"
              />
            </Box>
            <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
              Valor: R$ {formatValue(exp.value)}
            </Typography>
            <Box display="flex" alignItems="center" gap={1} sx={{ mt: 0.5 }}>
              <Typography variant="body2" color="text.secondary">
                Credor:
              </Typography>
              <UserAvatar
                name={exp.payerName || '-'}
                avatarUrl={exp.payerAvatarUrl}
                sx={{ width: 24, height: 24, fontSize: '0.7rem' }}
              />
              <Typography variant="body2" color="text.secondary">
                {exp.payerName || '-'}
              </Typography>
            </Box>
            <Box sx={{ mt: 1 }}>{renderExpenseActions(exp)}</Box>
          </CardContent>
        </Card>
      ))}
    </Stack>
  );

  return (
    <DespesasThemeScope>
      {/* Cabeçalho */}
      <Box
        display="flex"
        justifyContent="flex-end"
        alignItems="center"
        flexWrap="wrap"
        mb={3}
        gap={2}
        rowGap={1}
      >
        {/* Fechar/reabrir — só faz sentido na competência vigente (cyclesAgo=0):
            close()/reopen() sempre operam sobre "agora", nunca sobre a
            competência navegada via as setas do seletor abaixo. */}
        {summary && cyclesAgo === 0 && (summary.cycle.status === 'open' || summary.cycle.status === 'closed_manually') && (
          summary.cycle.status === 'open' ? (
            <Button variant="outlined" onClick={handleCloseMonth} disabled={closingMonth}>
              Fechar mês
            </Button>
          ) : (
            <Button variant="outlined" onClick={handleReopenMonth} disabled={reopeningMonth}>
              Reabrir mês
            </Button>
          )
        )}
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
        <Typography
          variant="h6"
          textTransform="capitalize"
          sx={{ flexGrow: 1, minWidth: 0, textAlign: 'center', fontSize: { xs: '1rem', md: '1.25rem' } }}
        >
          {summary ? `${formatCycleBoundary(summary.cycle.start)} – ${formatCycleBoundary(summary.cycle.end)}` : ''}
        </Typography>
        <IconButton onClick={goToNextCycle} aria-label="Próxima competência">
          <ArrowForwardIosIcon />
        </IconButton>
      </Box>

      {summary && <CycleClosingAlert summary={summary} />}

      {/* Erro de fechar/reabrir mês — o botão em si vive no cabeçalho; aqui fica
          só o feedback de erro, no mesmo lugar central de antes. */}
      {closeReopenError && (
        <Box display="flex" flexDirection="column" alignItems="center" mb={3}>
          <Alert severity="error" sx={{ maxWidth: 480 }}>
            {closeReopenError}
          </Alert>
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
                InputProps={{
                  startAdornment: <SearchOutlinedIcon fontSize="small" sx={{ color: 'text.secondary', mr: 1 }} />
                }}
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
              <Box display="flex" flexDirection="column" alignItems="center" textAlign="center" py={6} gap={2}>
                <Box
                  display="flex"
                  alignItems="center"
                  justifyContent="center"
                  sx={{
                    width: 72,
                    height: 72,
                    borderRadius: '50%',
                    bgcolor: brandColors.primaryLight,
                    color: brandColors.primary
                  }}
                >
                  <SavingsOutlinedIcon sx={{ fontSize: 36 }} />
                </Box>
                <Typography color="text.secondary">Nenhuma despesa encontrada para esta competência.</Typography>
              </Box>
            ) : filteredExpenses.length === 0 ? (
              <Box display="flex" flexDirection="column" alignItems="center" textAlign="center" py={6} gap={2}>
                <Box
                  display="flex"
                  alignItems="center"
                  justifyContent="center"
                  sx={{
                    width: 72,
                    height: 72,
                    borderRadius: '50%',
                    bgcolor: brandColors.primaryLight,
                    color: brandColors.primary
                  }}
                >
                  <SavingsOutlinedIcon sx={{ fontSize: 36 }} />
                </Box>
                <Typography color="text.secondary">Nenhuma despesa encontrada para esse filtro.</Typography>
              </Box>
            ) : compact ? (
              renderExpenseCards(filteredExpenses)
            ) : (
              <TableContainer component={Paper} elevation={0} sx={{ overflowX: 'auto' }}>
                <Table>
                  <TableHead>
                    <TableRow sx={{ bgcolor: 'grey.50' }}>
                      <TableCell sx={{ fontWeight: 600, color: 'text.secondary' }}>Tipo</TableCell>
                      <TableCell sx={{ fontWeight: 600, color: 'text.secondary' }}>Despesa</TableCell>
                      <TableCell sx={{ fontWeight: 600, color: 'text.secondary' }}>Valor</TableCell>
                      <TableCell sx={{ fontWeight: 600, color: 'text.secondary' }}>Credor</TableCell>
                      <TableCell sx={{ fontWeight: 600, color: 'text.secondary' }}>Status</TableCell>
                      <TableCell align="right" sx={{ fontWeight: 600, color: 'text.secondary' }}>
                        Ações
                      </TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {filteredExpenses.map(exp => (
                      <TableRow key={exp.id} hover>
                        <TableCell>
                          {renderTypeIcon(exp)}
                        </TableCell>
                        <TableCell>
                          <MuiLink component={Link} to={`/groups/${groupId}/expenses/${exp.id}`} underline="hover">
                            {exp.description}
                          </MuiLink>
                        </TableCell>
                        <TableCell>
                          R$ {formatValue(exp.value)}
                        </TableCell>
                        <TableCell>
                          <Box display="flex" alignItems="center" gap={1}>
                            <UserAvatar
                              name={exp.payerName || '-'}
                              avatarUrl={exp.payerAvatarUrl}
                              sx={{ width: 28, height: 28, fontSize: '0.75rem' }}
                            />
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
                          {renderExpenseActions(exp)}
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

      {/* Modal de detalhamento da despesa */}
      <Dialog
        open={detailExpenseId !== null}
        onClose={() => setDetailExpenseId(null)}
        PaperProps={{ sx: { borderRadius: 2 } }}
        fullWidth
        maxWidth="xs"
      >
        <DialogTitle>Detalhes da despesa</DialogTitle>
        <DialogContent dividers>
          {detailExpense && (
            <Box display="flex" flexDirection="column" gap={1.5}>
              <Box display="flex" justifyContent="space-between" alignItems="flex-start" gap={1}>
                <Typography variant="subtitle1">{detailExpense.description}</Typography>
                <Box display="flex" gap={0.5}>
                  <Chip label={detailTypeLabel(detailExpense)} size="small" />
                  <Chip
                    label={detailExpense.paid ? 'Paga' : 'Pendente'}
                    color={detailExpense.paid ? 'success' : 'warning'}
                    size="small"
                  />
                </Box>
              </Box>

              <Box>
                <Typography variant="h6" color="primary">
                  R$ {formatValue(detailExpense.value)}
                </Typography>
                {/* Numa parcelada o valor acima é o da parcela do mês, não o da
                    compra inteira — o total só aparece aqui, pra não confundir os dois. */}
                {detailExpense.expenseType === 'IN_INSTALLMENTS' && detailExpense.totalValue != null && (
                  <Typography variant="caption" color="text.secondary">
                    Total da despesa: R$ {formatValue(detailExpense.totalValue)}
                    {detailExpense.installmentsTotal ? ` em ${detailExpense.installmentsTotal}x` : ''}
                  </Typography>
                )}
              </Box>

              <Typography variant="body2" color="text.secondary">
                {formatDate(detailExpense.date)}
              </Typography>

              <Box display="flex" alignItems="center" gap={1}>
                <UserAvatar
                  name={detailExpense.payerName || '-'}
                  avatarUrl={detailExpense.payerAvatarUrl}
                  sx={{ width: 32, height: 32, fontSize: '0.8rem' }}
                />
                <Box>
                  <Typography variant="body2">Credor: {detailExpense.payerName || '-'}</Typography>
                  <Typography variant="caption" color="text.secondary">
                    Pagou R$ {formatValue(detailExpense.value)}
                  </Typography>
                </Box>
              </Box>

              <Box>
                <Typography variant="body2" color="text.secondary" gutterBottom>
                  Pagadores
                </Typography>
                {renderDetailPayers(detailExpense)}
              </Box>

              {detailExpense.paid && detailExpense.paymentProofUrl && (
                <MuiLink href={detailExpense.paymentProofUrl} target="_blank" rel="noreferrer" variant="body2">
                  Ver comprovante
                </MuiLink>
              )}
            </Box>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDetailExpenseId(null)}>Fechar</Button>
        </DialogActions>
      </Dialog>

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
        onClose={dismissPaySuccess}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={dismissPaySuccess} severity="success" variant="filled">
          Despesa marcada como paga.
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
    </DespesasThemeScope>
  );
};

export default ExpenseManager;
