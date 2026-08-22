import React, { useState } from 'react';
import axios from 'axios';
import { API_BASE_URL } from '../config';
import {
  Box,
  Button,
  Card,
  CardActionArea,
  CardActions,
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
  Snackbar,
  TextField,
  ToggleButton,
  ToggleButtonGroup
} from '@mui/material';
import { Link, useNavigate, useParams } from 'react-router-dom';
import ArrowBackIosNewIcon from '@mui/icons-material/ArrowBackIosNew';
import ArrowForwardIosIcon from '@mui/icons-material/ArrowForwardIos';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import AutorenewOutlinedIcon from '@mui/icons-material/AutorenewOutlined';
import ReceiptOutlinedIcon from '@mui/icons-material/ReceiptOutlined';
import { useGroupCycle } from '../hooks/useGroupCycle';

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

  const { summary, loading, error, goToPreviousCycle, goToNextCycle, reload } = useGroupCycle(groupId);
  const expenses = summary?.expenses ?? [];

  // Busca e filtro por tipo — client-side, sobre os dados da competência já carregada
  const [search, setSearch] = useState<string>('');
  const [typeFilter, setTypeFilter] = useState<'all' | 'fixed' | 'variable'>('all');

  // Diálogo de remoção de despesa Fixa
  const [removeExpenseId, setRemoveExpenseId] = useState<number | null>(null);
  const [removeSuccess, setRemoveSuccess] = useState<boolean>(false);

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

  const filteredExpenses = expenses
    .filter(exp => exp.description.toLowerCase().includes(search.toLowerCase()))
    .filter(exp => {
      if (typeFilter === 'fixed') return Boolean(exp.isFixed);
      if (typeFilter === 'variable') return !exp.isFixed;
      return true;
    });

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

      {/* Lista de despesas */}
      {loading ? (
        <Box display="flex" justifyContent="center" mt={4}>
          <CircularProgress />
        </Box>
      ) : error ? (
        <Typography color="error">{error}</Typography>
      ) : expenses.length === 0 ? (
        <Typography color="text.secondary">Nenhuma despesa encontrada para esta competência.</Typography>
      ) : filteredExpenses.length === 0 ? (
        <Typography color="text.secondary">Nenhuma despesa encontrada para esse filtro.</Typography>
      ) : (
        <Grid container spacing={2}>
          {filteredExpenses.map(exp => (
            <Grid size={{ xs: 12, sm: 6, md: 4 }} key={exp.id}>
              <Card elevation={3} sx={{ borderRadius: 2 }}>
                <CardActionArea component={Link} to={`/groups/${groupId}/expenses/${exp.id}`}>
                  <CardContent>
                    <Box display="flex" justifyContent="space-between" alignItems="flex-start" gap={1}>
                      <Box display="flex" alignItems="center" gap={1}>
                        {exp.isFixed ? (
                          <AutorenewOutlinedIcon color="action" fontSize="small" />
                        ) : (
                          <ReceiptOutlinedIcon color="action" fontSize="small" />
                        )}
                        <Typography variant="subtitle1">{exp.description}</Typography>
                      </Box>
                      <Chip label={exp.isFixed ? 'Fixa' : 'Variável'} size="small" />
                    </Box>
                    <Typography variant="h6" color="primary" sx={{ mt: 1 }}>
                      R$ {exp.value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </Typography>
                    <Typography variant="body2" color="text.secondary">
                      {formatDate(exp.date)} · Pago por {exp.payerName || '-'}
                    </Typography>
                  </CardContent>
                </CardActionArea>
                {exp.isFixed && (
                  <CardActions>
                    <IconButton
                      aria-label="Remover despesa fixa"
                      size="small"
                      onClick={() => setRemoveExpenseId(exp.id)}
                    >
                      <DeleteOutlineIcon fontSize="small" />
                    </IconButton>
                  </CardActions>
                )}
              </Card>
            </Grid>
          ))}
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
    </>
  );
};

export default ExpenseManager;
