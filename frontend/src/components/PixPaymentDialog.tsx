import React, { useEffect, useState } from 'react';
import axios from 'axios';
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogContent,
  DialogTitle,
  IconButton,
  TextField,
  Typography
} from '@mui/material';
import CloseIcon from '@mui/icons-material/Close';
import ContentCopyOutlinedIcon from '@mui/icons-material/ContentCopyOutlined';
import CheckIcon from '@mui/icons-material/Check';
import { API_BASE_URL } from '../config';

type PixResponse = { qrcode: string; copiacola: string };

interface PixPaymentDialogProps {
  open: boolean;
  onClose: () => void;
  targetEmail: string;
  targetName: string;
  amount: number;
}

const formatMoney = (value: number): string =>
  value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * QR Code + copia-e-cola pra pagar `targetName` via Pix — chama a API já
 * existente `GET /pix/generate` (docs/feature/concluidas/202608/20260825-pagamentos-grid-pix/plan.md §3).
 * Não confirma pagamento nem altera `Quota.paid` — é só um jeito de o
 * usuário efetuar o Pix fora do app; marcar como pago continua manual.
 */
const PixPaymentDialog: React.FC<PixPaymentDialogProps> = ({ open, onClose, targetEmail, targetName, amount }) => {
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [pix, setPix] = useState<PixResponse | null>(null);
  const [copied, setCopied] = useState<boolean>(false);

  useEffect(() => {
    if (!open) return;

    setLoading(true);
    setError(null);
    setPix(null);
    setCopied(false);

    const token = localStorage.getItem('accessToken');

    axios
      .get<PixResponse>(`${API_BASE_URL}/api/pix/generate`, {
        headers: { Authorization: token ? `Bearer ${token}` : '' },
        params: { email: targetEmail, valor: amount.toFixed(2) }
      })
      .then(res => setPix(res.data))
      .catch(err => {
        console.error('Erro ao gerar Pix:', err);
        setError(err.response?.data?.message ?? 'Não foi possível gerar o Pix agora. Tente novamente.');
      })
      .finally(() => setLoading(false));
  }, [open, targetEmail, amount]);

  const handleCopy = () => {
    if (!pix) return;
    navigator.clipboard
      .writeText(pix.copiacola)
      .then(() => setCopied(true))
      .catch(err => console.error('Erro ao copiar o código Pix:', err));
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="xs">
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 1 }}>
        <Box>
          <Typography variant="h6" fontWeight={700} component="span">
            Pagar {targetName}
          </Typography>
          <Typography variant="body2" color="text.secondary">
            R$ {formatMoney(amount)} via Pix
          </Typography>
        </Box>
        <IconButton onClick={onClose} aria-label="Fechar" size="small">
          <CloseIcon fontSize="small" />
        </IconButton>
      </DialogTitle>

      <DialogContent sx={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 2, pb: 3 }}>
        {loading ? (
          <Box display="flex" justifyContent="center" py={4}>
            <CircularProgress />
          </Box>
        ) : error ? (
          <Alert severity="error" sx={{ width: '100%' }}>
            {error}
          </Alert>
        ) : pix ? (
          <>
            <Box
              component="img"
              src={pix.qrcode}
              alt={`QR Code Pix para pagar ${targetName}`}
              sx={{ width: 220, height: 220, borderRadius: 2, border: '1px solid', borderColor: 'divider' }}
            />

            <Typography variant="body2" color="text.secondary" textAlign="center">
              Escaneie com o app do seu banco ou copie o código abaixo.
            </Typography>

            <TextField
              value={pix.copiacola}
              fullWidth
              size="small"
              multiline
              maxRows={3}
              InputProps={{ readOnly: true, sx: { fontFamily: 'monospace', fontSize: '0.75rem' } }}
            />

            <Button
              variant="contained"
              fullWidth
              startIcon={copied ? <CheckIcon /> : <ContentCopyOutlinedIcon />}
              color={copied ? 'success' : 'primary'}
              onClick={handleCopy}
            >
              {copied ? 'Copiado!' : 'Copiar código Pix'}
            </Button>
          </>
        ) : null}
      </DialogContent>
    </Dialog>
  );
};

export default PixPaymentDialog;
