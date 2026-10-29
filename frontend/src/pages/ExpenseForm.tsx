import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { API_BASE_URL } from '../config';
import { trackEvent } from '../analytics/trackEvent';
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
import UserAvatar from '../components/UserAvatar';
import PaymentProofFields from '../components/PaymentProofFields';
import {
  buildExpenseBody,
  describeSaveError,
  EXPENSE_SAVE_TIMEOUT_MS,
  proofFileProblem,
  type ExpensePayload
} from '../utils/expenseRequest';
import { describePaymentOccurrence } from '../utils/paymentOccurrence';
import { newRequestKey } from '../utils/requestKey';
import type { ExpenseType } from '../types/expense';
import type { GroupMember } from '../types/group';

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

  // Pagamento registrado no cadastro (docs/feature/20261027-pagamento-no-cadastro-despesa):
  // foto e/ou descrição do pagamento, ambas opcionais. `proofInputKey` recria o <input>
  // nativo quando a foto é descartada, para escolher o mesmo arquivo de novo.
  const [proofFile, setProofFile] = useState<File | null>(null);
  const [proofInputKey, setProofInputKey] = useState<number>(0);
  const [proofProblem, setProofProblem] = useState<string | null>(null);
  const [paymentNote, setPaymentNote] = useState<string>('');

  // Chave de idempotência do cadastro: uma por tela aberta, enviada em todo envio e em todo
  // reenvio, mesmo depois de o usuário editar campos. É o que faz o servidor devolver a
  // mesma despesa, em vez de criar outra, quando a resposta se perdeu (R23).
  const requestKeyRef = useRef<string | null>(null);
  if (requestKeyRef.current === null) {
    requestKeyRef.current = newRequestKey();
  }

  // Trava síncrona contra envio repetido: `saving` é estado e só vale depois da próxima
  // renderização, então dois cliques seguidos ainda passariam por ele.
  const submittingRef = useRef<boolean>(false);

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

  const handleProofChange = (chosen: File | null) => {
    if (chosen === null) {
      setProofFile(null);
      setProofProblem(null);
      return;
    }

    const problem = proofFileProblem(chosen);

    if (problem) {
      // O arquivo recusado não fica escolhido; uma foto válida anterior continua valendo.
      setProofProblem(problem);
      setProofInputKey(key => key + 1);
      return;
    }

    setProofProblem(null);
    setProofFile(chosen);
  };

  const handleRemoveProof = () => {
    setProofFile(null);
    setProofProblem(null);
    setProofInputKey(key => key + 1);
  };

  const handleSave = () => {
    if (!groupId || submittingRef.current) return;

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

    // Descrição só com espaços não é pagamento: o campo nem vai (R2). Com foto, o corpo é
    // `multipart`; sem foto, o JSON de sempre.
    const trimmedNote = paymentNote.trim();

    const payload: ExpensePayload = {
      date_payment: date,
      description,
      expense_type: expenseType,
      installments,
      total_value: valueNumber,
      group_id: Number(groupId),
      user_creator_id: currentUserId,
      user_payer_id: Number(payerId),
      payers: participantIds,
      quotas,
      ...(trimmedNote ? { descricao_pagamento: trimmedNote } : {}),
      client_request_id: requestKeyRef.current as string
    };

    submittingRef.current = true;
    setSaving(true);

    axios
      .post(`${API_BASE_URL}/api/expenses`, buildExpenseBody(payload, proofFile), {
        headers: { Authorization: token ? `Bearer ${token}` : '' },
        timeout: EXPENSE_SAVE_TIMEOUT_MS
      })
      .then(() => {
        trackEvent('expense_created');
        navigate(`/groups/${groupId}/expenses`);
      })
      .catch(err => {
        console.error('Erro ao salvar despesa:', err);
        // O backend valida as regras de domínio (competência fechada, soma das
        // quotas, pagador não-membro) — o cliente exibe o motivo que a API devolveu,
        // no mesmo padrão de ExpenseView.tsx, e acrescenta só o que ela não pode
        // dizer (413, timeout, falta de resposta). Campos, foto e chave ficam como estão.
        setSaveError(describeSaveError(err));
      })
      .finally(() => {
        submittingRef.current = false;
        setSaving(false);
      });
  };

  const paymentRequested = proofFile !== null || paymentNote.trim() !== '';
  const payerMember = members.find(member => String(member.id) === payerId);
  const occurrence = describePaymentOccurrence({
    expenseType,
    installments: parseInt(installmentsCount, 10),
    date,
    payerName: payerMember?.name ?? '',
    isSelf: currentUserId !== null && payerId === String(currentUserId)
  });

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
                  <Box display="flex" alignItems="center" gap={1}>
                    <UserAvatar name={member.name} avatarUrl={member.avatar_url} size={24} sx={{ fontSize: '0.7rem' }} />
                    {member.name}
                  </Box>
                </MenuItem>
              ))}
            </TextField>

            <Box>
              <FormLabel component="legend">Quem participa desta despesa?</FormLabel>
              <FormGroup>
                {members.map(member => (
                  <Box key={member.id} display="flex" alignItems="center" gap={1}>
                    <UserAvatar name={member.name} avatarUrl={member.avatar_url} size={24} sx={{ fontSize: '0.7rem' }} />
                    <FormControlLabel
                      control={
                        <Checkbox
                          checked={participantIds.includes(member.id)}
                          onChange={() => toggleParticipant(member.id)}
                        />
                      }
                      label={member.name}
                    />
                  </Box>
                ))}
              </FormGroup>
            </Box>

            <Box>
              <Typography variant="subtitle2" fontWeight={700} gutterBottom>
                Pagamento (opcional)
              </Typography>
              <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
                Se esta despesa já foi paga, anexe a foto do comprovante e/ou descreva o pagamento: ela será
                cadastrada já paga.
              </Typography>

              <PaymentProofFields
                file={proofFile}
                onFileChange={handleProofChange}
                note={paymentNote}
                onNoteChange={setPaymentNote}
                photoLabel="Anexar foto do comprovante"
                noteLabel="Descrição do pagamento (opcional)"
                inputKey={proofInputKey}
                disabled={saving}
              />

              {proofFile && (
                <Button size="small" onClick={handleRemoveProof} disabled={saving}>
                  Remover foto
                </Button>
              )}

              {proofProblem && (
                <Alert severity="warning" role="status" sx={{ mt: 1 }}>
                  {proofProblem}
                </Alert>
              )}

              {paymentRequested && (
                <Alert severity="info" role="status" sx={{ mt: 1.5 }}>
                  <Typography variant="body2">{occurrence.summary}</Typography>
                  {payerMember && occurrence.onBehalf && (
                    <Typography variant="body2" sx={{ mt: 0.5 }}>
                      {occurrence.onBehalf}
                    </Typography>
                  )}
                  <Typography variant="body2" sx={{ mt: 0.5 }}>
                    {occurrence.warning}
                  </Typography>
                </Alert>
              )}
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
