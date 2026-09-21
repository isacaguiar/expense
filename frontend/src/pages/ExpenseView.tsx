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
  Tooltip,
  Typography
} from '@mui/material';
import { Link, useNavigate, useParams } from 'react-router-dom';
import ArrowBackIosNewIcon from '@mui/icons-material/ArrowBackIosNew';
import { brandColors } from '../theme/brandColors';
import DespesasThemeScope from '../theme/DespesasThemeScope';
import { buildInstallmentQuotas } from '../utils/installments';
import UserAvatar from '../components/UserAvatar';
import type { ExpenseType } from '../types/expense';
import type { GroupMember } from '../types/group';

type ExpenseQuota = {
  number: number;
  date_expected: string;
  paid: boolean;
  payment_proof_url: string | null;
  // O cast `decimal:2` do backend serializa número como string ("292.40"),
  // mesmo caso de `total_value` abaixo — sempre passar por Number().
  value_quota: string | number;
};

type ExpenseDetail = {
  id: number;
  description: string;
  total_value: string | number;
  date_payment: string;
  expense_type: ExpenseType;
  installments: number;
  user_payer_id: number;
  payers: GroupMember[];
  quotas: ExpenseQuota[];
};

/**
 * Uma despesa pode ter várias Quota (uma por mês, em IN_INSTALLMENTS/FIXED)
 * — sem seletor de competência nesta tela, mostramos o comprovante da quota
 * paga mais recente (a mais relevante pra conferir "já paguei isso?"), não
 * necessariamente a do ciclo vigente. Ver
 * docs/feature/concluidas/202608/20260825-pagamentos-grid-pix/specify.md §2.8.
 */
const latestPaidProof = (quotas: ExpenseQuota[] | undefined): string | null => {
  const paidWithProof = (quotas ?? [])
    .filter(q => q.paid && q.payment_proof_url)
    .sort((a, b) => (a.date_expected < b.date_expected ? 1 : -1));

  return paidWithProof[0]?.payment_proof_url ?? null;
};

/**
 * Regra pedida pelo usuário: despesa parcelada com qualquer parcela paga
 * trava a edição inteira ("só permite alteração no mês de cadastro") — mesmo
 * gatilho que o backend usa em ExpenseController::update() (specify.md §R3
 * de docs/feature/concluidas/202608/20260826-editar-tipo-despesa/).
 */
const isInstallmentsLocked = (expense: ExpenseDetail): boolean =>
  expense.expense_type === 'IN_INSTALLMENTS' && expense.quotas.some(q => q.paid);

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * `GET /api/expenses/{id}` devolve o model cru, e o cast `date` do Laravel
 * serializa em ISO-8601 com hora e Z — verificado no model:
 * `(new Expense(['date_payment' => '2026-08-01']))->toJson()` dá
 * `"2026-08-01T00:00:00.000000Z"`. `new Date()` dessa string (ou de
 * 'YYYY-MM-DD') é meia-noite **UTC** e, em fuso negativo como
 * America/Sao_Paulo, cai no dia anterior ao formatar — é o bug do item de
 * backlog 013, que a TASK-133 já tirou dos outros arquivos que formatam data
 * (ExpenseManager, GroupSummary, GroupReports, CycleDetailPanel,
 * GroupGrossDebtsPanel, Payments, CycleClosingAlert). Esta tela tinha ficado
 * de fora.
 *
 * Cortar em 10 caracteres monta a data a partir das partes, no fuso local, e
 * atende os dois formatos — não depende de qual deles a API manda.
 * Ver docs/feature/concluidas/202609/20260912-expense-view-tipo-e-pagadores/plan.md §2.
 */
const parseLocalDate = (dateStr: string): Date => {
  const [year, month, day] = dateStr.slice(0, 10).split('-').map(Number);

  return new Date(year, month - 1, day);
};

/**
 * `mm/aaaa` e não `{ month: 'short' }`: em pt-BR o formato curto rende
 * "mai. de 2026" — longo e com ponto no meio, ruim numa linha de parcela.
 */
const formatMonth = (dateStr: string): string =>
  parseLocalDate(dateStr).toLocaleDateString('pt-BR', { month: '2-digit', year: 'numeric' });

/**
 * O rateio é sempre igualitário: não existe coluna de valor nem de percentual
 * na pivot `ex_expenses_payers`. Mesmo cálculo que o backend usa para
 * `valuePerPerson` no summary — `round($entry['value'] / max($payers->count(), 1), 2)`
 * —, inclusive o `max(…, 1)`, que evita divisão por zero numa despesa sem
 * pagador.
 */
