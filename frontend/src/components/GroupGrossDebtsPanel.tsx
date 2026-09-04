import React, { useState } from 'react';
import { Box, Typography, CircularProgress, IconButton, List, ListItem, ListItemText, Chip, Tooltip, Button } from '@mui/material';
import ArrowBackIosNewIcon from '@mui/icons-material/ArrowBackIosNew';
import ArrowForwardIosIcon from '@mui/icons-material/ArrowForwardIos';
import QrCode2OutlinedIcon from '@mui/icons-material/QrCode2Outlined';
import CheckCircleOutlineIcon from '@mui/icons-material/CheckCircleOutline';
import { useGroupGrossDebts, GrossCreditor, GrossDebtor } from '../hooks/useGroupGrossDebts';
import PixPaymentDialog from './PixPaymentDialog';

// new Date('YYYY-MM-DD') interpreta a string como UTC-meia-noite, o que desloca
// a data em 1 dia para trás em fusos negativos (ex.: America/Sao_Paulo) —
// construímos a partir dos componentes locais para evitar isso.
const formatDate = (dateStr: string): string => {
  const [year, month, day] = dateStr.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' });
};

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

type GroupGrossDebtsPanelProps = {
  groupId: string;
};

/**
 * Árvore Credor→devedores (valores brutos) de um grupo, com navegação de
 * competência própria — usada pela linha expansível do Dashboard
 * (`docs/feature/concluidas/202608/20260827-dashboard-resumo-credor-devedores/`). "Informar
 * pagamento" é só um estado visual local: nunca chama a API, marcar como
 * pago de fato continua exclusivo do credor dentro do grupo.
 */
const GroupGrossDebtsPanel: React.FC<GroupGrossDebtsPanelProps> = ({ groupId }) => {
  const [cyclesAgo, setCyclesAgo] = useState<number>(0);
  const { data, loading, error } = useGroupGrossDebts(groupId, cyclesAgo);

  const [informedKeys, setInformedKeys] = useState<Set<string>>(new Set());
  const [pixTarget, setPixTarget] = useState<{ creditor: GrossCreditor['creditor']; debtor: GrossDebtor } | null>(null);

  const goToPreviousCycle = () => {
    setCyclesAgo(prev => prev + 1);
    setInformedKeys(new Set());
  };

  const goToNextCycle = () => {
    setCyclesAgo(prev => prev - 1);
    setInformedKeys(new Set());
  };

  const markInformed = (creditorId: number, debtorId: number) => {
    setInformedKeys(prev => new Set(prev).add(`${creditorId}-${debtorId}`));
  };

  return (
    <Box sx={{ p: 2 }}>
      {loading ? (
        <Box display="flex" justifyContent="center" py={2}>
          <CircularProgress size={24} />
        </Box>
      ) : error ? (
        <Typography color="error">{error}</Typography>
      ) : data ? (
        <>
          <Box display="flex" alignItems="center" justifyContent="center" gap={1} mb={2}>
            <IconButton onClick={goToPreviousCycle} aria-label="Ciclo anterior" size="small">
              <ArrowBackIosNewIcon fontSize="small" />
            </IconButton>
            <Typography variant="body2" fontWeight={600} textTransform="capitalize">
              {formatDate(data.cycle.start)} – {formatDate(data.cycle.end)}
            </Typography>
            <IconButton onClick={goToNextCycle} aria-label="Próximo ciclo" size="small">
              <ArrowForwardIosIcon fontSize="small" />
            </IconButton>
          </Box>

          {data.creditors.length === 0 ? (
            <Typography color="text.secondary" textAlign="center">
              Nenhuma pendência neste ciclo.
            </Typography>
          ) : (
            data.creditors.map(({ creditor, debtors }) => (
              <Box key={creditor.id} mb={2}>
                <Typography variant="subtitle2" fontWeight={700}>
                  {creditor.name}
                </Typography>
                <List disablePadding dense>
                  {debtors.map(debtor => {
                    const key = `${creditor.id}-${debtor.id}`;
                    const informed = informedKeys.has(key);

                    return (
                      <ListItem key={debtor.id} sx={{ pl: 3 }}>
                        <ListItemText primary={debtor.name} secondary={`R$ ${formatMoney(debtor.amount)}`} />

                        {informed ? (
                          <Chip size="small" color="success" icon={<CheckCircleOutlineIcon fontSize="small" />} label="Informado" />
                        ) : (
                          <Box display="flex" gap={1}>
                            <Tooltip title={creditor.pix ? 'Pagar via Pix' : `${creditor.name} ainda não cadastrou uma chave Pix.`}>
                              <span>
                                <IconButton
                                  size="small"
                                  disabled={!creditor.pix}
                                  aria-label={`Pagar ${creditor.name} via Pix (dívida de ${debtor.name})`}
                                  onClick={() => setPixTarget({ creditor, debtor })}
                                >
                                  <QrCode2OutlinedIcon fontSize="small" />
                                </IconButton>
                              </span>
                            </Tooltip>
                            <Button
                              size="small"
                              aria-label={`Informar pagamento de ${debtor.name} para ${creditor.name}`}
                              onClick={() => markInformed(creditor.id, debtor.id)}
                            >
                              Informar pagamento
                            </Button>
                          </Box>
                        )}
                      </ListItem>
                    );
                  })}
                </List>
              </Box>
            ))
          )}

          <PixPaymentDialog
            open={pixTarget !== null}
            onClose={() => setPixTarget(null)}
            targetEmail={pixTarget?.creditor.email ?? ''}
            targetName={pixTarget?.creditor.name ?? ''}
            amount={pixTarget?.debtor.amount ?? 0}
          />
        </>
      ) : null}
    </Box>
  );
};

export default GroupGrossDebtsPanel;
