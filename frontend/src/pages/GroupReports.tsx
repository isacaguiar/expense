import React, { useState } from 'react';
import { Box, Typography, CircularProgress, List, ListItemButton, ListItemText, Paper, Pagination } from '@mui/material';
import { useParams } from 'react-router-dom';
import { useGroupCycleHistory } from '../hooks/useGroupCycleHistory';
import { Summary } from '../hooks/useGroupCycle';
import CycleDetailPanel from '../components/CycleDetailPanel';

// new Date('YYYY-MM-DD') interpreta a string como UTC-meia-noite, o que desloca
// a data em 1 dia para trás em fusos negativos (ex.: America/Sao_Paulo) —
// construímos a partir dos componentes locais para evitar isso.
const formatDate = (dateStr: string): string => {
  const [year, month, day] = dateStr.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' });
};

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const GroupReports: React.FC = () => {
  const { id: groupId } = useParams<{ id: string }>();
  const { cycles, loading, error, page, totalPages, setPage } = useGroupCycleHistory(groupId);
  const [selectedCycle, setSelectedCycle] = useState<Summary | null>(null);

  return (
    <>
      <Typography variant="h6" gutterBottom>
        Histórico de ciclos fechados
      </Typography>

      {loading ? (
        <Box display="flex" justifyContent="center" mt={4}>
          <CircularProgress />
        </Box>
      ) : error ? (
        <Typography color="error">{error}</Typography>
      ) : cycles.length === 0 ? (
        <Typography color="text.secondary">Nenhum ciclo fechado ainda.</Typography>
      ) : (
        <>
          <Paper elevation={3} sx={{ mb: 2 }}>
            <List disablePadding>
              {cycles.map(cycle => (
                <ListItemButton
                  key={cycle.cycle.start}
                  divider
                  selected={selectedCycle?.cycle.start === cycle.cycle.start}
                  onClick={() => setSelectedCycle(cycle)}
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

          {selectedCycle && <CycleDetailPanel summary={selectedCycle} />}
        </>
      )}
    </>
  );
};

export default GroupReports;
