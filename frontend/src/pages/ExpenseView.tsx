import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { API_BASE_URL } from '../config';
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Checkbox,
  Chip,
  CircularProgress,
  FormControlLabel,
  FormGroup,
  FormLabel,
  Link as MuiLink,
  MenuItem,
  TextField,
  Typography
} from '@mui/material';
import { Link, useNavigate, useParams } from 'react-router-dom';

type GroupMember = { id: number; name: string };

type ExpenseType = 'IN_CASH' | 'IN_INSTALLMENTS' | 'FIXED';

type ExpenseDetail = {
  id: number;
  description: string;
  total_value: string | number;
  date_payment: string;
  expense_type: ExpenseType;
  user_payer_id: number;
  payers: GroupMember[];
};

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const typeLabel: Record<ExpenseType, string> = {
  IN_CASH: 'Variável',
  IN_INSTALLMENTS: 'Variável',
  FIXED: 'Fixa'
};

const ExpenseView: React.FC = () => {
  const { id: groupId, expenseId } = useParams<{ id: string; expenseId: string }>();
  const navigate = useNavigate();

  const [expense, setExpense] = useState<ExpenseDetail | null>(null);
  const [members, setMembers] = useState<GroupMember[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [notFound, setNotFound] = useState<boolean>(false);

  const [editing, setEditing] = useState<boolean>(false);
  const [description, setDescription] = useState<string>('');
  const [value, setValue] = useState<string>('');
  const [date, setDate] = useState<string>('');
  const [payerId, setPayerId] = useState<string>('');
  const [participantIds, setParticipantIds] = useState<number[]>([]);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saving, setSaving] = useState<boolean>(false);

  const loadExpense = () => {
    if (!groupId || !expenseId) return;

    setLoading(true);
    setNotFound(false);

    const token = localStorage.getItem('accessToken');
    axios
      .get<ExpenseDetail>(`${API_BASE_URL}/api/expenses/${expenseId}`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(res => setExpense(res.data))
      .catch(err => {
        console.error('Erro ao carregar despesa:', err);
        if (err.response?.status === 401) {
          navigate('/', { replace: true });
          return;
        }
        setNotFound(true);
      })
      .finally(() => setLoading(false));
  };

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(loadExpense, [groupId, expenseId, navigate]);

  useEffect(() => {
    if (!groupId) return;

    const token = localStorage.getItem('accessToken');
    axios
      .get<GroupMember[]>(`${API_BASE_URL}/api/groups/${groupId}/members`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(res => setMembers(res.data))
      .catch(err => console.error('Erro ao carregar membros do grupo:', err));
  }, [groupId]);

  const creditorName = (expense && members.find(m => m.id === expense.user_payer_id)?.name) ?? '-';

  const startEditing = () => {
    if (!expense) return;

    setDescription(expense.description);
    setValue(String(expense.total_value));
    setDate(expense.date_payment);
    setPayerId(String(expense.user_payer_id));
    setParticipantIds(expense.payers.map(p => p.id));
    setSaveError(null);
    setEditing(true);
  };

  const toggleParticipant = (memberId: number) => {
    setParticipantIds(prev =>
      prev.includes(memberId) ? prev.filter(id => id !== memberId) : [...prev, memberId]
    );
  };

  const handleSave = () => {
    if (!expenseId) return;

    const valueNumber = parseFloat(value.replace('.', '').replace(',', '.'));

    if (!description || isNaN(valueNumber) || !payerId || participantIds.length === 0) {
      setSaveError('Preencha descrição, valor, credor e ao menos um pagador.');
      return;
    }

    setSaving(true);
    setSaveError(null);

    const token = localStorage.getItem('accessToken');

    axios
      .put(
        `${API_BASE_URL}/api/expenses/${expenseId}`,
        {
          description,
          date_payment: date,
          total_value: valueNumber,
          user_payer_id: Number(payerId),
          payers: participantIds
        },
        { headers: { Authorization: token ? `Bearer ${token}` : '' } }
      )
      .then(() => {
        setEditing(false);
        loadExpense();
      })
      .catch(err => {
        console.error('Erro ao salvar despesa:', err);
        // O backend valida as regras de domínio (competência fechada, valor de
        // despesa já paga) — o cliente só exibe o motivo que a API devolveu.
        setSaveError(err.response?.data?.error ?? 'Falha ao salvar despesa.');
      })
      .finally(() => setSaving(false));
  };

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
            Essa despesa não existe mais, ou você não tem acesso a ela.
          </Typography>
          <MuiLink component={Link} to={`/groups/${groupId}/expenses`}>
            Voltar para a listagem
          </MuiLink>
        </CardContent>
      </Card>
    );
  }

  if (editing) {
    return (
      <Card elevation={3} sx={{ borderRadius: 2, maxWidth: 560, mx: 'auto' }}>
        <CardContent sx={{ p: 4 }}>
          <Typography variant="h6" gutterBottom>
            Editar despesa
          </Typography>

          {saveError && (
            <Alert severity="error" sx={{ mb: 2 }}>
              {saveError}
            </Alert>
          )}

          <Box display="flex" flexDirection="column" gap={2} mt={2}>
            <TextField
              label="Descrição"
              fullWidth
              value={description}
              onChange={e => setDescription(e.target.value)}
            />

            <TextField
              label="Valor"
              fullWidth
              value={value}
              onChange={e => setValue(e.target.value)}
              placeholder="Ex: 150,00"
            />

            <TextField
              label="Data"
              type="date"
              fullWidth
              value={date}
              onChange={e => setDate(e.target.value)}
              InputLabelProps={{ shrink: true }}
            />

            <TextField label="Credor" select fullWidth value={payerId} onChange={e => setPayerId(e.target.value)}>
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

            <Box display="flex" gap={2} mt={1}>
              <Button variant="contained" onClick={handleSave} disabled={saving}>
                Salvar
              </Button>
              <Button variant="outlined" onClick={() => setEditing(false)} disabled={saving}>
                Cancelar
              </Button>
            </Box>
          </Box>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card elevation={3} sx={{ borderRadius: 2, maxWidth: 560, mx: 'auto' }}>
      <CardContent sx={{ p: 4 }}>
        <Box display="flex" justifyContent="space-between" alignItems="flex-start" mb={2}>
          <Typography variant="h6">{expense.description}</Typography>
          <Chip label={typeLabel[expense.expense_type]} size="small" />
        </Box>

        <Typography variant="h5" color="primary" gutterBottom>
          R$ {formatMoney(Number(expense.total_value))}
        </Typography>

        <Typography color="text.secondary">
          {new Date(expense.date_payment).toLocaleDateString('pt-BR')}
        </Typography>
        <Typography color="text.secondary" sx={{ mb: 3 }}>
          Credor: {creditorName}
        </Typography>

        <Box display="flex" gap={2}>
          <Button variant="contained" onClick={startEditing}>
            Editar
          </Button>
          <Button variant="outlined" component={Link} to={`/groups/${groupId}/expenses`}>
            Voltar para a listagem
          </Button>
        </Box>
      </CardContent>
    </Card>
  );
};

export default ExpenseView;
