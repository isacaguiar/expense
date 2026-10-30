import React from 'react';
import { Box, Card, Chip, Stack, Typography } from '@mui/material';
import CheckCircleOutlineIcon from '@mui/icons-material/CheckCircleOutline';
import HourglassEmptyOutlinedIcon from '@mui/icons-material/HourglassEmptyOutlined';
import UserAvatar from './UserAvatar';
import type { OverviewReceivable } from '../types/overview';

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

type ReceivableListProps = {
  receivables: OverviewReceivable[];
};

/**
 * "Você recebe" do Painel: quem deve ao usuário no ciclo em foco, quanto (o
 * acerto líquido par a par) e se já mandou o comprovante. Só acompanhamento:
 * quem age é o devedor, do lado dele. O `SettlementList` de Resumo/Relatórios
 * não mostra confirmação e fica como está
 * (docs/feature/concluidas/202610/20261010-painel-financeiro-pessoal/plan.md §2).
 */
const ReceivableList: React.FC<ReceivableListProps> = ({ receivables }) => {
  if (receivables.length === 0) {
    return (
      <Typography variant="body2" color="text.secondary" py={1}>
        Ninguém te deve neste ciclo.
      </Typography>
    );
  }

  return (
    <Stack spacing={1}>
      {receivables.map(receivable => {
        const confirmed = receivable.confirmed_at !== null;

        return (
          <Card
            key={receivable.from_user.id}
            variant="outlined"
            sx={{ p: 1.5 }}
            data-testid={`receivable-${receivable.from_user.id}`}
          >
            <Box display="flex" alignItems="center" gap={1.5}>
              <UserAvatar name={receivable.from_user.name} avatarUrl={receivable.from_user.avatar_url} size={32} />
              <Typography variant="body2" fontWeight={600} flexGrow={1} minWidth={0} noWrap>
                {receivable.from_user.name}
              </Typography>
              <Typography variant="body2" fontWeight={700} color="success.main" sx={{ flexShrink: 0 }}>
                R$ {formatMoney(receivable.amount)}
              </Typography>
            </Box>

            <Box display="flex" justifyContent="flex-end" mt={1}>
              {confirmed ? (
                <Chip
                  icon={<CheckCircleOutlineIcon fontSize="small" />}
                  label="Comprovante enviado"
                  color="success"
                  size="small"
                  variant="outlined"
                  {...(receivable.confirmed_proof_url
                    ? {
                        component: 'a',
                        href: receivable.confirmed_proof_url,
                        target: '_blank',
                        rel: 'noreferrer',
                        clickable: true
                      }
                    : {})}
                />
              ) : (
                <Chip
                  icon={<HourglassEmptyOutlinedIcon fontSize="small" />}
                  label="Aguardando pagamento"
                  color="warning"
                  size="small"
                  variant="outlined"
                />
              )}
            </Box>
          </Card>
        );
      })}
    </Stack>
  );
};

export default ReceivableList;
