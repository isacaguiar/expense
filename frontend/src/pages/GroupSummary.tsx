import React from 'react';
import { Box, Typography, CircularProgress, IconButton } from '@mui/material';
import { useParams } from 'react-router-dom';
import ArrowBackIosNewIcon from '@mui/icons-material/ArrowBackIosNew';
import ArrowForwardIosIcon from '@mui/icons-material/ArrowForwardIos';
import { useGroupCycle } from '../hooks/useGroupCycle';
import CycleDetailPanel from '../components/CycleDetailPanel';
import CycleClosingAlert from '../components/CycleClosingAlert';
import { formatShortDate } from '../utils/dates';

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
              {formatShortDate(summary.cycle.start)} – {formatShortDate(summary.cycle.end)}
            </Typography>
            <IconButton
              onClick={goToNextCycle}
              aria-label="Próximo ciclo"
            >
              <ArrowForwardIosIcon />
            </IconButton>
          </Box>

          <CycleClosingAlert summary={summary} />

          <CycleDetailPanel summary={summary} />
        </>
      ) : null}
    </>
  );
};

export default GroupSummary;
