import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { API_BASE_URL } from '../config';
import { Box, Button, Card, CardContent, Chip, CircularProgress, Link as MuiLink, Typography } from '@mui/material';
import { Link, useNavigate, useParams } from 'react-router-dom';

type Expense = {
  id: number;
  description: string;
  value: number;
  date: string;
  payerName?: string;
  isFixed?: boolean;
};

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const ExpenseView: React.FC = () => {
  const { id: groupId, expenseId } = useParams<{ id: string; expenseId: string }>();
  const navigate = useNavigate();

  const [expense, setExpense] = useState<Expense | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [notFound, setNotFound] = useState<boolean>(false);

  useEffect(() => {
    if (!groupId || !expenseId) return;

    setLoading(true);
    setNotFound(false);

    const token = localStorage.getItem('accessToken');
    const today = new Date();

    axios
      .get<Expense[]>(`${API_BASE_URL}/api/groups/${groupId}/expenses`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' },
        params: { year: today.getFullYear(), month: today.getMonth() + 1 }
      })
      .then(res => {
        const found = res.data.find(exp => String(exp.id) === expenseId);
        if (found) {
          setExpense(found);
        } else {
          setNotFound(true);
        }
      })
      .catch(err => {
        console.error('Erro ao carregar despesa:', err);
        if (err.response?.status === 401) {
          navigate('/', { replace: true });
          return;
        }
        setNotFound(true);
      })
      .finally(() => setLoading(false));
  }, [groupId, expenseId, navigate]);

  if (loading) {
    return (
      <Box display="flex" justifyContent="center" mt={4}>
        <CircularProgress />
      </Box>
    );
  }

  if (notFound || !expense) {
    return (
      <Card elevation={3} sx={{ borderRadius: 2, maxWidth: 560, mx: 'auto' }}>
        <CardContent sx={{ p: 4 }}>
          <Typography variant="h6" gutterBottom>
            Despesa não encontrada
          </Typography>
          <Typography color="text.secondary" sx={{ mb: 3 }}>
            Essa despesa não está no mês atualmente carregado, ou não existe mais.
          </Typography>
          <MuiLink component={Link} to={`/groups/${groupId}/expenses`}>
            Voltar para a listagem
          </MuiLink>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card elevation={3} sx={{ borderRadius: 2, maxWidth: 560, mx: 'auto' }}>
      <CardContent sx={{ p: 4 }}>
        <Box display="flex" justifyContent="space-between" alignItems="flex-start" mb={2}>
          <Typography variant="h6">{expense.description}</Typography>
          <Chip label={expense.isFixed ? 'Fixa' : 'Variável'} size="small" />
        </Box>

        <Typography variant="h5" color="primary" gutterBottom>
          R$ {formatMoney(expense.value)}
        </Typography>

        <Typography color="text.secondary">
          {new Date(expense.date).toLocaleDateString('pt-BR')}
        </Typography>
        <Typography color="text.secondary" sx={{ mb: 3 }}>
          Pago por {expense.payerName ?? '-'}
        </Typography>

        <Button variant="outlined" component={Link} to={`/groups/${groupId}/expenses`}>
          Voltar para a listagem
        </Button>
      </CardContent>
    </Card>
  );
};

export default ExpenseView;
