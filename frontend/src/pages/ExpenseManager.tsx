import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { API_BASE_URL } from '../config';
import {
  Box,
  Button,
  Container,
  Typography,
  CircularProgress,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Paper,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  TextField,
  IconButton,
  MenuItem,
  FormGroup,
  FormControlLabel,
  Checkbox,
  FormLabel
} from '@mui/material';
import { useNavigate, useParams } from 'react-router-dom';
import ArrowBackIosNewIcon from '@mui/icons-material/ArrowBackIosNew';
import ArrowForwardIosIcon from '@mui/icons-material/ArrowForwardIos';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';

type ExpenseType = 'IN_CASH' | 'IN_INSTALLMENTS' | 'FIXED';

// Tipo de despesa (ajuste conforme sua API)
type Expense = {
  id: number;
  description: string;
  value: number;
  date: string;        // ISO string
  payerName?: string;  // opcional, se vier do backend
  isFixed?: boolean;
};

type GroupMember = {
  id: number;
  name: string;
};

const pad = (n: number): string => String(n).padStart(2, '0');

// Soma `months` meses a uma data 'YYYY-MM-DD', preservando o dia (com clamp pro fim do mês).
const addMonthsClamped = (dateStr: string, months: number): string => {
  const [y, m, d] = dateStr.split('-').map(Number);
  const totalMonths = (y * 12) + (m - 1) + months;
  const targetYear = Math.floor(totalMonths / 12);
  const targetMonthIndex = ((totalMonths % 12) + 12) % 12;
  const lastDayOfTargetMonth = new Date(targetYear, targetMonthIndex + 1, 0).getDate();
  const day = Math.min(d, lastDayOfTargetMonth);
  return `${targetYear}-${pad(targetMonthIndex + 1)}-${pad(day)}`;
};

type Quota = { number: number; date_expected: string; paid: boolean; value_quota: number };

// Divide o valor total em N parcelas mensais iguais, arredondamento absorvido na última.
const buildInstallmentQuotas = (totalValue: number, installmentsCount: number, startDate: string): Quota[] => {
  const totalCents = Math.round(totalValue * 100);
  const baseCents = Math.floor(totalCents / installmentsCount);
  const remainderCents = totalCents - (baseCents * installmentsCount);

  return Array.from({ length: installmentsCount }, (_, i) => {
    const cents = baseCents + (i === installmentsCount - 1 ? remainderCents : 0);
    return {
      number: i + 1,
      date_expected: addMonthsClamped(startDate, i),
      paid: false,
      value_quota: cents / 100
    };
  });
};

