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
  IconButton
} from '@mui/material';
import { useNavigate, useParams } from 'react-router-dom';
import ArrowBackIosNewIcon from '@mui/icons-material/ArrowBackIosNew';
import ArrowForwardIosIcon from '@mui/icons-material/ArrowForwardIos';
import AddIcon from '@mui/icons-material/Add';

// Tipo de despesa (ajuste conforme sua API)
type Expense = {
  id: number;
  description: string;
  value: number;
  date: string;        // ISO string
  payerName?: string;  // opcional, se vier do backend
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

  const handleOpenModal = () => {
    setNewDescription('');
    setNewValue('');
    const today = new Date();
    setNewDate(today.toISOString().slice(0, 10));
    setOpenModal(true);
  };

  const handleCloseModal = () => {
    setOpenModal(false);
  };

  const handleSaveExpense = () => {
    if (!groupId) return;

    const valueNumber = parseFloat(
      newValue.replace('.', '').replace(',', '.')
    );

    if (!newDescription || isNaN(valueNumber)) {
      alert('Preencha descrição e valor corretamente.');
      return;
    }

    const token = localStorage.getItem('accessToken');

    const payload = {
      description: newDescription,
      value: valueNumber,
      date: newDate
      // inclua outros campos aqui se sua API exigir (ex: payer_id, participants etc.)
    };

    axios
      .post(`${API_BASE_URL}/api/groups/${groupId}/expenses`, payload, {
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
              label="Data"
              type="date"
              fullWidth
              value={newDate}
              onChange={e => setNewDate(e.target.value)}
              InputLabelProps={{ shrink: true }}
            />
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={handleCloseModal}>Cancelar</Button>
          <Button variant="contained" onClick={handleSaveExpense}>
            Salvar
          </Button>
        </DialogActions>
      </Dialog>
    </Container>
  );
};

export default ExpenseManager;