const perPersonValue = (value: number, payersCount: number): number =>
  Math.round((value / Math.max(payersCount, 1)) * 100) / 100;

/**
 * Mesmos rótulos do modal "Detalhes da despesa" do ExpenseManager
 * (`detailTypeLabel`) — as duas telas mostram a mesma despesa e não podem
 * divergir; antes, `IN_CASH` e `IN_INSTALLMENTS` caíam os dois em "Variável".
 *
 * Diferença deliberada em relação ao modal: lá o chip diz "Parcelada 3/6",
 * porque o backend já escolheu a parcela da competência; aqui a rota não
 * recebe `cycles_ago`, então o chip traz só o total de parcelas — qual
 * parcela é qual fica na lista de parcelas.
 * Ver docs/feature/concluidas/202609/20260912-expense-view-tipo-e-pagadores/plan.md §1.
 */
const typeLabel = (expense: ExpenseDetail): string => {
  if (expense.expense_type === 'FIXED') return 'Fixa';
  if (expense.expense_type === 'IN_INSTALLMENTS') return `Parcelada (${expense.installments}x)`;

  return 'À Vista';
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
  const [expenseType, setExpenseType] = useState<ExpenseType>('IN_CASH');
  const [installmentsCount, setInstallmentsCount] = useState<string>('');
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

  const creditor = expense ? members.find(m => m.id === expense.user_payer_id) : undefined;
  const creditorName = creditor?.name ?? '-';

  const startEditing = () => {
    if (!expense) return;

    setDescription(expense.description);
    // handleSave() faz parse de `value` como número digitado em pt-BR (ponto =
    // separador de milhar, vírgula = decimal — mesmo formato do placeholder
    // "Ex: 150,00"). expense.total_value vem da API em formato de máquina
    // ("100.00", decimal:2 do backend) — pré-preencher com esse valor cru faz
    // o parser ler o ponto decimal como separador de milhar e multiplicar o
    // valor por ~100 se o usuário salvar sem tocar no campo. Formatando aqui
    // pro mesmo padrão pt-BR que o parser espera.
    setValue(Number(expense.total_value).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    setDate(expense.date_payment);
    setPayerId(String(expense.user_payer_id));
    setParticipantIds(expense.payers.map(p => p.id));
    setExpenseType(expense.expense_type);
    setInstallmentsCount(expense.expense_type === 'IN_INSTALLMENTS' ? String(expense.installments) : '');
    setSaveError(null);
    setEditing(true);
  };

  const toggleParticipant = (memberId: number) => {
    setParticipantIds(prev =>
      prev.includes(memberId) ? prev.filter(id => id !== memberId) : [...prev, memberId]
    );
  };

  const handleSave = () => {
    if (!expenseId || !expense) return;

    const valueNumber = parseFloat(value.replace('.', '').replace(',', '.'));

    if (!description || isNaN(valueNumber) || !payerId || participantIds.length === 0) {
      setSaveError('Preencha descrição, valor, credor e ao menos um pagador.');
      return;
    }

    // O tipo só é editável (e só entra no payload) pra despesas que já não são
    // Fixa — ver isso espelhado em §R2 do specify: mudar de/para Fixa não é
    // suportado, então nem mostramos o campo pra ela (ver JSX abaixo).
    let typeFields: { expense_type: ExpenseType; installments: number; quotas: ReturnType<typeof buildInstallmentQuotas> } | null = null;

    if (expense.expense_type !== 'FIXED') {
      if (expenseType === 'IN_INSTALLMENTS') {
        const count = parseInt(installmentsCount, 10);
        if (!Number.isInteger(count) || count < 2) {
          setSaveError('Informe uma quantidade de parcelas válida (mínimo 2 — para 1 parcela use À Vista).');
          return;
        }
        typeFields = { expense_type: 'IN_INSTALLMENTS', installments: count, quotas: buildInstallmentQuotas(valueNumber, count, date) };
      } else {
        typeFields = {
          expense_type: 'IN_CASH',
          installments: 1,
          quotas: [{ number: 1, date_expected: date, paid: false, value_quota: valueNumber }]
        };
      }
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
          payers: participantIds,
          ...typeFields
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
        // despesa já paga, parcelada com 1ª parcela já paga) — o cliente só
        // exibe o motivo que a API devolveu.
        setSaveError(err.response?.data?.error ?? 'Falha ao salvar despesa.');
      })
      .finally(() => setSaving(false));
  };

  const dateFieldLabel = expenseType === 'IN_INSTALLMENTS' ? 'Mês de início das parcelas' : 'Data';

  // Um único DespesasThemeScope embrulha qualquer que seja o conteúdo abaixo
  // (nunca um por branch) — evita recriar o ThemeProvider a cada transição de
  // estado (loading/notFound/editing/visualização), que causava reconciliação
  // instável dos botões da Card (ver docs/feature/concluidas/202608/20260825-redesign-visual-despesas/implementation.md).
  let content: React.ReactNode;

  if (loading) {
    content = (
      <Box display="flex" justifyContent="center" mt={4}>
        <CircularProgress />
      </Box>
    );
  } else if (notFound || !expense) {
    content = (
      <Card elevation={0} sx={{ maxWidth: 560, mx: 'auto' }}>
        <CardContent sx={{ p: 4 }}>
          <Typography variant="h6" fontWeight={700} gutterBottom>
            Despesa não encontrada
          </Typography>
          <Typography color="text.secondary" sx={{ mb: 3 }}>
            Essa despesa não existe mais, ou você não tem acesso a ela.
          </Typography>
          <MuiLink component={Link} to={`/groups/${groupId}/expenses`} sx={{ fontWeight: 600 }}>
            Voltar para a listagem
          </MuiLink>
        </CardContent>
      </Card>
    );
  } else if (editing) {
    content = (
      <Card elevation={0} sx={{ maxWidth: 560, mx: 'auto' }}>
        <CardContent sx={{ p: 4 }}>
          <Typography variant="h6" fontWeight={700} gutterBottom>
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

            {/* Fixa fica de fora — converter de/pra ela não é suportado
                (docs/feature/concluidas/202608/20260826-editar-tipo-despesa/specify.md §R2) */}
            {expense.expense_type !== 'FIXED' && (
              <TextField
                label="Tipo de despesa"
                select
                fullWidth
                value={expenseType}
                onChange={e => setExpenseType(e.target.value as ExpenseType)}
              >
                <MenuItem value="IN_CASH">À Vista</MenuItem>
                <MenuItem value="IN_INSTALLMENTS">Parcelada</MenuItem>
              </TextField>
            )}

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

            <TextField label="Credor" select fullWidth value={payerId} onChange={e => setPayerId(e.target.value)}>
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
  } else {
    const proofUrl = latestPaidProof(expense.quotas);
    // Só parcelada tem cronograma: IN_CASH tem uma quota só (o valor já está no
    // destaque do topo) e FIXED nasce sem Quota na competência — o `quotas[]`
    // dela é esparso (os meses em que alguém pagou), não um cronograma. A
    // condição é o tipo, nunca "tem mais de uma quota".
    // Ver docs/feature/concluidas/202609/20260912-expense-view-tipo-e-pagadores/specify.md §2.6.
    const showInstallments = expense.expense_type === 'IN_INSTALLMENTS';

    content = (
      <Card elevation={0} sx={{ maxWidth: 560, mx: 'auto' }}>
        <CardContent sx={{ p: 4 }}>
          <Box display="flex" justifyContent="space-between" alignItems="flex-start" mb={2}>
            <Typography variant="h6" fontWeight={700}>
              {expense.description}
            </Typography>
            <Chip label={typeLabel(expense)} size="small" sx={{ bgcolor: brandColors.primaryLight, color: brandColors.primaryDark }} />
          </Box>

          <Typography variant="h5" color="primary" fontWeight={700} gutterBottom>
            R$ {formatMoney(Number(expense.total_value))}
          </Typography>

          <Typography color="text.secondary">
            {parseLocalDate(expense.date_payment).toLocaleDateString('pt-BR')}
          </Typography>
          {/* A seção de pagadores sempre vem depois daqui, então o espaçamento
              apertado (mb: 1) que existia quando o link de comprovante vinha
              logo a seguir migrou para a última seção antes dele. */}
          <Box display="flex" alignItems="center" gap={1} sx={{ mb: 3 }}>
            <Typography color="text.secondary">Credor:</Typography>
            <UserAvatar name={creditorName} avatarUrl={creditor?.avatar_url} sx={{ width: 24, height: 24, fontSize: '0.7rem' }} />
            <Typography color="text.secondary">{creditorName}</Typography>
          </Box>

          {showInstallments && (
            <Box sx={{ mb: 3 }}>
              <Typography variant="body2" color="text.secondary">
                Parcelas
              </Typography>
              {/* O valor em destaque no topo é o total da compra; aqui cada
                  linha traz o valor da parcela. Esta legenda amarra os dois. */}
              <Typography variant="caption" color="text.secondary" display="block" sx={{ mb: 1 }}>
                Total da despesa: R$ {formatMoney(Number(expense.total_value))} em {expense.installments}x
              </Typography>

              <Box display="flex" flexDirection="column" gap={1}>
                {/* Ordena por `number`, não por `date_expected`: `number` é a
                    identidade da parcela e é o que o rótulo "n/N" afirma. Datas
                    de parcela podem ter sido deslocadas em massa em produção
                    (ver as TASK-003/004 de
                    docs/feature/concluidas/202609/20260904-detalhe-despesa-tipo-parcela-valores/),
                    e ordenar por data deixaria a numeração fora de ordem. */}
                {[...expense.quotas]
                  .sort((a, b) => a.number - b.number)
                  .map(quota => (
                    <Box key={quota.number} display="flex" alignItems="center" gap={1}>
                      <Typography variant="body2" fontWeight={600} sx={{ minWidth: 36 }}>
                        {quota.number}/{expense.installments}
                      </Typography>
                      <Typography variant="body2" color="text.secondary" flexGrow={1}>
                        {formatMonth(quota.date_expected)}
                      </Typography>
                      <Box textAlign="right">
                        <Typography variant="body2">R$ {formatMoney(Number(quota.value_quota))}</Typography>
                        <Typography variant="caption" color="text.secondary" display="block">
                          R$ {formatMoney(perPersonValue(Number(quota.value_quota), expense.payers.length))} por pessoa
                        </Typography>
                      </Box>
                      <Chip
                        label={quota.paid ? 'Paga' : 'Pendente'}
                        color={quota.paid ? 'success' : 'warning'}
                        size="small"
                      />
                    </Box>
                  ))}
              </Box>
            </Box>
          )}

          {/* Mesmo formato da lista de pagadores do modal "Detalhes da despesa"
              (`renderDetailPayers` em ExpenseManager.tsx): avatar + nome, o
              credor marcado, valor à direita. O valor aqui é a cota da pessoa
              no total da despesa — o valor por parcela já está em cada linha do
              cronograma acima, então nenhum número aparece duas vezes com base
              diferente. Ver specify.md §3.3. */}
          <Box sx={{ mb: proofUrl ? 1 : 3 }}>
            <Typography variant="body2" color="text.secondary" gutterBottom>
              Pagadores
            </Typography>

            <Box display="flex" flexDirection="column" gap={1}>
              {expense.payers.map(payer => (
                <Box key={payer.id} display="flex" alignItems="center" gap={1}>
                  <UserAvatar name={payer.name} avatarUrl={payer.avatar_url} sx={{ width: 28, height: 28, fontSize: '0.75rem' }} />
                  <Typography variant="body2" flexGrow={1}>
                    {payer.name}
                    {payer.id === expense.user_payer_id && (
                      <Typography component="span" variant="caption" color="text.secondary">
                        {' '}
                        (credor)
                      </Typography>
                    )}
                  </Typography>
                  <Typography variant="body2" fontWeight={600}>
                    R$ {formatMoney(perPersonValue(Number(expense.total_value), expense.payers.length))}
                  </Typography>
                </Box>
              ))}
            </Box>
          </Box>

          {proofUrl && (
            <Typography sx={{ mb: 3 }}>
              <MuiLink href={proofUrl} target="_blank" rel="noreferrer">
                Ver comprovante
              </MuiLink>
            </Typography>
          )}

          <Box display="flex" gap={2}>
            <Tooltip
              title={
                isInstallmentsLocked(expense)
                  ? 'Despesa parcelada com a 1ª parcela já paga não pode mais ser editada.'
                  : ''
              }
            >
              <span>
                <Button variant="contained" onClick={startEditing} disabled={isInstallmentsLocked(expense)}>
                  Editar
                </Button>
              </span>
            </Tooltip>
            <Button
              variant="outlined"
              startIcon={<ArrowBackIosNewIcon sx={{ fontSize: 14 }} />}
              component={Link}
              to={`/groups/${groupId}/expenses`}
            >
              Voltar para a listagem
            </Button>
          </Box>
        </CardContent>
      </Card>
    );
  }

  return <DespesasThemeScope>{content}</DespesasThemeScope>;
};

export default ExpenseView;
