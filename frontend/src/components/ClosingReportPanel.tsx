import React, { useId } from 'react';
import { Box, Button, Card, CardContent, Chip, CircularProgress, GlobalStyles, Stack, Typography } from '@mui/material';
import HourglassEmptyIcon from '@mui/icons-material/HourglassEmpty';
import PaidOutlinedIcon from '@mui/icons-material/PaidOutlined';
import PrintOutlinedIcon from '@mui/icons-material/PrintOutlined';
import UpdateIcon from '@mui/icons-material/Update';
import { useClosingReport, type ClosingReport } from '../hooks/useClosingReport';
import CycleExpenseList from './CycleExpenseList';
import CycleTotalsCards from './CycleTotalsCards';
import SettlementList from './SettlementList';
import UserAvatar from './UserAvatar';
import { sortExpensesByOccurrence } from '../utils/cycleOrdering';

// new Date('YYYY-MM-DD') interpreta a string como UTC-meia-noite, o que desloca
// a data em 1 dia para trás em fusos negativos (ex.: America/Sao_Paulo) —
// construímos a partir dos componentes locais para evitar isso.
const formatFullDate = (dateStr: string): string => {
  const [year, month, day] = dateStr.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('pt-BR');
};

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

type ReportStatus = {
  label: string;
  color: 'info' | 'warning' | 'success';
  icon: React.ReactElement;
};

const PREVIEW: ReportStatus = { label: 'Prévia', color: 'info', icon: <UpdateIcon fontSize="small" /> };
const WAITING: ReportStatus = {
  label: 'Aguardando pagamentos',
  color: 'warning',
  icon: <HourglassEmptyIcon fontSize="small" />,
};
const SETTLED: ReportStatus = { label: 'Quitado', color: 'success', icon: <PaidOutlinedIcon fontSize="small" /> };

/**
 * Prévia = ciclo que ainda não fechou (`open`, inclusive na carência): os
 * valores podem mudar, como no chip de `SummarySidePanel`. Ciclo futuro nem
 * chega aqui (o endpoint devolve 404). Fechado, é Quitado
 * quando o ciclo está selado ou, sem esperar o selo, quando nada falta pagar e
 * todo acerto tem comprovante confirmado (a regra de `summaryIsFullySettled`
 * no backend); senão, Aguardando pagamentos.
 */
const reportStatus = ({ cycle, totals, settlements }: ClosingReport): ReportStatus => {
  if (cycle.status === 'open') return PREVIEW;

  const nothingToPay = Math.round(totals.pending * 100) <= 0;
  const allConfirmed = settlements.every(settlement => Boolean(settlement.confirmedAt));

  return cycle.settled || (nothingToPay && allConfirmed) ? SETTLED : WAITING;
};

// Só vale na impressão: esconde o que leva `no-print` (menu, cabeçalho do grupo,
// abas, botões), deixa a página branca e fixa a margem da folha.
const printStyles = {
  '@media print': {
    '.no-print': { display: 'none !important' },
    body: { backgroundColor: '#fff !important' },
    '@page': { margin: '12mm' },
  },
};

type ClosingReportPanelProps = {
  groupId: string | undefined;
  // Início do ciclo (YYYY-MM-DD), exatamente como a lista o devolveu.
  cycleStart: string;
};

/**
 * Relatório de Fechamento de um ciclo: cabeçalho (grupo, período, status, data
 * de emissão), totais, valores brutos por credor, acertos líquidos com o status
 * de cada um e as despesas. Imprime pelo navegador (`window.print`), sem
 * biblioteca de PDF: o usuário escolhe "Salvar como PDF" no diálogo.
 */
const ClosingReportPanel: React.FC<ClosingReportPanelProps> = ({ groupId, cycleStart }) => {
  const { report, loading, error } = useClosingReport(groupId, cycleStart);
  const grossId = useId();
  const settlementsId = useId();

  return (
    <>
      <GlobalStyles styles={printStyles} />

      {loading ? (
        <Box display="flex" justifyContent="center" mt={4}>
          <CircularProgress />
        </Box>
      ) : error ? (
        <Typography color="error">{error}</Typography>
      ) : report ? (
        <ReportBody report={report} grossId={grossId} settlementsId={settlementsId} />
      ) : null}
    </>
  );
};

type ReportBodyProps = {
  report: ClosingReport;
  grossId: string;
  settlementsId: string;
};

