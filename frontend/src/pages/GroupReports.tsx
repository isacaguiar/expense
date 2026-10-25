import React from 'react';
import { Box, Typography, CircularProgress, List, ListItemButton, ListItemText, Paper, Pagination, Tab, Tabs } from '@mui/material';
import { useParams, useSearchParams } from 'react-router-dom';
import { useGroupCycleHistory } from '../hooks/useGroupCycleHistory';
import ClosingReportPanel from '../components/ClosingReportPanel';
import PendingCyclesList from '../components/PendingCyclesList';

// new Date('YYYY-MM-DD') interpreta a string como UTC-meia-noite, o que desloca
// a data em 1 dia para trás em fusos negativos (ex.: America/Sao_Paulo) —
// construímos a partir dos componentes locais para evitar isso.
const formatDate = (dateStr: string): string => {
  const [year, month, day] = dateStr.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' });
};

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

type ReportTab = 'acompanhamento' | 'historico';

// Ausente ou desconhecida cai em Acompanhamento, a aba de abertura.
const parseTab = (value: string | null): ReportTab => (value === 'historico' ? 'historico' : 'acompanhamento');

type CycleHistoryProps = {
  groupId: string | undefined;
  // Início do ciclo aberto no Fechamento (o `?ciclo=` da URL), para marcar o item da lista.
  selectedStart: string | null;
  onSelect: (cycleStart: string) => void;
};

/**
 * A lista paginada dos ciclos já fechados. Fica num componente à parte para o
 * histórico só ser buscado quando a aba Histórico está aberta.
 */
const CycleHistory: React.FC<CycleHistoryProps> = ({ groupId, selectedStart, onSelect }) => {
  const { cycles, loading, error, page, totalPages, setPage } = useGroupCycleHistory(groupId);

  if (loading) {
    return (
      <Box display="flex" justifyContent="center" mt={4}>
        <CircularProgress />
      </Box>
    );
  }

  if (error) return <Typography color="error">{error}</Typography>;

  if (cycles.length === 0) {
    return <Typography color="text.secondary">Nenhum ciclo fechado ainda.</Typography>;
  }

  return (
    <>
      <Paper elevation={3} sx={{ mb: 2 }}>
        <List disablePadding>
          {cycles.map(cycle => (
            <ListItemButton
              key={cycle.cycle.start}
              divider
              selected={selectedStart === cycle.cycle.start}
              onClick={() => onSelect(cycle.cycle.start)}
            >
              <ListItemText
                primary={`${formatDate(cycle.cycle.start)} – ${formatDate(cycle.cycle.end)}`}
                secondary={`Total: R$ ${formatMoney(cycle.totals.total)}`}
              />
            </ListItemButton>
          ))}
        </List>
      </Paper>

      {totalPages > 1 && (
        <Box display="flex" justifyContent="center" mb={3}>
          <Pagination
            count={totalPages}
            page={page}
            onChange={(_, value) => setPage(value)}
          />
        </Box>
      )}
    </>
  );
};

/**
 * Relatórios do grupo, em duas abas: Acompanhamento (ciclos encerrados que
 * ainda aguardam quitação) e Histórico (ciclos já fechados). O estado mora na
 * URL: `?aba=acompanhamento|historico` e `?ciclo=YYYY-MM-DD`, o ciclo aberto
 * no Relatório de Fechamento, que aparece abaixo da lista. Trocar de aba
 * descarta o `ciclo`. Abas e listas levam `no-print`: na impressão só sobra o
 * Fechamento.
 */
const GroupReports: React.FC = () => {
  const { id: groupId } = useParams<{ id: string }>();
  const [searchParams, setSearchParams] = useSearchParams();

  const tab = parseTab(searchParams.get('aba'));
  // `?ciclo=` vazio vale como ausente: o `&&` abaixo não renderiza string vazia.
  const cycleStart = searchParams.get('ciclo');

  const openClosing = (start: string) => setSearchParams({ aba: tab, ciclo: start });

  return (
    <>
      <Tabs
        className="no-print"
        value={tab}
        onChange={(_, value: ReportTab) => setSearchParams({ aba: value })}
        aria-label="Seções de Relatórios"
        sx={{ mb: 2 }}
      >
        <Tab label="Acompanhamento" value="acompanhamento" />
        <Tab label="Histórico" value="historico" />
      </Tabs>

      <Box className="no-print" mb={2}>
        {tab === 'acompanhamento' ? (
          <PendingCyclesList groupId={groupId} onViewClosing={openClosing} />
        ) : (
          <CycleHistory groupId={groupId} selectedStart={cycleStart} onSelect={openClosing} />
        )}
      </Box>

      {cycleStart && <ClosingReportPanel groupId={groupId} cycleStart={cycleStart} />}
    </>
  );
};

export default GroupReports;