const ExpenseManager: React.FC = () => {
  const { id: groupId } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const [expenses, setExpenses] = useState<Expense[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  const [currentDate, setCurrentDate] = useState<Date>(new Date());

  // Modal de nova despesa
  const [openModal, setOpenModal] = useState<boolean>(false);
  const [newDescription, setNewDescription] = useState<string>('');
  const [newValue, setNewValue] = useState<string>(''); // string para input
  const [newDate, setNewDate] = useState<string>(() => {
    const today = new Date();
    return today.toISOString().slice(0, 10); // yyyy-MM-dd
  });
  const [newPayerId, setNewPayerId] = useState<string>('');
  const [newExpenseType, setNewExpenseType] = useState<ExpenseType>('IN_CASH');
  const [newInstallmentsCount, setNewInstallmentsCount] = useState<string>('');
  const [participantIds, setParticipantIds] = useState<number[]>([]);

  // Diálogo de remoção de despesa Fixa
  const [removeExpenseId, setRemoveExpenseId] = useState<number | null>(null);

  // Membros do grupo (seletor de pagador) e usuário autenticado
  const [members, setMembers] = useState<GroupMember[]>([]);
  const [currentUserId, setCurrentUserId] = useState<number | null>(null);

  // Helpers de mês/ano
  const year = currentDate.getFullYear();
  const month = currentDate.getMonth() + 1; // 1-12

  const monthLabel = currentDate.toLocaleDateString('pt-BR', {
    month: 'long',
    year: 'numeric'
  });

  const changeMonth = (delta: number) => {
    setCurrentDate(prev => {
      const d = new Date(prev);
      d.setMonth(d.getMonth() + delta);
      return d;
    });
  };

  const loadExpenses = () => {
    if (!groupId) return;

    setLoading(true);
    setError(null);

    const token = localStorage.getItem('accessToken');
    console.error('Load expenses for groupId: ${groupId}');
    axios
      .get<Expense[]>(`${API_BASE_URL}/api/groups/${groupId}/expenses`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' },
        params: {
          year,
          month
        }
      })
      .then(res => setExpenses(res.data))
      .catch(err => {
        console.error('Erro ao carregar despesas:', err);
        if (err.response?.status === 401) {
          navigate('/', { replace: true });
          return;
        }
        setError('Falha ao carregar despesas.');
      })
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    loadExpenses();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [groupId, year, month]);

  useEffect(() => {
    if (!groupId) return;

    const token = localStorage.getItem('accessToken');
    const headers = { Authorization: token ? `Bearer ${token}` : '' };

    axios
      .get<GroupMember[]>(`${API_BASE_URL}/api/groups/${groupId}/members`, { headers })
      .then(res => setMembers(res.data))
      .catch(err => console.error('Erro ao carregar membros do grupo:', err));

    axios
      .get<{ id: number }>(`${API_BASE_URL}/api/me`, { headers })
      .then(res => setCurrentUserId(res.data.id))
      .catch(err => console.error('Erro ao carregar usuário autenticado:', err));
  }, [groupId]);

  const handleOpenModal = () => {
    setNewDescription('');
    setNewValue('');
    const today = new Date();
    setNewDate(today.toISOString().slice(0, 10));
    setNewPayerId(currentUserId ? String(currentUserId) : '');
    setNewExpenseType('IN_CASH');
    setNewInstallmentsCount('');
    setParticipantIds(members.map(member => member.id));
    setOpenModal(true);
  };

  const handleCloseModal = () => {
    setOpenModal(false);
  };

  const toggleParticipant = (memberId: number) => {
    setParticipantIds(prev =>
      prev.includes(memberId) ? prev.filter(id => id !== memberId) : [...prev, memberId]
    );
  };

  const handleSaveExpense = () => {
    if (!groupId) return;

    const valueNumber = parseFloat(
      newValue.replace('.', '').replace(',', '.')
    );

    if (!newDescription || isNaN(valueNumber) || !newPayerId) {
      alert('Preencha descrição, valor e pagador corretamente.');
      return;
    }

    if (participantIds.length === 0) {
      alert('Selecione ao menos um participante da divisão.');
      return;
    }

    let installments = 1;
    let quotas: Quota[];

    if (newExpenseType === 'IN_INSTALLMENTS') {
      const installmentsCount = parseInt(newInstallmentsCount, 10);
      if (!Number.isInteger(installmentsCount) || installmentsCount < 2) {
        alert('Informe uma quantidade de parcelas válida (mínimo 2 — para 1 parcela use À Vista).');
        return;
      }
      installments = installmentsCount;
      quotas = buildInstallmentQuotas(valueNumber, installmentsCount, newDate);
    } else if (newExpenseType === 'FIXED') {
      quotas = [{ number: 1, date_expected: newDate, paid: false, value_quota: valueNumber }];
    } else {
      quotas = [{ number: 1, date_expected: newDate, paid: true, value_quota: valueNumber }];
    }

    const token = localStorage.getItem('accessToken');
    const payerId = Number(newPayerId);

    const payload = {
      date_payment: newDate,
      description: newDescription,
      expense_type: newExpenseType,
      installments,
      total_value: valueNumber,
      group_id: Number(groupId),
      user_creator_id: currentUserId,
      user_payer_id: payerId,
      payers: participantIds,
      quotas
    };

    axios
      .post(`${API_BASE_URL}/api/expenses`, payload, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(() => {
        handleCloseModal();
        loadExpenses();
      })
      .catch(err => {
        console.error('Erro ao salvar despesa:', err);
        alert('Falha ao salvar despesa.');
      });
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
        loadExpenses();
      })
      .catch(err => {
        console.error('Erro ao remover recorrência da despesa fixa:', err);
        alert('Falha ao remover despesa fixa.');
      });
  };

  const handleRemoveFromThisMonth = () => stopFixedRecurrence(year, month);

  const handleRemoveFromNextMonth = () => {
    const next = new Date(currentDate);
    next.setMonth(next.getMonth() + 1);
    stopFixedRecurrence(next.getFullYear(), next.getMonth() + 1);
  };

  const dateFieldLabel =
    newExpenseType === 'IN_INSTALLMENTS'
      ? 'Mês de início das parcelas'
      : newExpenseType === 'FIXED'
      ? 'Data de início'
      : 'Data';

  return (
    <Container sx={{ mt: 4, mb: 4 }}>
      {/* Cabeçalho */}
      <Box
        display="flex"
        justifyContent="space-between"
        alignItems="center"
        mb={3}
      >
        <Box>
          <Typography variant="h4">Despesas do Grupo</Typography>
          {groupId && (
            <Typography variant="subtitle2" color="text.secondary">
              Grupo ID: {groupId}
            </Typography>
          )}
        </Box>

        <Button
          variant="contained"
          color="primary"
          startIcon={<AddIcon />}
          onClick={handleOpenModal}
        >
          Nova Despesa
        </Button>
      </Box>

      {/* Seletor de mês */}
      <Box
        display="flex"
        alignItems="center"
        justifyContent="center"
        mb={3}
        gap={1}
      >
        <IconButton onClick={() => changeMonth(-1)} aria-label="Mês anterior">
          <ArrowBackIosNewIcon />
        </IconButton>
        <Typography variant="h6" textTransform="capitalize">
          {monthLabel}
        </Typography>
        <IconButton onClick={() => changeMonth(1)} aria-label="Próximo mês">
          <ArrowForwardIosIcon />
        </IconButton>
      </Box>

      {/* Lista de despesas */}
      {loading ? (
        <Box display="flex" justifyContent="center" mt={4}>
          <CircularProgress />
        </Box>
      ) : error ? (
        <Typography color="error">{error}</Typography>
      ) : expenses.length === 0 ? (
        <Typography>Nenhuma despesa encontrada para este mês.</Typography>
      ) : (
        <Paper elevation={3}>
          <Table>
            <TableHead>
              <TableRow>
                <TableCell>Data</TableCell>
                <TableCell>Descrição</TableCell>
                <TableCell align="right">Valor (R$)</TableCell>
                <TableCell>Pagador</TableCell>
                <TableCell align="right">Ações</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {expenses.map(exp => (
                <TableRow key={exp.id}>
                  <TableCell>
                    {new Date(exp.date).toLocaleDateString('pt-BR')}
                  </TableCell>
                  <TableCell>{exp.description}</TableCell>
                  <TableCell align="right">
                    {exp.value.toLocaleString('pt-BR', {
                      minimumFractionDigits: 2,
                      maximumFractionDigits: 2
                    })}
                  </TableCell>
                  <TableCell>{exp.payerName || '-'}</TableCell>
                  <TableCell align="right">
                    {exp.isFixed && (
                      <IconButton
                        aria-label="Remover despesa fixa"
                        size="small"
                        onClick={() => setRemoveExpenseId(exp.id)}
                      >
                        <DeleteOutlineIcon fontSize="small" />
                      </IconButton>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Paper>
      )}

      {/* Modal de nova despesa */}
      <Dialog open={openModal} onClose={handleCloseModal} fullWidth maxWidth="sm">
        <DialogTitle>Cadastrar nova despesa</DialogTitle>
        <DialogContent dividers>
          <Box display="flex" flexDirection="column" gap={2} mt={1}>
            <TextField
              label="Descrição"
              fullWidth
              value={newDescription}
              onChange={e => setNewDescription(e.target.value)}
            />

            <TextField
              label="Valor"
              fullWidth
              value={newValue}
              onChange={e => setNewValue(e.target.value)}
              placeholder="Ex: 150,00"
            />

            <TextField
              label="Tipo de despesa"
              select
              fullWidth
              value={newExpenseType}
              onChange={e => setNewExpenseType(e.target.value as ExpenseType)}
            >
              <MenuItem value="IN_CASH">À Vista</MenuItem>
              <MenuItem value="IN_INSTALLMENTS">Parcelada</MenuItem>
              <MenuItem value="FIXED">Fixa</MenuItem>
            </TextField>

            <TextField
              label={dateFieldLabel}
              type="date"
              fullWidth
              value={newDate}
              onChange={e => setNewDate(e.target.value)}
              InputLabelProps={{ shrink: true }}
            />

            {newExpenseType === 'IN_INSTALLMENTS' && (
              <TextField
                label="Quantidade de parcelas"
                type="number"
                fullWidth
                value={newInstallmentsCount}
                onChange={e => setNewInstallmentsCount(e.target.value)}
                inputProps={{ min: 2 }}
              />
            )}

            <TextField
              label="Pagador"
              select
              fullWidth
              value={newPayerId}
              onChange={e => setNewPayerId(e.target.value)}
            >
              {members.map(member => (
                <MenuItem key={member.id} value={String(member.id)}>
                  {member.name}
                </MenuItem>
              ))}
            </TextField>

            <Box>
              <FormLabel component="legend">Quem participa desta despesa?</FormLabel>
              <FormGroup>
                {members.map(member => (
                  <FormControlLabel
                    key={member.id}
                    control={
                      <Checkbox
                        checked={participantIds.includes(member.id)}
                        onChange={() => toggleParticipant(member.id)}
                      />
                    }
                    label={member.name}
                  />
                ))}
              </FormGroup>
            </Box>
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={handleCloseModal}>Cancelar</Button>
          <Button variant="contained" onClick={handleSaveExpense}>
            Salvar
          </Button>
        </DialogActions>
      </Dialog>

      {/* Diálogo de remoção de despesa Fixa */}
      <Dialog open={removeExpenseId !== null} onClose={() => setRemoveExpenseId(null)}>
        <DialogTitle>Remover despesa fixa</DialogTitle>
        <DialogContent dividers>
          <Typography>
            A partir de quando esta despesa deve deixar de aparecer?
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
    </Container>
  );
};

export default ExpenseManager;