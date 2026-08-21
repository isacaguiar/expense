import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { API_BASE_URL } from '../config';
import {
  Box,
  Button,
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
  IconButton
} from '@mui/material';
import { useNavigate, useParams } from 'react-router-dom';
import ArrowBackIosNewIcon from '@mui/icons-material/ArrowBackIosNew';
import ArrowForwardIosIcon from '@mui/icons-material/ArrowForwardIos';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';

// Tipo de despesa (ajuste conforme sua API)
type Expense = {
  id: number;
  description: string;
  value: number;
  date: string;        // ISO string
  payerName?: string;  // opcional, se vier do backend
  isFixed?: boolean;
};

const ExpenseManager: React.FC = () => {
  const { id: groupId } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const [expenses, setExpenses] = useState<Expense[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  const [currentDate, setCurrentDate] = useState<Date>(new Date());

  // Diálogo de remoção de despesa Fixa
  const [removeExpenseId, setRemoveExpenseId] = useState<number | null>(null);

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
    </>
  );
};

export default ExpenseManager;