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
  FormControlLabel,
  FormGroup,
  FormLabel,
  MenuItem,
  TextField,
  Typography
} from '@mui/material';
import { useNavigate, useParams } from 'react-router-dom';
import DespesasThemeScope from '../theme/DespesasThemeScope';
import { buildInstallmentQuotas, InstallmentQuota } from '../utils/installments';

type ExpenseType = 'IN_CASH' | 'IN_INSTALLMENTS' | 'FIXED';

type GroupMember = {
  id: number;
  name: string;
  avatar_url: string | null;
};

type Quota = InstallmentQuota;

const ExpenseForm: React.FC = () => {
  const { id: groupId } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const [members, setMembers] = useState<GroupMember[]>([]);
  const [currentUserId, setCurrentUserId] = useState<number | null>(null);

  const [description, setDescription] = useState<string>('');
  const [value, setValue] = useState<string>('');
  const [date, setDate] = useState<string>(() => new Date().toISOString().slice(0, 10));
  const [payerId, setPayerId] = useState<string>('');
  const [expenseType, setExpenseType] = useState<ExpenseType>('IN_CASH');
  const [installmentsCount, setInstallmentsCount] = useState<string>('');
  const [participantIds, setParticipantIds] = useState<number[]>([]);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saving, setSaving] = useState<boolean>(false);

  useEffect(() => {
    if (!groupId) return;

    const token = localStorage.getItem('accessToken');
    const headers = { Authorization: token ? `Bearer ${token}` : '' };

    axios
      .get<GroupMember[]>(`${API_BASE_URL}/api/groups/${groupId}/members`, { headers })
      .then(res => {
        setMembers(res.data);
        setParticipantIds(res.data.map(member => member.id));
      })
      .catch(err => console.error('Erro ao carregar membros do grupo:', err));

    axios
      .get<{ id: number }>(`${API_BASE_URL}/api/me`, { headers })
      .then(res => {
        setCurrentUserId(res.data.id);
        setPayerId(String(res.data.id));
      })
      .catch(err => console.error('Erro ao carregar usuário autenticado:', err));
  }, [groupId]);

  const toggleParticipant = (memberId: number) => {
    setParticipantIds(prev =>
      prev.includes(memberId) ? prev.filter(id => id !== memberId) : [...prev, memberId]
    );
  };

  const handleSave = () => {
    if (!groupId) return;

    setSaveError(null);

    const valueNumber = parseFloat(value.replace('.', '').replace(',', '.'));

    if (!description || isNaN(valueNumber) || !payerId) {
      setSaveError('Preencha descrição, valor e pagador corretamente.');
      return;
    }

    if (participantIds.length === 0) {
      setSaveError('Selecione ao menos um participante da divisão.');
      return;
    }

    let installments = 1;
    let quotas: Quota[];

    if (expenseType === 'IN_INSTALLMENTS') {
      const count = parseInt(installmentsCount, 10);
      if (!Number.isInteger(count) || count < 2) {
        setSaveError('Informe uma quantidade de parcelas válida (mínimo 2 — para 1 parcela use À Vista).');
        return;
      }
      installments = count;
      quotas = buildInstallmentQuotas(valueNumber, count, date);
    } else if (expenseType === 'FIXED') {
      quotas = [{ number: 1, date_expected: date, paid: false, value_quota: valueNumber }];
    } else {
      quotas = [{ number: 1, date_expected: date, paid: true, value_quota: valueNumber }];
    }

    const token = localStorage.getItem('accessToken');

    const payload = {
      date_payment: date,
      description,
      expense_type: expenseType,
      installments,
      total_value: valueNumber,
      group_id: Number(groupId),
      user_creator_id: currentUserId,
      user_payer_id: Number(payerId),
      payers: participantIds,
      quotas
    };

    setSaving(true);

    axios
      .post(`${API_BASE_URL}/api/expenses`, payload, {
        headers: { Authorization: token ? `Bearer ${token}` : '' }
      })
      .then(() => {
        navigate(`/groups/${groupId}/expenses`);
      })
      .catch(err => {
        console.error('Erro ao salvar despesa:', err);
        // O backend valida as regras de domínio (competência fechada, soma das
        // quotas, pagador não-membro) — o cliente só exibe o motivo que a API
        // devolveu, no mesmo padrão de ExpenseView.tsx.
        setSaveError(err.response?.data?.error ?? 'Falha ao salvar despesa.');
      })
      .finally(() => setSaving(false));
  };

  const dateFieldLabel =
    expenseType === 'IN_INSTALLMENTS'
      ? 'Mês de início das parcelas'
      : expenseType === 'FIXED'
      ? 'Data de início'
      : 'Data';

  return (
    <DespesasThemeScope>
      <Card elevation={0} sx={{ maxWidth: 560, mx: 'auto' }}>
        <CardContent sx={{ p: 4 }}>
          <Typography variant="h6" fontWeight={700} gutterBottom>
            Cadastrar nova despesa
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
              label="Tipo de despesa"
              select
              fullWidth
              value={expenseType}
              onChange={e => setExpenseType(e.target.value as ExpenseType)}
            >
              <MenuItem value="IN_CASH">À Vista</MenuItem>
              <MenuItem value="IN_INSTALLMENTS">Parcelada</MenuItem>
              <MenuItem value="FIXED">Fixa</MenuItem>
            </TextField>

            <TextField
              label={dateFieldLabel}
              type="date"
              fullWidth
              value={date}
              onChange={e => setDate(e.target.value)}
              InputLabelProps={{ shrink: true }}
            />

            {expenseType === 'IN_INSTALLMENTS' && (
              <TextField
                label="Quantidade de parcelas"
                type="number"
                fullWidth
                value={installmentsCount}
                onChange={e => setInstallmentsCount(e.target.value)}
                inputProps={{ min: 2 }}
              />
            )}

            <TextField
              label="Pagador"
              select
              fullWidth
              value={payerId}
              onChange={e => setPayerId(e.target.value)}
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

            <Box display="flex" gap={2} mt={1}>
              <Button variant="contained" onClick={handleSave} disabled={saving}>
                Salvar
              </Button>
              <Button
                variant="outlined"
                onClick={() => navigate(`/groups/${groupId}/expenses`)}
                disabled={saving}
              >
                Cancelar
              </Button>
            </Box>
          </Box>
        </CardContent>
      </Card>
    </DespesasThemeScope>
  );
};

export default ExpenseForm;
