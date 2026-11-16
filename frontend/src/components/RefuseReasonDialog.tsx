import React, { useEffect, useState } from 'react';
import { Button, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle, TextField } from '@mui/material';

export const REFUSE_REASON_MAX = 500;

type RefuseReasonDialogProps = {
  open: boolean;
  busy: boolean;
  onCancel: () => void;
  // O motivo aparado, ou `undefined` quando vazio (o servidor grava nulo sem ele).
  onConfirm: (reason: string | undefined) => void;
};

/**
 * Recusar o comprovante pede um motivo **opcional** (feature 20261114-historico-de-comprovantes-e-
 * justificativa, plan §12, D4-A). O motivo só chega a quem é parte do acerto e a quem enviou o comprovante;
 * não vai para o sino. O texto é sempre exibido como texto puro, nunca como HTML ou link.
 */
const RefuseReasonDialog: React.FC<RefuseReasonDialogProps> = ({ open, busy, onCancel, onConfirm }) => {
  const [reason, setReason] = useState<string>('');

  // Cada abertura começa vazia.
  useEffect(() => {
    if (open) setReason('');
  }, [open]);

  return (
    <Dialog open={open} onClose={busy ? undefined : onCancel} fullWidth maxWidth="xs">
      <DialogTitle>Recusar comprovante?</DialogTitle>
      <DialogContent>
        <DialogContentText sx={{ mb: 2 }}>
          O acerto volta a "a pagar" e quem enviou poderá corrigir. Se quiser, diga o motivo.
        </DialogContentText>
        <TextField
          label="Motivo (opcional)"
          value={reason}
          onChange={event => setReason(event.target.value)}
          multiline
          minRows={2}
          fullWidth
          disabled={busy}
          inputProps={{ maxLength: REFUSE_REASON_MAX }}
          helperText={`${reason.length}/${REFUSE_REASON_MAX}`}
        />
      </DialogContent>
      <DialogActions>
        <Button onClick={onCancel} disabled={busy}>
          Cancelar
        </Button>
        <Button color="error" disabled={busy} onClick={() => onConfirm(reason.trim() === '' ? undefined : reason.trim())}>
          Recusar
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default RefuseReasonDialog;
