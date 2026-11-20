import React, { useState } from 'react';
import { Box, Button, Collapse, Link, Stack, Typography } from '@mui/material';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import type { SettlementProofEntry } from '../hooks/useGroupCycle';
import useProofViewer from '../hooks/useProofViewer';

const KIND_LABEL: Record<SettlementProofEntry['kind'], string> = {
  replaced: 'Substituído',
  withdrawn: 'Retirado',
  complement: 'Justificativa'
};

const STATUS_LABEL: Record<NonNullable<SettlementProofEntry['status']>, string> = {
  sent: 'Enviado',
  awaiting_acceptance: 'Aguardando aceite',
  refused: 'Recusado'
};

const formatWhen = (iso: string | null): string => {
  if (!iso) return '';
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
};

type SettlementProofHistoryProps = {
  entries: SettlementProofEntry[];
};

/**
 * O histórico de comprovantes do acerto (feature 20261114-historico-de-comprovantes-e-justificativa, plan §12,
 * R7): comprovantes substituídos ou retirados e as justificativas com foto de quem paga pelo devedor, da
 * mais nova para a mais antiga (a ordem que o servidor manda). Recolhido por padrão. O servidor só manda estas
 * entradas às partes do acerto e a quem enviou cada uma; sem entradas nada é renderizado. Nota, justificativa e
 * motivo são **texto puro** (nunca HTML nem link) e quebram em qualquer ponto.
 */
const SettlementProofHistory: React.FC<SettlementProofHistoryProps> = ({ entries }) => {
  const [open, setOpen] = useState<boolean>(false);
  // O comprovante abre num diálogo em vez de outra aba (feature 20261120-ver-comprovante-em-modal, TASK-657).
  const { proofLinkProps, proofViewer } = useProofViewer();

  if (entries.length === 0) return null;

  return (
    <Box mt={1}>
      <Button
        size="small"
        color="inherit"
        aria-expanded={open}
        endIcon={<ExpandMoreIcon sx={{ transform: open ? 'rotate(180deg)' : 'none', transition: 'transform 0.2s' }} />}
        onClick={() => setOpen(current => !current)}
        sx={{ color: 'text.secondary' }}
      >
        {`Histórico (${entries.length})`}
      </Button>

      <Collapse in={open} unmountOnExit>
        <Stack component="ul" spacing={1} sx={{ listStyle: 'none', m: 0, mt: 0.5, p: 0 }}>
          {entries.map(entry => {
            const isComplement = entry.kind === 'complement';

            return (
              <Box
                component="li"
                key={entry.id}
                sx={{ borderLeft: 2, borderColor: 'divider', pl: 1.5, overflowWrap: 'anywhere' }}
              >
                <Typography variant="caption" fontWeight={600} display="block">
                  {KIND_LABEL[entry.kind]}
                </Typography>
                <Typography variant="caption" color="text.secondary" display="block">
                  {`${entry.sentBy ? `Enviado por ${entry.sentBy.name}` : 'Enviado por conta removida'}${
                    formatWhen(entry.sentAt ?? entry.archivedAt) ? ` em ${formatWhen(entry.sentAt ?? entry.archivedAt)}` : ''
                  }`}
                </Typography>
                {!isComplement && entry.status && (
                  <Typography variant="caption" color="text.secondary" display="block">
                    {`Estava: ${STATUS_LABEL[entry.status]}`}
                  </Typography>
                )}
                {entry.note && (
                  <Typography variant="caption" display="block" sx={{ overflowWrap: 'anywhere' }}>
                    {`${isComplement ? 'Justificativa' : 'Nota'}: ${entry.note}`}
                  </Typography>
                )}
                {entry.refusalReason && (
                  <Typography variant="caption" color="error" display="block" sx={{ overflowWrap: 'anywhere' }}>
                    {`Motivo da recusa: ${entry.refusalReason}`}
                  </Typography>
                )}
                {entry.proofUrl && (
                  <Link {...proofLinkProps(entry.proofUrl)} variant="caption" underline="hover">
                    Ver comprovante
                  </Link>
                )}
              </Box>
            );
          })}
        </Stack>
      </Collapse>
      {proofViewer}
    </Box>
  );
};

export default SettlementProofHistory;
