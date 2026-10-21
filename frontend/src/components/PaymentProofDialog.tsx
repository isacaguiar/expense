import React, { useEffect, useState } from 'react';
import {
  Alert,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle,
  TextField
} from '@mui/material';
import PhotoCameraOutlinedIcon from '@mui/icons-material/PhotoCameraOutlined';

/** Limite da descrição; o servidor valida o mesmo valor (`max:500`). */
export const NOTE_MAX_LENGTH = 500;

type CanConfirmInput = {
  requirePhoto: boolean;
  hasFile: boolean;
  note: string;
  busy: boolean;
};

/**
 * Regra de habilitação do botão Confirmar (docs/feature/20261021-comprovante-ao-marcar-paga,
 * specify R1/R2): com a foto opcional, basta foto OU descrição não vazia; com
 * a foto obrigatória (acerto do devedor), só a foto conta e o texto é um
 * complemento. Nunca confirma enquanto `busy`.
 */
export const canConfirmPayment = ({ requirePhoto, hasFile, note, busy }: CanConfirmInput): boolean => {
  if (busy) return false;
  if (requirePhoto) return hasFile;
  return hasFile || note.trim() !== '';
};

export type PaymentProofValue = { file: File | null; note: string };

interface PaymentProofDialogProps {
  open: boolean;
  title: string;
  /** Texto de ajuda acima dos campos. */
  description: string;
  /** Texto do botão de foto enquanto nenhuma foi escolhida. */
  photoLabel: string;
  /** `true`: a foto é obrigatória e a descrição é só complemento. */
  requirePhoto: boolean;
  busy?: boolean;
  error?: string | null;
  onClose: () => void;
  onConfirm: (value: PaymentProofValue) => void;
}

/**
 * Popup único de comprovante de pagamento: seletor de foto + descrição em
 * texto livre. Usado por Despesas (✓ "Marcar como paga"), por Pagamentos
 * (credor) e pelo acerto do devedor. Quem usa decide se fecha o popup ao
 * confirmar (credor) ou só depois do sucesso (devedor, com `busy`/`error`).
 * A descrição vira texto puro na tela — nunca HTML.
 */
const PaymentProofDialog: React.FC<PaymentProofDialogProps> = ({
  open,
  title,
  description,
  photoLabel,
  requirePhoto,
  busy = false,
  error = null,
  onClose,
  onConfirm
}) => {
  const [file, setFile] = useState<File | null>(null);
  const [note, setNote] = useState<string>('');

  // Cada abertura começa em branco.
  useEffect(() => {
    if (open) {
      setFile(null);
      setNote('');
    }
  }, [open]);

  const canConfirm = canConfirmPayment({ requirePhoto, hasFile: file !== null, note, busy });

  const handleConfirm = () => {
    if (!canConfirm) return;
    onConfirm({ file, note: note.trim() });
  };

  return (
    <Dialog open={open} onClose={busy ? undefined : onClose} fullWidth maxWidth="xs">
      <DialogTitle>{title}</DialogTitle>
      <DialogContent>
        <DialogContentText mb={2}>{description}</DialogContentText>

        <Button variant="outlined" component="label" fullWidth startIcon={<PhotoCameraOutlinedIcon />}>
          {file ? file.name : photoLabel}
          <input
            type="file"
            accept="image/*"
            capture="environment"
            hidden
            onChange={e => setFile(e.target.files?.[0] ?? null)}
          />
        </Button>

        <TextField
          label={requirePhoto ? 'Descrição (opcional)' : 'Descrição'}
          value={note}
          onChange={e => setNote(e.target.value)}
          multiline
          minRows={2}
          maxRows={5}
          fullWidth
          margin="normal"
          inputProps={{ maxLength: NOTE_MAX_LENGTH }}
          helperText={`${note.length}/${NOTE_MAX_LENGTH}`}
        />

        {error && (
          <Alert severity="error" sx={{ mt: 1 }}>
            {error}
          </Alert>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={busy}>
          Cancelar
        </Button>
        <Button variant="contained" disabled={!canConfirm} onClick={handleConfirm}>
          Confirmar
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default PaymentProofDialog;
