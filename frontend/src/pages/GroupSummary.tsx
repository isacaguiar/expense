import React from 'react';
import { Box, Typography, CircularProgress, IconButton } from '@mui/material';
import { useParams } from 'react-router-dom';
import ArrowBackIosNewIcon from '@mui/icons-material/ArrowBackIosNew';
import ArrowForwardIosIcon from '@mui/icons-material/ArrowForwardIos';
import { useGroupCycle } from '../hooks/useGroupCycle';
import CycleDetailPanel from '../components/CycleDetailPanel';

// new Date('YYYY-MM-DD') interpreta a string como UTC-meia-noite, o que desloca
// a data em 1 dia para trás em fusos negativos (ex.: America/Sao_Paulo) —
// construímos a partir dos componentes locais para evitar isso.
const formatDate = (dateStr: string): string => {
  const [year, month, day] = dateStr.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' });
};

const GroupSummary: React.FC = () => {
  const { id: groupId } = useParams<{ id: string }>();
  const { summary, loading, error, goToPreviousCycle, goToNextCycle } = useGroupCycle(groupId);

  return (
    <>
      {loading ? (
        <Box display="flex" justifyContent="center" mt={4}>
          <CircularProgress />
        </Box>
      ) : error ? (
        <Typography color="error">{error}</Typography>
      ) : summary ? (
        <>
          <Box
            display="flex"
            alignItems="center"
            justifyContent="center"
            mb={3}
            gap={1}
          >
            <IconButton
              onClick={goToPreviousCycle}
              aria-label="Ciclo anterior"
            >
              <ArrowBackIosNewIcon />
            </IconButton>
            <Typography variant="h6" textTransform="capitalize">
              {formatDate(summary.cycle.start)} – {formatDate(summary.cycle.end)}
            </Typography>
            <IconButton
              onClick={goToNextCycle}
              aria-label="Próximo ciclo"
            >
              <ArrowForwardIosIcon />
            </IconButton>
          </Box>

          <CycleDetailPanel summary={summary} />
        </>
      ) : null}
    </>
  );
};

export default GroupSummary;
