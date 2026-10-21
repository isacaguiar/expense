import React from 'react';
import { Box, Button, Card, Chip, Stack, Typography } from '@mui/material';
import PhotoCameraOutlinedIcon from '@mui/icons-material/PhotoCameraOutlined';
import UndoIcon from '@mui/icons-material/Undo';
import type { OverviewItem } from '../types/overview';

const formatDate = (dateStr: string): string => {
  const [year, month, day] = dateStr.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' });
};

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

type MyItemsListProps = {
  items: OverviewItem[];
  /** Despesa com marcar/desfazer em andamento: os botões dela ficam travados. */
  busyExpenseId: number | null;
  onPay: (expenseId: number) => void;
  onUnpay: (expenseId: number) => void;
};

/**
 * "Seus itens" do Painel: as despesas do ciclo em foco de que o usuário
 * participa ou que ele pagou. O devedor vê a parte dele e o credor; o credor vê
 * o que pagou. "Paga/Pendente" é só informação (o que o credor marcou): os
 * totais de "devo" e "a receber" vêm dos acertos líquidos, não daqui.
 *
 * Os botões seguem `can_pay`/`can_unpay` do servidor e nada mais: o cliente não
 * deduz permissão de `role` nem de `paid`
 * (docs/feature/20261010-painel-financeiro-pessoal/plan.md §2 e §3).
 */
const MyItemsList: React.FC<MyItemsListProps> = ({ items, busyExpenseId, onPay, onUnpay }) => {
  if (items.length === 0) {
    return (
      <Typography variant="body2" color="text.secondary" py={1}>
        Nenhum item seu neste ciclo.
      </Typography>
    );
  }

  return (
    <Stack spacing={1}>
      {items.map(item => {
        const busy = busyExpenseId === item.expense_id;

        return (
          <Card key={item.expense_id} variant="outlined" sx={{ p: 1.5 }} data-testid={`my-item-${item.expense_id}`}>
            <Box display="flex" justifyContent="space-between" alignItems="flex-start" gap={1}>
              <Box minWidth={0}>
                <Typography variant="body2" fontWeight={700}>
                  {item.description}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {formatDate(item.date)}
                  {item.installment && (
                    <>
                      {' · '}
                      <span>{`Parcela ${item.installment.number}/${item.installment.total}`}</span>
                    </>
                  )}
                </Typography>
              </Box>
              <Chip
                label={item.paid ? 'Paga' : 'Pendente'}
                color={item.paid ? 'success' : 'warning'}
                size="small"
                sx={{ flexShrink: 0 }}
              />
            </Box>

            {item.role === 'creditor' ? (
              <Typography variant="body2" mt={0.5}>
                Você pagou R$ {formatMoney(item.value)}
              </Typography>
            ) : (
              <>
                <Typography variant="body2" fontWeight={600} mt={0.5}>
                  Sua parte R$ {formatMoney(item.my_share)}
                </Typography>
                <Typography variant="caption" color="text.secondary" component="p">
                  Total da despesa R$ {formatMoney(item.value)}
                </Typography>
                <Typography variant="caption" color="text.secondary" component="p">
                  Credor: {item.creditor.name ?? '-'}
                </Typography>
              </>
            )}

            {(item.can_pay || item.can_unpay) && (
              <Box display="flex" justifyContent="flex-end" mt={1}>
                {item.can_pay && (
                  <Button
                    size="small"
                    variant="contained"
                    startIcon={<PhotoCameraOutlinedIcon />}
                    disabled={busy}
                    onClick={() => onPay(item.expense_id)}
                  >
                    Marcar como paga
                  </Button>
                )}
                {item.can_unpay && (
                  <Button
                    size="small"
                    variant="outlined"
                    startIcon={<UndoIcon />}
                    disabled={busy}
                    onClick={() => onUnpay(item.expense_id)}
                  >
                    Desfazer
                  </Button>
                )}
              </Box>
            )}
          </Card>
        );
      })}
    </Stack>
  );
};

export default MyItemsList;