const ReportBody: React.FC<ReportBodyProps> = ({ report, grossId, settlementsId }) => {
  const status = reportStatus(report);

  return (
    <>
      <Box display="flex" justifyContent="space-between" alignItems="flex-start" flexWrap="wrap" gap={2} mb={3}>
        <Box>
          <Typography variant="h5" component="h2">
            {report.group.name}
          </Typography>
          <Typography color="text.secondary">
            Período: {formatFullDate(report.cycle.start)} a {formatFullDate(report.cycle.end)}
          </Typography>
          <Typography variant="body2" color="text.secondary">
            Emitido em {new Date().toLocaleDateString('pt-BR')}
          </Typography>
        </Box>

        <Box display="flex" flexDirection="column" alignItems="flex-end" gap={1}>
          <Chip size="small" variant="outlined" color={status.color} icon={status.icon} label={status.label} />
          <Button
            className="no-print"
            variant="outlined"
            size="small"
            startIcon={<PrintOutlinedIcon />}
            onClick={() => window.print()}
          >
            Imprimir / Salvar PDF
          </Button>
        </Box>
      </Box>

      {report.cycle.in_grace && (
        <Typography variant="body2" color="text.secondary" mb={3}>
          Em carência até {formatFullDate(report.cycle.closes_at)}: os valores ainda podem mudar.
        </Typography>
      )}

      <CycleTotalsCards totals={report.totals} cycle={report.cycle} />

      <Box component="section" aria-labelledby={grossId} mb={3}>
        <Typography id={grossId} variant="h6" component="h3" gutterBottom>
          Valores por despesa (bruto)
        </Typography>
        {report.creditors.length === 0 ? (
          <Typography color="text.secondary">Nenhum valor a dividir neste ciclo.</Typography>
        ) : (
          <Stack component="ul" spacing={1.5} sx={{ listStyle: 'none', m: 0, p: 0 }}>
            {report.creditors.map(({ creditor, advanced_total, debtors }, index) => (
              <Card key={`${creditor.id ?? creditor.name}-${index}`} component="li" variant="outlined">
                <CardContent sx={{ '&:last-child': { pb: 2 } }}>
                  <Box display="flex" alignItems="center" gap={1.5} mb={debtors.length > 0 ? 1.5 : 0}>
                    <UserAvatar name={creditor.name} avatarUrl={creditor.avatarUrl} />
                    <Box>
                      <Typography fontWeight={700}>{creditor.name}</Typography>
                      <Typography variant="body2" color="text.secondary">
                        Adiantou R$ {formatMoney(advanced_total)}
                      </Typography>
                    </Box>
                  </Box>
                  {debtors.length === 0 ? (
                    <Typography variant="body2" color="text.secondary">
                      Sem devedores neste ciclo.
                    </Typography>
                  ) : (
                    <Stack spacing={1} pl={1}>
                      {debtors.map((debtor, position) => (
                        <Box key={`${debtor.id ?? debtor.name}-${position}`} display="flex" alignItems="center" gap={1}>
                          <UserAvatar
                            name={debtor.name}
                            avatarUrl={debtor.avatarUrl}
                            size={28}
                            sx={{ fontSize: '0.75rem' }}
                          />
                          <Typography variant="body2">
                            {debtor.name} deve R$ {formatMoney(debtor.amount)}
                          </Typography>
                        </Box>
                      ))}
                    </Stack>
                  )}
                </CardContent>
              </Card>
            ))}
          </Stack>
        )}
      </Box>

      <Box component="section" aria-labelledby={settlementsId} mb={3}>
        <Typography id={settlementsId} variant="h6" component="h3" gutterBottom>
          Acertos (líquido entre pares)
        </Typography>
        <Typography variant="body2" color="text.secondary" mb={1.5}>
          O líquido desconta o que cada par deve um ao outro, por isso os acertos podem diferir dos valores brutos
          acima.
        </Typography>
        {report.settlements.length === 0 ? (
          <Typography color="text.secondary">Nenhum acerto entre os membros neste ciclo.</Typography>
        ) : (
          <SettlementList settlements={report.settlements} balances={report.balances} showConfirmation />
        )}
      </Box>

      <Box component="section" aria-label="Despesas do ciclo">
        {/* O snapshot de um ciclo selado está congelado em ordem crescente; a exibição reordena
            pela data da ocorrência, mais recente primeiro, sem tocar nele. */}
        <CycleExpenseList expenses={sortExpensesByOccurrence(report.expenses)} />
      </Box>
    </>
  );
};

export default ClosingReportPanel;
